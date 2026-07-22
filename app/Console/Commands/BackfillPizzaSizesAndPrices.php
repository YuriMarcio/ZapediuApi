<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\CategoryPizzaSizePrice;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Store;
use App\Models\StorePizzaSize;
use App\Services\Pizzaria\PizzaOptionalFlowProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Migra lojas pizzaria já existentes (size_template JSON + product_variations soltas) pra
 * o motor avançado novo (store_pizza_sizes + category_pizza_size_prices + fluxos de
 * bordas/ingredientes/molhos), sem mudar nenhum preço já efetivo pro cliente. Idempotente —
 * seguro rodar de novo (usa firstOrCreate/updateOrCreate em tudo).
 */
class BackfillPizzaSizesAndPrices extends Command
{
    protected $signature = 'pizzaria:backfill-sizes-and-prices {--dry-run : Só mostra o que seria feito, sem gravar nada}';

    protected $description = 'Migra lojas pizzaria existentes pro motor avançado de configuração (tamanhos, preço por categoria, fluxos de opcionais)';

    public function handle(PizzaOptionalFlowProvisioner $provisioner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stores = Store::query()->where('business_type', 'pizzaria')->get();

        $this->info(($dryRun ? '[DRY-RUN] ' : '').'Encontradas '.$stores->count().' loja(s) pizzaria.');

        $totalSizesCreated = 0;
        $totalVariationsMatched = 0;
        $totalVariationsOrphaned = 0;
        $totalCategoriesTyped = 0;
        $totalPriceRowsCreated = 0;

        foreach ($stores as $store) {
            $this->line("\n— Loja #{$store->id} ({$store->name}) —");

            $sizes = $this->ensureSizes($store, $dryRun);
            $totalSizesCreated += $sizes['created'];
            $sizeByName = $sizes['sizes'];

            [$matched, $orphaned] = $this->backfillVariations($store, $sizeByName, $dryRun);
            $totalVariationsMatched += $matched;
            $totalVariationsOrphaned += $orphaned;

            $totalCategoriesTyped += $this->backfillCategoryTypes($store, $dryRun);
            $totalPriceRowsCreated += $this->ensureCategoryPriceRows($store, $dryRun);

            if (! $dryRun) {
                $provisioner->ensureFlowsForStore($store);
                $this->info('  Fluxos de bordas/ingredientes/molhos garantidos.');
            } else {
                $this->line('  [DRY-RUN] Fluxos de bordas/ingredientes/molhos seriam garantidos.');
            }
        }

        $this->newLine();
        $this->info("Resumo: {$totalSizesCreated} tamanho(s) criado(s), {$totalVariationsMatched} variação(ões) casada(s) por nome, "
            ."{$totalVariationsOrphaned} variação(ões) órfã(s) (tamanho novo criado pra não perder o dado), "
            ."{$totalCategoriesTyped} categoria(s) marcada(s) como pizza, {$totalPriceRowsCreated} linha(s) de preço-por-categoria criada(s).");

        if ($dryRun) {
            $this->comment('Nenhuma alteração foi persistida (--dry-run). Rode sem essa flag pra aplicar de verdade.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{created: int, sizes: \Illuminate\Support\Collection<string, StorePizzaSize>}
     */
    private function ensureSizes(Store $store, bool $dryRun): array
    {
        $existing = $store->pizzaSizes()->get()->keyBy(fn (StorePizzaSize $s) => mb_strtolower(trim($s->name)));

        if ($existing->isNotEmpty()) {
            $this->line('  Já tem '.$existing->count().' tamanho(s) cadastrado(s) — mantendo como estão.');

            return ['created' => 0, 'sizes' => $existing];
        }

        $template = $store->sizeTemplate();
        $created = 0;
        $sizes = collect();

        foreach (array_values($template) as $index => $entry) {
            $name = (string) ($entry['label'] ?? $entry['key'] ?? 'Tamanho '.($index + 1));
            $key = mb_strtolower(trim($name));

            if ($dryRun) {
                $this->line("  [DRY-RUN] Criaria tamanho \"{$name}\" (max_flavors={$store->max_flavors}).");
                $created++;

                continue;
            }

            $size = StorePizzaSize::query()->firstOrCreate(
                ['store_id' => $store->id, 'name' => $name],
                ['slice_count' => null, 'max_flavors' => max(1, (int) $store->max_flavors), 'is_active' => true, 'position' => $index + 1],
            );
            $sizes->put($key, $size);
            $created++;
        }

        if (! $dryRun) {
            $this->info("  {$created} tamanho(s) criado(s) a partir do template atual.");
        }

        return ['created' => $created, 'sizes' => $sizes];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, StorePizzaSize>  $sizeByName
     * @return array{0: int, 1: int} [matched, orphaned]
     */
    private function backfillVariations(Store $store, $sizeByName, bool $dryRun): array
    {
        $matched = 0;
        $orphaned = 0;

        $products = Product::query()->where('store_id', $store->id)->where('has_variations', true)->with('variations')->get();

        foreach ($products as $product) {
            foreach ($product->variations as $variation) {
                if ($variation->store_pizza_size_id !== null) {
                    continue; // já migrada (execução anterior)
                }

                $key = mb_strtolower(trim((string) $variation->name));
                $size = $sizeByName->get($key);

                if ($size === null) {
                    $orphaned++;

                    if ($dryRun) {
                        $this->line("  [DRY-RUN] Variação órfã \"{$variation->name}\" (produto #{$product->id}) — criaria tamanho novo.");

                        continue;
                    }

                    $size = StorePizzaSize::query()->firstOrCreate(
                        ['store_id' => $store->id, 'name' => $variation->name],
                        ['max_flavors' => max(1, (int) $store->max_flavors), 'is_active' => true, 'position' => $sizeByName->count() + 1],
                    );
                    $sizeByName->put($key, $size);
                }

                $matched++;
                $effectivePrice = (float) $product->price + (float) $variation->additional_price;

                if ($dryRun) {
                    $this->line("  [DRY-RUN] Variação \"{$variation->name}\" (produto #{$product->id}) -> tamanho #{$size->id}, override_price={$effectivePrice}.");

                    continue;
                }

                $variation->store_pizza_size_id = $size->id;
                $variation->price_mode = 'override';
                $variation->override_price = $effectivePrice;
                $variation->save();
            }
        }

        return [$matched, $orphaned];
    }

    private function backfillCategoryTypes(Store $store, bool $dryRun): int
    {
        $categories = Category::query()
            ->where('company_id', $store->company_id)
            ->where('category_type', 'standard')
            ->whereDoesntHave('pizzaSizePrices')
            ->get();

        if ($dryRun) {
            if ($categories->isNotEmpty()) {
                $this->line('  [DRY-RUN] Marcaria '.$categories->count().' categoria(s) como "pizza": '.$categories->pluck('name')->implode(', '));
            }

            return $categories->count();
        }

        foreach ($categories as $category) {
            $category->category_type = 'pizza';
            $category->save();
        }

        if ($categories->isNotEmpty()) {
            $this->info('  '.$categories->count().' categoria(s) marcada(s) como "pizza" (revise depois se alguma for bebida/combo).');
        }

        return $categories->count();
    }

    private function ensureCategoryPriceRows(Store $store, bool $dryRun): int
    {
        $sizes = $store->pizzaSizes()->where('is_active', true)->get();
        $categories = Category::query()->where('company_id', $store->company_id)->where('category_type', 'pizza')->get();

        $created = 0;

        foreach ($categories as $category) {
            foreach ($sizes as $size) {
                $exists = CategoryPizzaSizePrice::query()
                    ->where('category_id', $category->id)
                    ->where('store_pizza_size_id', $size->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $created++;

                if ($dryRun) {
                    continue;
                }

                CategoryPizzaSizePrice::query()->create([
                    'category_id' => $category->id,
                    'store_pizza_size_id' => $size->id,
                    'price' => null,
                ]);
            }
        }

        return $created;
    }
}
