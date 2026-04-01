<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StoreSeeder extends Seeder
{
    private const STORES_PER_SCOPE = 50;

    public function run(): void
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('ordem_exibicao')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'slug']);

        if ($categories->isEmpty()) {
            return;
        }

        $scopes = $categories->groupBy(fn (Category $category): string => (string) ($category->company_id ?? 'null'));

        foreach ($scopes as $scopeKey => $scopeCategories) {
            $this->seedScopeStores($scopeKey, $scopeCategories);
        }
    }

    private function seedScopeStores(string $scopeKey, Collection $categories): void
    {
        $companyId = $scopeKey === 'null' ? null : (int) $scopeKey;
        $categoryCount = $categories->count();

        for ($index = 1; $index <= self::STORES_PER_SCOPE; $index++) {
            /** @var Category $category */
            $category = $categories->values()->get(($index - 1) % $categoryCount);

            $baseName = $this->storeBaseName($index, $category);
            $name = $baseName.' '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $slug = Str::slug($name).'-'.$scopeKey;

            $phoneSuffix = str_pad((string) ((int) $scopeKey === 0 ? $index : ((int) $scopeKey * 100 + $index)), 4, '0', STR_PAD_LEFT);

            Store::query()->updateOrCreate(
                [
                    'slug' => $slug,
                ],
                [
                    'company_id' => $companyId,
                    'user_id' => null,
                    'name' => $name,
                    'legal_name' => $name.' LTDA',
                    'segment' => 'food',
                    'category_id' => $category->id,
                    'whatsapp_phone' => '55989'.$phoneSuffix.'11',
                    'phone' => '55319'.$phoneSuffix.'22',
                    'description' => 'Loja local de '.$category->name.' no marketplace Zapediu com entrega rapida.',
                    'logo_path' => $this->storeImageForCategory((string) $category->slug, true),
                    'cover_image_path' => $this->storeImageForCategory((string) $category->slug, false),
                    'zip_code' => '30110-000',
                    'street' => 'Rua Marketplace',
                    'number' => (string) (100 + $index),
                    'complement' => 'Loja '.$index,
                    'neighborhood' => 'Centro',
                    'city' => 'Belo Horizonte',
                    'state' => 'MG',
                    'is_active' => true,
                    'settings' => [
                        'marketplace' => true,
                    ],
                    'business_hours' => [
                        'monday' => ['open' => '09:00', 'close' => '22:00'],
                        'tuesday' => ['open' => '09:00', 'close' => '22:00'],
                        'wednesday' => ['open' => '09:00', 'close' => '22:00'],
                        'thursday' => ['open' => '09:00', 'close' => '22:00'],
                        'friday' => ['open' => '09:00', 'close' => '23:00'],
                        'saturday' => ['open' => '10:00', 'close' => '23:00'],
                        'sunday' => ['open' => '10:00', 'close' => '21:00'],
                    ],
                ]
            );
        }
    }

    private function storeBaseName(int $index, Category $category): string
    {
        $prefixes = [
            'Sabor',
            'Ponto',
            'Casa',
            'Villa',
            'Emporio',
            'Cantinho',
            'Praca',
            'Estacao',
            'Mercado',
            'Tempero',
        ];

        $suffixes = [
            'Local',
            'Express',
            'Urbano',
            'Prime',
            'Da Esquina',
            'Do Bairro',
            'Da Praca',
            'Delivery',
            'Central',
            'Gourmet',
        ];

        $prefix = $prefixes[$index % count($prefixes)];
        $suffix = $suffixes[$index % count($suffixes)];
        $categoryLabel = Str::headline(str_replace('cat_', '', (string) $category->slug));

        return $prefix.' '.$categoryLabel.' '.$suffix;
    }

    private function storeImageForCategory(string $slug, bool $logo): string
    {
        $images = [
            'cat_lanches' => [
                'logo' => 'https://images.unsplash.com/photo-1550547660-d9450f859349?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_pastel' => [
                'logo' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1483695028939-5bb13f8648b0?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_pizza' => [
                'logo' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_acai' => [
                'logo' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_refeicao' => [
                'logo' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_farmacia' => [
                'logo' => 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_padaria' => [
                'logo' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1483695028939-5bb13f8648b0?auto=format&fit=crop&w=900&q=80',
            ],
            'cat_mercadinho' => [
                'logo' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=300&q=80',
                'cover' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=900&q=80',
            ],
        ];

        $fallback = 'https://images.unsplash.com/photo-1556740749-887f6717d7e4?auto=format&fit=crop&w='.
            ($logo ? '300' : '900').
            '&q=80';

        return $images[$slug][$logo ? 'logo' : 'cover'] ?? $fallback;
    }
}
