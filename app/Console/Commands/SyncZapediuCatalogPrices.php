<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Console\Command;
use JsonException;

/**
 * Sincroniza só price/promotional_price dos produtos a partir do catálogo Zapediu (o mesmo
 * JSON que zapediu:import-catalog usa, agora com preços reais em vez dos fictícios gerados
 * por CATEGORY_BASE_PRICES). Não toca em nome, imagem, descrição, company_id ou qualquer
 * outro campo — evita reintroduzir o company_id=null que o import original grava.
 */
class SyncZapediuCatalogPrices extends Command
{
    protected $signature = 'zapediu:sync-catalog-prices
        {source=- : Caminho do JSON ou - para ler da entrada padrão}
        {--dry-run : Mostra o que seria atualizado sem gravar no banco}';

    protected $description = 'Atualiza price/promotional_price dos produtos existentes a partir do catálogo Zapediu.';

    public function handle(): int
    {
        try {
            $catalog = $this->readCatalog((string) $this->argument('source'));
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $stores = $catalog['stores'] ?? null;
        if (! is_array($stores) || $stores === []) {
            $this->error('O catálogo não contém lojas válidas.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $missing = [];

        foreach ($stores as $storeData) {
            if (! is_array($storeData) || empty($storeData['slug'])) {
                continue;
            }

            $store = Store::withoutGlobalScopes()->where('slug', $storeData['slug'])->first();
            if (! $store) {
                $missing[] = "loja não encontrada: {$storeData['slug']}";
                continue;
            }

            foreach (($storeData['categories'] ?? []) as $categoryData) {
                if (! is_array($categoryData) || empty($categoryData['name'])) {
                    continue;
                }

                $category = Category::withoutGlobalScopes()
                    ->where('store_id', $store->id)
                    ->where('name', $categoryData['name'])
                    ->first();

                if (! $category) {
                    $missing[] = "{$store->slug}: categoria não encontrada: {$categoryData['name']}";
                    continue;
                }

                foreach (($categoryData['products'] ?? []) as $productData) {
                    if (! is_array($productData) || empty($productData['name'])) {
                        continue;
                    }

                    $product = Product::withoutGlobalScopes()
                        ->where('store_id', $store->id)
                        ->where('category_id', $category->id)
                        ->where('name', $productData['name'])
                        ->first();

                    if (! $product) {
                        $missing[] = "{$store->slug} / {$category->name}: produto não encontrado: {$productData['name']}";
                        continue;
                    }

                    $resolved = $this->resolvePrices($productData);
                    if ($resolved === null) {
                        $this->line("  (preservado, sem preço próprio no catálogo) {$store->slug} / {$category->name} / {$product->name}");
                        continue;
                    }
                    [$price, $promotionalPrice] = $resolved;

                    if ($dryRun) {
                        $this->line(sprintf(
                            '%s / %s / %s: price=%s%s',
                            $store->slug,
                            $category->name,
                            $product->name,
                            $price,
                            $promotionalPrice !== null ? " promo={$promotionalPrice}" : '',
                        ));
                    } else {
                        $product->forceFill([
                            'price' => $price,
                            'promotional_price' => $promotionalPrice,
                        ])->save();
                    }

                    $updated++;
                }
            }
        }

        $this->info(sprintf('%s %d produtos.', $dryRun ? 'Seriam atualizados' : 'Atualizados', $updated));

        if ($missing !== []) {
            $this->warn(sprintf('%d itens do catálogo não bateram com o banco:', count($missing)));
            foreach ($missing as $line) {
                $this->line("  - {$line}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Retorna null quando o produto não tem preço próprio no catálogo (ex.: sabor de gelato
     * com priceIncludedInContainer=true, cujo preço vem do "monte seu pote") — nesse caso o
     * preço fictício já cadastrado no banco é preservado em vez de zerado.
     *
     * @param array<string, mixed> $data @return array{0: float, 1: float|null}|null
     */
    private function resolvePrices(array $data): ?array
    {
        if (isset($data['pricesBySize']) && is_array($data['pricesBySize']) && $data['pricesBySize'] !== []) {
            $sizes = $data['pricesBySize'];
            $price = (float) ($sizes['medium'] ?? $sizes['large'] ?? $sizes['small'] ?? reset($sizes));

            return [$price, null];
        }

        if (! isset($data['price'])) {
            return null;
        }

        $price = (float) $data['price'];
        $promotional = (bool) ($data['promotional'] ?? false);

        if ($promotional && isset($data['originalPrice']) && (float) $data['originalPrice'] > $price) {
            return [(float) $data['originalPrice'], $price];
        }

        return [$price, null];
    }

    /** @return array<string, mixed> */
    private function readCatalog(string $source): array
    {
        $json = $source === '-' ? stream_get_contents(STDIN) : @file_get_contents($source);
        if (! is_string($json) || trim($json) === '') {
            throw new \RuntimeException('Não foi possível ler o JSON informado.');
        }

        try {
            $catalog = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('O arquivo não contém um JSON válido: '.$exception->getMessage());
        }

        if (! is_array($catalog)) {
            throw new \RuntimeException('O catálogo precisa ser um objeto JSON.');
        }

        return $catalog;
    }
}
