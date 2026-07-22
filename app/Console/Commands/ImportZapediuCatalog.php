<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class ImportZapediuCatalog extends Command
{
    protected $signature = 'zapediu:import-catalog
        {source=- : Caminho do JSON ou - para ler da entrada padrão}
        {--dry-run : Valida e exibe o resumo sem gravar no banco}';

    protected $description = 'Importa lojas, categorias e produtos fictícios do catálogo Zapediu.';

    /** @var array<string, string> */
    private const MARKETPLACE_CATEGORIES = [
        'Pizzaria' => 'cat_pizza',
        'Hamburgueria' => 'cat_lanches',
        'Sushi e comida japonesa' => 'cat_japonesa',
        'Açaí e sobremesas' => 'cat_acai',
        'Marmitaria e comida caseira' => 'cat_refeicao',
        'Pastelaria' => 'cat_pastel',
        'Cafeteria' => 'cat_cafeteria',
        'Gelateria e sobremesas' => 'cat_gelateria',
        'Alimentação saudável e refeições fitness' => 'cat_saudavel',
        'Comida mexicana' => 'cat_mexicana',
    ];

    /** @var array<string, string> */
    private const CATEGORY_IMAGES = [
        'pizza' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=1200&q=80',
        'lanche' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=1200&q=80',
        'sushi' => 'https://images.unsplash.com/photo-1579871494447-9811cf80d66c?auto=format&fit=crop&w=1200&q=80',
        'acai' => 'https://images.unsplash.com/photo-1490474418585-ba9bad8fd0ea?auto=format&fit=crop&w=1200&q=80',
        'marmita' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=1200&q=80',
        'pastel' => 'https://images.unsplash.com/photo-1626200419199-391ae4be7a41?auto=format&fit=crop&w=1200&q=80',
        'cafe' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=80',
        'gelato' => 'https://images.unsplash.com/photo-1563805042-7684c019e1cb?auto=format&fit=crop&w=1200&q=80',
        'fit' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1200&q=80',
        'mexicana' => 'https://images.unsplash.com/photo-1551504734-5ee1c4a1479b?auto=format&fit=crop&w=1200&q=80',
        'bebidas' => 'https://images.unsplash.com/photo-1544145945-f90425340c7e?auto=format&fit=crop&w=1200&q=80',
    ];

    /** @var array<string, float> */
    private const CATEGORY_BASE_PRICES = [
        'combos' => 32.90,
        'pizzas' => 45.90,
        'pizzas premium' => 59.90,
        'pizzas doces' => 39.90,
        'hambúrgueres' => 26.90,
        'porções' => 14.90,
        'combinados' => 49.90,
        'sushis e rolls' => 26.90,
        'sashimis' => 31.90,
        'entradas' => 15.90,
        'monte seu açaí' => 15.90,
        'açaís especiais' => 22.90,
        'complementos' => 3.50,
        'pratos do dia' => 25.90,
        'marmitas tradicionais' => 22.90,
        'proteínas' => 10.90,
        'pastéis tradicionais' => 9.90,
        'pastéis especiais' => 14.90,
        'pastéis doces' => 11.90,
        'combos de café' => 20.90,
        'cafés' => 6.90,
        'sanduíches' => 14.90,
        'doces e bolos' => 9.90,
        'monte seu gelato' => 14.90,
        'sabores' => 10.90,
        'sobremesas' => 13.90,
        'combos fit' => 34.90,
        'bowls' => 24.90,
        'refeições proteicas' => 29.90,
        'saladas' => 19.90,
        'tacos' => 14.90,
        'burritos' => 24.90,
        'nachos e acompanhamentos' => 12.90,
    ];

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

        $summary = ['stores' => 0, 'categories' => 0, 'products' => 0];
        $import = function () use ($stores, &$summary): void {
            foreach ($stores as $storeIndex => $storeData) {
                if (! is_array($storeData)) {
                    continue;
                }

                $store = $this->upsertStore($storeData, (int) $storeIndex);
                $summary['stores']++;

                foreach (($storeData['categories'] ?? []) as $categoryIndex => $categoryData) {
                    if (! is_array($categoryData) || empty($categoryData['name'])) {
                        continue;
                    }

                    $category = $this->upsertMenuCategory($store, $categoryData, (int) $categoryIndex);
                    $summary['categories']++;

                    foreach (($categoryData['products'] ?? []) as $productIndex => $productData) {
                        if (! is_array($productData) || empty($productData['name'])) {
                            continue;
                        }

                        $this->upsertProduct($store, $category, $productData, (int) $productIndex);
                        $summary['products']++;
                    }
                }
            }
        };

        if ((bool) $this->option('dry-run')) {
            $this->info(sprintf(
                'Validação concluída: %d lojas, %d categorias e %d produtos seriam importados.',
                count($stores),
                array_sum(array_map(fn (array $store): int => count($store['categories'] ?? []), $stores)),
                array_sum(array_map(fn (array $store): int => array_sum(array_map(fn (array $category): int => count($category['products'] ?? []), $store['categories'] ?? [])), $stores)),
            ));

            return self::SUCCESS;
        }

        Store::withoutEvents(fn () => DB::transaction($import));

        $this->info(sprintf(
            'Catálogo importado: %d lojas, %d categorias e %d produtos.',
            $summary['stores'],
            $summary['categories'],
            $summary['products'],
        ));

        return self::SUCCESS;
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

    /** @param array<string, mixed> $data */
    private function upsertStore(array $data, int $index): Store
    {
        $name = (string) $data['name'];
        $slug = (string) ($data['slug'] ?? Str::slug($name));
        $segment = (string) ($data['segment'] ?? 'Alimentação');
        $marketplaceCategory = $this->marketplaceCategory($segment);
        $storeNumber = 100 + $index;

        return Store::withoutGlobalScopes()->updateOrCreate(
            ['slug' => $slug],
            [
                'company_id' => null,
                'name' => $name,
                'legal_name' => $name.' Comércio de Alimentos Ltda. (Demonstração)',
                'segment' => $segment,
                'business_type' => $this->businessType($segment),
                'max_flavors' => in_array($this->businessType($segment), ['pizzaria', 'acaiteria'], true) ? 2 : 1,
                'category_id' => $marketplaceCategory->id,
                'whatsapp_phone' => '551100000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'phone' => '+55 (11) 0000-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                'logo_url' => (string) ($data['logoUrl'] ?? $this->imageFor($segment)),
                'cover_image_url' => $this->imageFor($segment),
                'menu_banner_url' => $this->imageFor($segment),
                'description' => "Loja fictícia de demonstração do marketplace Zapediu, especializada em {$segment}.",
                'zip_code' => '01000-000',
                'street' => 'Rua do Mercado',
                'number' => (string) $storeNumber,
                'complement' => 'Loja demonstração '.$storeNumber,
                'neighborhood' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'full_address' => "Rua do Mercado, {$storeNumber} - Centro, São Paulo - SP, 01000-000",
                'is_active' => true,
                'timezone' => 'America/Sao_Paulo',
                'settings' => [
                    'marketplace' => true,
                    'demo_data' => true,
                    'catalog_source' => 'zapediu_lojas_produtos_imagens_com_logos.json',
                ],
                'business_hours' => $this->businessHours($segment),
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function upsertMenuCategory(Store $store, array $data, int $index): Category
    {
        $name = (string) $data['name'];
        $slug = Str::limit('menu-'.$store->slug.'-'.Str::slug($name), 80, '');

        return Category::withoutGlobalScopes()->updateOrCreate(
            ['store_id' => $store->id, 'slug' => $slug],
            [
                'company_id' => null,
                'name' => $name,
                'icon' => 'restaurant_menu',
                'description' => "Opções de {$name} do cardápio fictício.",
                'image_url' => $this->imageFor($name),
                'ordem_exibicao' => $index + 1,
                'color' => '#F97316',
                'is_active' => true,
                'category_type' => str_contains(Str::lower($name), 'pizza') ? 'pizza' : 'standard',
            ],
        );
    }

    /** @param array<string, mixed> $data */
    private function upsertProduct(Store $store, Category $category, array $data, int $index): void
    {
        $name = (string) $data['name'];
        $price = $this->priceFor($category->name, $name, $index);

        Product::withoutGlobalScopes()->updateOrCreate(
            ['store_id' => $store->id, 'category_id' => $category->id, 'name' => $name],
            [
                'company_id' => null,
                'category_id' => $category->id,
                'category' => $category->name,
                'sku' => 'DEMO-'.strtoupper($store->slug).'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'description' => $this->descriptionFor($name, $category->name, $store->name),
                'image_path' => $this->imageFor($category->name),
                'price' => $price,
                'promotional_price' => $index % 4 === 0 ? round($price * 0.9, 2) : null,
                'stock_quantity' => 25 + (($index * 7) % 50),
                'is_active' => true,
                'has_variations' => false,
                'available_for_combo' => ! str_contains(Str::lower($category->name), 'bebida'),
            ],
        );
    }

    private function marketplaceCategory(string $segment): Category
    {
        $slug = self::MARKETPLACE_CATEGORIES[$segment] ?? 'cat_refeicao';

        return Category::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => null, 'slug' => $slug, 'store_id' => null],
            [
                'name' => Str::upper($segment),
                'description' => "Categoria do marketplace para {$segment}.",
                'image_url' => $this->imageFor($segment),
                'ordem_exibicao' => 1,
                'color' => '#EA580C',
                'is_active' => true,
                'category_type' => $slug === 'cat_pizza' ? 'pizza' : 'standard',
            ],
        );
    }

    private function businessType(string $segment): string
    {
        return match (true) {
            str_contains(Str::lower($segment), 'pizz') => 'pizzaria',
            str_contains(Str::lower($segment), 'açaí') => 'acaiteria',
            default => 'outros',
        };
    }

    private function imageFor(string $value): string
    {
        $normalized = Str::ascii(Str::lower($value));

        return match (true) {
            str_contains($normalized, 'pizza') => self::CATEGORY_IMAGES['pizza'],
            str_contains($normalized, 'hamb') || str_contains($normalized, 'lanche') || str_contains($normalized, 'porc') => self::CATEGORY_IMAGES['lanche'],
            str_contains($normalized, 'sushi') || str_contains($normalized, 'sashimi') || str_contains($normalized, 'roll') || str_contains($normalized, 'japonesa') => self::CATEGORY_IMAGES['sushi'],
            str_contains($normalized, 'acai') => self::CATEGORY_IMAGES['acai'],
            str_contains($normalized, 'marmita') || str_contains($normalized, 'proteina') || str_contains($normalized, 'prato') => self::CATEGORY_IMAGES['marmita'],
            str_contains($normalized, 'pastel') => self::CATEGORY_IMAGES['pastel'],
            str_contains($normalized, 'cafe') || str_contains($normalized, 'doce') || str_contains($normalized, 'bolo') => self::CATEGORY_IMAGES['cafe'],
            str_contains($normalized, 'gelato') || str_contains($normalized, 'sobremesa') || str_contains($normalized, 'sabor') => self::CATEGORY_IMAGES['gelato'],
            str_contains($normalized, 'fit') || str_contains($normalized, 'bowl') || str_contains($normalized, 'salada') => self::CATEGORY_IMAGES['fit'],
            str_contains($normalized, 'taco') || str_contains($normalized, 'burrito') || str_contains($normalized, 'nacho') || str_contains($normalized, 'mexicana') => self::CATEGORY_IMAGES['mexicana'],
            str_contains($normalized, 'bebida') => self::CATEGORY_IMAGES['bebidas'],
            default => self::CATEGORY_IMAGES['marmita'],
        };
    }

    private function priceFor(string $category, string $product, int $index): float
    {
        $normalizedCategory = Str::lower($category);
        $normalizedProduct = Str::ascii(Str::lower($product));

        if (str_contains($normalizedCategory, 'bebida')) {
            return match (true) {
                str_contains($normalizedProduct, 'agua') => 3.50,
                str_contains($normalizedProduct, 'coca') => 7.00,
                str_contains($normalizedProduct, 'guarana') => 6.50,
                str_contains($normalizedProduct, 'suco') => 8.50,
                default => 6.90,
            };
        }

        $base = self::CATEGORY_BASE_PRICES[$normalizedCategory] ?? 18.90;

        return round($base + (($index % 4) * 2.5), 2);
    }

    private function descriptionFor(string $product, string $category, string $store): string
    {
        $normalizedCategory = Str::lower($category);

        return match (true) {
            str_contains($normalizedCategory, 'bebida') => "{$product}, bebida fictícia servida gelada para acompanhar seu pedido.",
            str_contains($normalizedCategory, 'combo') => "{$product}, combo fictício de demonstração preparado para compartilhar.",
            str_contains($normalizedCategory, 'complemento') => "{$product}, adicional fictício para personalizar seu pedido.",
            default => "{$product}, opção fictícia de demonstração preparada na hora pela {$store}.",
        };
    }

    /** @return array<string, array{open: string, close: string}> */
    private function businessHours(string $segment): array
    {
        $isCafe = str_contains(Str::ascii(Str::lower($segment)), 'cafe');
        $opening = $isCafe ? '07:00' : '10:00';
        $closing = $isCafe ? '20:00' : '23:00';

        return [
            'monday' => ['open' => $opening, 'close' => $closing],
            'tuesday' => ['open' => $opening, 'close' => $closing],
            'wednesday' => ['open' => $opening, 'close' => $closing],
            'thursday' => ['open' => $opening, 'close' => $closing],
            'friday' => ['open' => $opening, 'close' => '23:30'],
            'saturday' => ['open' => $opening, 'close' => '23:30'],
            'sunday' => ['open' => $opening, 'close' => '22:00'],
        ];
    }
}
