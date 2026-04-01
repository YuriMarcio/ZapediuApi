<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'name' => 'LANCHES',
                'slug' => 'cat_lanches',
                'image_url' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 1,
            ],
            [
                'name' => 'PASTEL',
                'slug' => 'cat_pastel',
                'image_url' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 2,
            ],
            [
                'name' => 'PIZZA',
                'slug' => 'cat_pizza',
                'image_url' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 3,
            ],
            [
                'name' => 'AÇAÍ',
                'slug' => 'cat_acai',
                'image_url' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 4,
            ],
            [
                'name' => 'REFEIÇÃO',
                'slug' => 'cat_refeicao',
                'image_url' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 5,
            ],
            [
                'name' => 'FARMÁCIA',
                'slug' => 'cat_farmacia',
                'image_url' => 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 6,
            ],
            [
                'name' => 'PADARIA',
                'slug' => 'cat_padaria',
                'image_url' => 'https://images.unsplash.com/photo-1483695028939-5bb13f8648b0?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 7,
            ],
            [
                'name' => 'MERCADINHO',
                'slug' => 'cat_mercadinho',
                'image_url' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=900&q=80',
                'ordem_exibicao' => 8,
            ],
        ];

        $companies = Company::query()->pluck('id')->all();

        if ($companies === []) {
            foreach ($rows as $row) {
                Category::query()->updateOrCreate(
                    ['company_id' => null, 'slug' => $row['slug']],
                    [
                        'name' => $row['name'],
                        'image_url' => $row['image_url'],
                        'ordem_exibicao' => $row['ordem_exibicao'],
                        'is_active' => true,
                    ]
                );
            }

            return;
        }

        foreach ($companies as $companyId) {
            foreach ($rows as $row) {
                Category::query()->updateOrCreate(
                    ['company_id' => $companyId, 'slug' => $row['slug']],
                    [
                        'name' => $row['name'],
                        'image_url' => $row['image_url'],
                        'ordem_exibicao' => $row['ordem_exibicao'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
