<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    private const PRODUCTS_PER_STORE = 12;

    public function run(): void
    {
        $stores = Store::query()
            ->where('is_active', true)
            ->with('category:id,slug,name')
            ->orderBy('id')
            ->get();

        foreach ($stores as $store) {
            $templates = $this->templatesForCategory((string) ($store->category?->slug ?? 'cat_refeicao'));

            for ($index = 1; $index <= self::PRODUCTS_PER_STORE; $index++) {
                $template = $templates[($index - 1) % count($templates)];
                $name = $template['name'].' '.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
                $sku = 'SKU-'.$store->id.'-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);

                $price = $template['base_price'] + (($index % 5) * 1.75);
                $price = number_format((float) $price, 2, '.', '');

                Product::query()->updateOrCreate(
                    [
                        'store_id' => $store->id,
                        'name' => $name,
                    ],
                    [
                        'company_id' => $store->company_id,
                        'category_id' => $store->category_id,
                        'category' => (string) ($store->category?->name ?? 'GERAL'),
                        'sku' => $sku,
                        'description' => $template['description'].' Preparado na '.$store->name.'.',
                        'image_path' => $template['image_url'],
                        'price' => $price,
                        'stock_quantity' => 20 + ($index % 15),
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * @return array<int, array{name:string,description:string,image_url:string,base_price:float}>
     */
    private function templatesForCategory(string $slug): array
    {
        return match ($slug) {
            'cat_lanches' => [
                ['name' => 'Hamburguer Artesanal', 'description' => 'Pao brioche e blend bovino grelhado.', 'image_url' => 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=800&q=80', 'base_price' => 24.90],
                ['name' => 'X-Bacon Especial', 'description' => 'Bacon crocante e queijo derretido.', 'image_url' => 'https://images.unsplash.com/photo-1553979459-d2229ba7433b?auto=format&fit=crop&w=800&q=80', 'base_price' => 27.90],
                ['name' => 'Batata Crocante', 'description' => 'Porcao de batatas sequinhas.', 'image_url' => 'https://images.unsplash.com/photo-1576107232684-1279f390859f?auto=format&fit=crop&w=800&q=80', 'base_price' => 14.90],
            ],
            'cat_pastel' => [
                ['name' => 'Pastel de Carne', 'description' => 'Massa fina com recheio temperado.', 'image_url' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=800&q=80', 'base_price' => 12.90],
                ['name' => 'Pastel de Queijo', 'description' => 'Queijo derretido e massa crocante.', 'image_url' => 'https://images.unsplash.com/photo-1483695028939-5bb13f8648b0?auto=format&fit=crop&w=800&q=80', 'base_price' => 11.90],
                ['name' => 'Caldo de Cana', 'description' => 'Bebida gelada para acompanhar.', 'image_url' => 'https://images.unsplash.com/photo-1464306076886-da185f6a9d05?auto=format&fit=crop&w=800&q=80', 'base_price' => 9.50],
            ],
            'cat_pizza' => [
                ['name' => 'Pizza Margherita', 'description' => 'Molho, queijo e manjericao fresco.', 'image_url' => 'https://images.unsplash.com/photo-1513104890138-7c749659a591?auto=format&fit=crop&w=800&q=80', 'base_price' => 49.90],
                ['name' => 'Pizza Calabresa', 'description' => 'Calabresa artesanal e cebola roxa.', 'image_url' => 'https://images.unsplash.com/photo-1594007654729-407eedc4be65?auto=format&fit=crop&w=800&q=80', 'base_price' => 54.90],
                ['name' => 'Pizza Frango Catupiry', 'description' => 'Frango desfiado com creme.', 'image_url' => 'https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=800&q=80', 'base_price' => 57.90],
            ],
            'cat_acai' => [
                ['name' => 'Acai Tradicional', 'description' => 'Creme de acai com granola.', 'image_url' => 'https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=800&q=80', 'base_price' => 18.90],
                ['name' => 'Acai com Morango', 'description' => 'Acai com frutas frescas.', 'image_url' => 'https://images.unsplash.com/photo-1505253716362-afaea1d3d1af?auto=format&fit=crop&w=800&q=80', 'base_price' => 21.90],
                ['name' => 'Acai Fit', 'description' => 'Acai com banana e mel.', 'image_url' => 'https://images.unsplash.com/photo-1511690743698-d9d85f2fbf38?auto=format&fit=crop&w=800&q=80', 'base_price' => 22.90],
            ],
            'cat_refeicao' => [
                ['name' => 'Prato Executivo', 'description' => 'Arroz, feijao e proteina grelhada.', 'image_url' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=800&q=80', 'base_price' => 28.90],
                ['name' => 'Frango Grelhado', 'description' => 'Frango suculento com legumes.', 'image_url' => 'https://images.unsplash.com/photo-1532550907401-a500c9a57435?auto=format&fit=crop&w=800&q=80', 'base_price' => 31.90],
                ['name' => 'Carne Acebolada', 'description' => 'Carne salteada com cebola.', 'image_url' => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=800&q=80', 'base_price' => 34.90],
            ],
            'cat_farmacia' => [
                ['name' => 'Kit Primeiros Socorros', 'description' => 'Itens basicos para emergencia.', 'image_url' => 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?auto=format&fit=crop&w=800&q=80', 'base_price' => 39.90],
                ['name' => 'Vitamina C', 'description' => 'Suplemento para imunidade.', 'image_url' => 'https://images.unsplash.com/photo-1584017911766-d451b3d0e843?auto=format&fit=crop&w=800&q=80', 'base_price' => 24.90],
                ['name' => 'Protetor Solar', 'description' => 'Protecao diaria para pele.', 'image_url' => 'https://images.unsplash.com/photo-1556228724-4b2f76c2c913?auto=format&fit=crop&w=800&q=80', 'base_price' => 44.90],
            ],
            'cat_padaria' => [
                ['name' => 'Pao Frances', 'description' => 'Pao fresco assado no dia.', 'image_url' => 'https://images.unsplash.com/photo-1509440159596-0249088772ff?auto=format&fit=crop&w=800&q=80', 'base_price' => 8.90],
                ['name' => 'Croissant Manteiga', 'description' => 'Massa folhada leve.', 'image_url' => 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?auto=format&fit=crop&w=800&q=80', 'base_price' => 10.90],
                ['name' => 'Bolo Caseiro', 'description' => 'Fatia macia de bolo do dia.', 'image_url' => 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?auto=format&fit=crop&w=800&q=80', 'base_price' => 12.90],
            ],
            'cat_mercadinho' => [
                ['name' => 'Cesta Basica', 'description' => 'Selecao de itens essenciais.', 'image_url' => 'https://images.unsplash.com/photo-1542838132-92c53300491e?auto=format&fit=crop&w=800&q=80', 'base_price' => 69.90],
                ['name' => 'Kit Cafe da Manha', 'description' => 'Pao, leite e itens matinais.', 'image_url' => 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?auto=format&fit=crop&w=800&q=80', 'base_price' => 34.90],
                ['name' => 'Hortifruti Fresco', 'description' => 'Frutas e legumes selecionados.', 'image_url' => 'https://images.unsplash.com/photo-1518843875459-f738682238a6?auto=format&fit=crop&w=800&q=80', 'base_price' => 29.90],
            ],
            default => [
                ['name' => 'Item Especial', 'description' => 'Produto de alta procura no marketplace.', 'image_url' => 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=800&q=80', 'base_price' => 19.90],
                ['name' => 'Item Premium', 'description' => 'Versao premium do produto.', 'image_url' => 'https://images.unsplash.com/photo-1544025162-d76694265947?auto=format&fit=crop&w=800&q=80', 'base_price' => 29.90],
                ['name' => 'Item Classico', 'description' => 'Opcao tradicional para o dia a dia.', 'image_url' => 'https://images.unsplash.com/photo-1511690743698-d9d85f2fbf38?auto=format&fit=crop&w=800&q=80', 'base_price' => 24.90],
            ],
        };
    }
}
