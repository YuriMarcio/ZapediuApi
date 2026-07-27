<?php

namespace App\Services\Zapi\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\Scopes\CompanyScope;
use App\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StoreSearch
{
    /**
     * Busca lojas por nome/slug/descrição/segmento/categoria — ponto único usado tanto pela
     * busca por palavra-chave quanto pelo NLP (GroqClient). Só retorna lojas ativas e
     * abertas agora (Store::isOpenNow).
     */
    public function byQuery(string $query): array
    {
        $normalizedQuery = trim((string) Str::of($query)->lower()->ascii()->toString());

        $storesQuery = Store::query()
            ->where('is_active', true)
            ->with(['category:id,name,slug', 'company:id,is_open']);

        if ($normalizedQuery !== '') {
            $tokens = array_values(array_filter(explode(' ', $normalizedQuery)));

            foreach ($tokens as $token) {
                $storesQuery->where(function ($builder) use ($token): void {
                    $like = '%'.$token.'%';

                    $builder
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(slug) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(segment) LIKE ?', [$like])
                        ->orWhereHas('category', fn ($categoryBuilder) => $categoryBuilder->whereRaw('LOWER(name) LIKE ?', [$like]));
                });
            }
        }

        return $storesQuery
            ->orderBy('name')
            ->distinct()
            ->get()
            ->filter(fn (Store $store): bool => $store->isOpenNow())
            ->pluck('slug')
            ->filter(fn (mixed $slug): bool => is_string($slug) && trim($slug) !== '')
            ->values()
            ->all();
    }

    /**
     * Busca produtos casando pelo nome/descrição, cruzando todas as lojas ativas e abertas —
     * usado quando o NLP classifica a intenção como `tipo:"produto"` (spec §1.1.b). Ao
     * contrário de `byProduct`/`byQuery` (que retornam lojas), aqui o retorno já é o Product
     * em si, com a Store carregada, pronto pro carrossel de produtos cross-store.
     *
     * @return Collection<int, Product>
     */
    /**
     * Palavras sem valor de busca — "hambúrguer DE bacon" não pode virar uma condição LIKE
     * pra "de", que bateria em quase toda descrição do catálogo.
     */
    private const SEARCH_STOPWORDS = ['de', 'da', 'do', 'das', 'dos', 'com', 'sem', 'um', 'uma', 'uns', 'umas', 'para', 'pra', 'e', 'ou', 'no', 'na', 'nos', 'nas', 'o', 'a'];

    /**
     * Sinônimos por categoria geral — mesmo com o prompt pedindo a string EXATA da lista
     * (§ knownGeneralCategories), a IA já devolveu "Lanches" em vez de "HAMBURGUERIA" (mesma
     * ideia, palavra diferente — não é erro de digitação, então LIKE não resolve). Lista
     * pequena e estável (13 categorias gerais na plataforma toda), curada uma vez.
     */
    private const GENERAL_CATEGORY_SYNONYMS = [
        'HAMBURGUERIA' => ['hamburgueria', 'hamburguer', 'hamburgueres', 'lanches', 'lanche', 'sanduiche', 'sanduiches', 'burger'],
        'PIZZARIA' => ['pizzaria', 'pizza', 'pizzas'],
        'AÇAÍ E SOBREMESAS' => ['acai', 'sobremesa', 'sobremesas', 'doces', 'doce'],
        'SUSHI E COMIDA JAPONESA' => ['sushi', 'japonesa', 'japones', 'comida japonesa', 'temaki'],
        'MARMITARIA E COMIDA CASEIRA' => ['marmita', 'marmitaria', 'comida caseira', 'caseira', 'almoco', 'refeicao', 'refeicoes'],
        'PASTELARIA' => ['pastelaria', 'pastel', 'pasteis'],
        'CAFETERIA' => ['cafeteria', 'cafe', 'cafes'],
        'GELATERIA E SOBREMESAS' => ['gelateria', 'sorvete', 'sorvetes', 'gelato', 'sorveteria'],
        'ALIMENTAÇÃO SAUDÁVEL E REFEIÇÕES FITNESS' => ['saudavel', 'fitness', 'fit', 'dieta', 'saudaveis'],
        'COMIDA MEXICANA' => ['mexicana', 'mexicano', 'taco', 'tacos', 'burrito', 'burritos'],
        'FARMÁCIA' => ['farmacia', 'remedio', 'remedios'],
        'MERCADINHO' => ['mercadinho', 'mercado', 'mercearia'],
        'PADARIA' => ['padaria', 'pao', 'paes'],
    ];

    public function productsByQuery(string $query, int $limit = 60): Collection
    {
        $normalizedQuery = trim((string) Str::of($query)->lower()->ascii()->toString());

        if ($normalizedQuery === '') {
            return collect();
        }

        // Tokeniza por palavra (igual ao byQuery pra loja) em vez de casar a frase inteira
        // como um substring só — "hambúrguer de bacon" tem que bater em "Bacon Inferno" pela
        // palavra "bacon", não precisa da frase inteira aparecer literal na descrição.
        $tokens = array_values(array_filter(
            explode(' ', $normalizedQuery),
            fn (string $token): bool => mb_strlen($token) >= 3 && ! in_array($token, self::SEARCH_STOPWORDS, true)
        ));

        if ($tokens === []) {
            $tokens = [$normalizedQuery];
        }

        return Product::query()
            ->available()
            ->with(['store' => fn ($storeQuery) => $storeQuery->with('category:id,name,slug')->where('is_active', true)])
            ->whereHas('store', fn ($storeQuery) => $storeQuery->where('is_active', true))
            // Cada palavra precisa bater em ALGUM lugar (nome OU descrição) — TODAS as
            // palavras precisam bater (AND entre elas, mesmo padrão do byQuery pra loja).
            // Com OR entre palavras, "hambúrguer de bacon" batia em qualquer produto com
            // "bacon" solto — inclusive uma pizza sabor Bacon numa pizzaria, o que não faz
            // sentido nenhum pra quem pediu hambúrguer.
            ->where(function ($builder) use ($tokens): void {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $builder->where(function ($tokenBuilder) use ($like): void {
                        $tokenBuilder
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
                    });
                }
            })
            ->orderBy('name')
            // Margem sobre o limite: parte é filtrada depois pelo horário de funcionamento
            // da loja (isOpenNow não dá pra empurrar pro SQL).
            ->limit($limit * 3)
            ->get()
            ->filter(fn (Product $product): bool => $product->store !== null && $product->store->isOpenNow())
            ->values()
            ->take($limit);
    }

    /**
     * Categorias GERAIS/segmento da plataforma (a que a loja escolhe no cadastro —
     * `Store.category_id`, ex: "HAMBURGUERIA", "PIZZARIA") — lista fechada e pequena, bem
     * diferente da categoria livre que cada loja cria no próprio cardápio. Alimenta o
     * grounding do prompt do `GroqClient`, pra IA escolher uma dessas em vez de chutar um
     * nome livre que provavelmente não bate em nada.
     */
    public function knownGeneralCategories(): array
    {
        return Cache::remember('zapi:known_general_categories', 300, function (): array {
            return $this->globalCategoryQuery()
                ->orderBy('name')
                ->pluck('name')
                ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                ->values()
                ->all();
        });
    }

    /**
     * Categoria geral (`store_id IS NULL`) é dado compartilhado entre TODAS as empresas da
     * plataforma (`company_id` da linha é sempre NULL, de propósito), não pertence a nenhuma
     * empresa específica. Sem isso, o `CompanyScope` (`BelongsToCompany`) do model soma um
     * `WHERE company_id = <empresa do tenant atual>` por baixo dos panos — como a linha tem
     * `company_id NULL`, NUNCA bate (`NULL = 2` é falso em SQL), e a lista vem vazia sempre
     * que o bot roda dentro do fluxo real do webhook (que amarra o tenant da empresa que
     * recebeu a mensagem). Só aparecia certo no teste porque tinker não tem tenant nenhum
     * setado — bug só visível rodando de verdade.
     */
    private function globalCategoryQuery()
    {
        return Category::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereNull('store_id')
            ->where('is_active', true);
    }

    /**
     * Busca produto quando o termo não bate em título/descrição (§1.1.b, nível 2): a IA já
     * classificou o termo numa categoria GERAL (segmento, ex: "HAMBURGUERIA") — acha as lojas
     * desse segmento e, dentro delas, casa o termo ORIGINAL do cliente (`$item`, ex:
     * "hambúrguer") contra o nome da categoria PRÓPRIA da loja (ex: "Hambúrgueres") — as duas
     * palavras têm a mesma raiz e batem por substring, diferente de "HAMBURGUERIA" (segmento)
     * que não bate em "Hambúrgueres" (categoria da loja).
     *
     * @return Collection<int, Product>
     */
    public function productsByGeneralCategoryAndTerm(string $generalCategory, string $item, int $limit = 60): Collection
    {
        $normalizedCategory = trim((string) Str::of($generalCategory)->lower()->ascii()->toString());
        if ($normalizedCategory === '') {
            return collect();
        }

        $category = $this->globalCategoryQuery()
            ->whereRaw('LOWER(name) = ?', [$normalizedCategory])
            ->first();

        if ($category === null) {
            // Rede de segurança 1: a IA pode não devolver a string idêntica — tenta por LIKE.
            $category = $this->globalCategoryQuery()
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$normalizedCategory.'%'])
                ->first();
        }

        if ($category === null) {
            // Rede de segurança 2: a IA devolveu um SINÔNIMO em vez do nome exato (ex:
            // "Lanches" no lugar de "HAMBURGUERIA") — LIKE não resolve porque não são a
            // mesma palavra, só o mesmo conceito. Usa o mapa curado.
            $matchedGeneralName = null;
            foreach (self::GENERAL_CATEGORY_SYNONYMS as $generalName => $synonyms) {
                if (in_array($normalizedCategory, $synonyms, true)) {
                    $matchedGeneralName = $generalName;
                    break;
                }
            }

            if ($matchedGeneralName !== null) {
                $category = $this->globalCategoryQuery()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($matchedGeneralName)])
                    ->first();
            }
        }

        if ($category === null) {
            return collect();
        }

        $storeIds = Store::query()
            ->where('is_active', true)
            ->where('category_id', $category->id)
            ->get()
            ->filter(fn (Store $store): bool => $store->isOpenNow())
            ->pluck('id');

        if ($storeIds->isEmpty()) {
            return collect();
        }

        $normalizedItem = trim((string) Str::of($item)->lower()->ascii()->toString());
        $itemLike = '%'.$normalizedItem.'%';

        $products = Product::query()
            ->available()
            ->whereIn('store_id', $storeIds)
            ->when($normalizedItem !== '', fn ($query) => $query->whereHas(
                'category',
                fn ($categoryQuery) => $categoryQuery->whereRaw('LOWER(name) LIKE ?', [$itemLike])
            ))
            ->with(['store' => fn ($storeQuery) => $storeQuery->with('category:id,name,slug')])
            ->orderBy('store_id')
            ->orderBy('name')
            ->limit($limit * 4)
            ->get();

        // Fallback de robustez: se nenhuma loja candidata tiver uma categoria própria batendo
        // com o termo (nomenclatura idiossincrática do lojista), mostra os produtos
        // disponíveis dessas lojas sem o sub-filtro — melhor algo da loja certa que nada.
        if ($products->isEmpty()) {
            $products = Product::query()
                ->available()
                ->whereIn('store_id', $storeIds)
                ->with(['store' => fn ($storeQuery) => $storeQuery->with('category:id,name,slug')])
                ->orderBy('store_id')
                ->orderBy('name')
                ->limit($limit * 4)
                ->get();
        }

        return $products
            ->groupBy('store_id')
            ->flatMap(fn (Collection $group): Collection => $group->sortBy('name')->take(4))
            ->sortBy([['store_id', 'asc'], ['name', 'asc']])
            ->values()
            ->take($limit);
    }
}
