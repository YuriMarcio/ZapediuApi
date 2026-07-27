<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Models\Category;
use App\Services\GeocodeService;
use App\Services\Stores\StoreSizeTemplateDefaults;
use Illuminate\Support\Facades\Log;

/**
 * @property string $slug
 * @property int $id
 * @property string $name
 * @property string $category
 */
class Store extends Model implements HasMedia
{
    use BelongsToCompany;
    use InteractsWithMedia;

    /**
     * Tipos de negócio suportados. Pizzaria e açaiteria compartilham o mesmo fluxo de
     * cardápio (tamanhos + "meio a meio" / múltiplos sabores).
     */
    public const BUSINESS_TYPES = ['pizzaria', 'acaiteria', 'outros'];

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'legal_name',
        'slug',
        'full_address',
        'segment',
        'business_type',
        'max_flavors',
        'size_template',
        'pizza_settings',
        'category_id',
        'whatsapp_phone',
        'phone',
        'cnpj',
        'logo_url',
        'cover_image_url',
        'carousel_banner_url',
        'menu_banner_url',
        'description',
        'zip_code',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'is_active',
        'timezone',
        'settings',
        'latitude',
        'longitude',
        'business_hours',
        'delivery_radius_km',
        'delivery_fee_per_km',
        'delivery_fee_min',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'business_hours' => 'array',
        'size_template' => 'array',
        'pizza_settings' => 'array',
        'delivery_radius_km' => 'decimal:2',
        'delivery_fee_per_km' => 'decimal:2',
        'delivery_fee_min' => 'decimal:2',
    ];

    public const PIZZA_FLAVOR_PRICE_RULES = ['most_expensive', 'average'];
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Pizzaria e açaiteria compartilham o mesmo fluxo de cardápio no WhatsApp: tamanho por
     * botão + pergunta de borda. Lojas 'outros' seguem o fluxo padrão (botões de quantidade).
     */
    public function isFlavorMenuStore(): bool
    {
        return in_array($this->business_type, ['pizzaria', 'acaiteria'], true);
    }

    /**
     * Subconjunto do fluxo acima: só entra a pergunta de "outro sabor?" (meio a meio) quando
     * a loja permite combinar 2+ sabores num item.
     */
    public function usesFlavorComboFlow(): bool
    {
        return $this->isFlavorMenuStore() && (int) $this->max_flavors >= 2;
    }

    /**
     * Loja aberta agora: cruza o toggle manual da empresa (pausa) com o horário de
     * funcionamento configurado (business_hours). Sem horário configurado, considera
     * aberta por padrão (não esconder lojas antigas que ainda não preencheram isso).
     * Requer `company` carregado (ex: `with('company:id,is_open')`) pra evitar N+1.
     */
    public function isOpenNow(): bool
    {
        if ($this->relationLoaded('company') && $this->company !== null && $this->company->is_open === false) {
            return false;
        }

        $entries = $this->normalizedBusinessHours();
        if ($entries === null) {
            return true;
        }

        $now = CarbonImmutable::now($this->timezone ?: 'America/Sao_Paulo');

        if ($this->isWithinDayWindow($now, $entries->get(strtolower($now->englishDayOfWeek)), $now)) {
            return true;
        }

        // Janela de ontem que cruza a meia-noite (ex.: 22h-03h) ainda pode valer de madrugada
        // hoje — sem isso, uma loja que fecha às 3h da manhã apareceria fechada às 2h.
        $yesterday = $now->subDay();

        return $this->isWithinDayWindow($yesterday, $entries->get(strtolower($yesterday->englishDayOfWeek)), $now);
    }

    /**
     * `business_hours` aparece em dois formatos nos dados reais: lista
     * `[{day, enabled, open, close}]` (o que `StoreHoursRequest` valida) e mapa
     * `{monday: {open, close}, ...}` (dado antigo/importado, sem `day`/`enabled`). Normaliza
     * os dois pra um mapa dia-da-semana => entry, ou `null` se não há nada configurado.
     */
    private function normalizedBusinessHours(): ?Collection
    {
        $hours = $this->business_hours;
        if (! is_array($hours) || $hours === []) {
            return null;
        }

        if (array_is_list($hours)) {
            return collect($hours)->keyBy(fn ($h) => strtolower((string) ($h['day'] ?? '')));
        }

        return collect($hours)->mapWithKeys(fn ($entry, $day) => [strtolower((string) $day) => $entry]);
    }

    /**
     * @param array{enabled?: bool, open?: string, close?: string}|null $entry
     */
    private function isWithinDayWindow(CarbonImmutable $referenceDay, ?array $entry, CarbonImmutable $now): bool
    {
        // `enabled` ausente = aberto nesse dia (dado antigo/importado não tem essa chave).
        // Só fecha quando o lojista desativou o dia explicitamente (`enabled: false`).
        if (! $entry || ($entry['enabled'] ?? true) === false || empty($entry['open']) || empty($entry['close'])) {
            return false;
        }

        $open = $referenceDay->setTimeFromTimeString((string) $entry['open']);
        $close = $referenceDay->setTimeFromTimeString((string) $entry['close']);

        if ($close->lessThanOrEqualTo($open)) {
            $close = $close->addDay();
        }

        return $now->between($open, $close);
    }

    public function scopeWithPromotionFlag(Builder $query): Builder
    {
        return $query->withExists(['products as has_active_promotion' => function (Builder $productsQuery): void {
            $productsQuery
                ->where('is_active', true)
                ->whereNotNull('promotional_price')
                ->whereColumn('promotional_price', '<', 'price');
        }]);
    }

    /**
     * Tamanhos disponíveis pro fluxo de sabor (grade fixa do formulário "Novo sabor").
     * Usa o template salvo pela loja, ou o default do business_type se nada foi salvo.
     *
     * @return array<int, array{key: string, label: string, sublabel: string}>
     */
    public function sizeTemplate(): array
    {
        return $this->size_template ?? StoreSizeTemplateDefaults::for($this->business_type);
    }

    /**
     * Lojas pizzaria ganham o motor avançado de configuração (tamanhos próprios,
     * preço por categoria, bordas/ingredientes/molhos). Açaiteria e outros continuam
     * no fluxo genérico de sabores (size_template + max_flavors), inalterado.
     */
    public function isPizzaAdvancedStore(): bool
    {
        return $this->business_type === 'pizzaria';
    }

    public function pizzaSizes(): HasMany
    {
        return $this->hasMany(StorePizzaSize::class)->orderBy('position');
    }

    /**
     * Configurações do motor avançado de pizzaria (regra de combo + toggles de recursos),
     * com defaults aplicados por cima do que estiver salvo — mesmo padrão de sizeTemplate().
     *
     * @return array{flavor_price_rule: string, features: array{bordas: bool, ingredientes: bool, molhos: bool, observacoes: bool}}
     */
    public function pizzaSettings(): array
    {
        return array_replace_recursive([
            'flavor_price_rule' => 'most_expensive',
            'features' => [
                'bordas' => true,
                'ingredientes' => true,
                'molhos' => true,
                'observacoes' => true,
            ],
        ], $this->pizza_settings ?? []);
    }

    public function pizzaFlavorPriceRule(): string
    {
        return $this->pizzaSettings()['flavor_price_rule'];
    }

    public function pizzaFeatureEnabled(string $feature): bool
    {
        return (bool) ($this->pizzaSettings()['features'][$feature] ?? false);
    }

    /**
     * Nome de pasta usado para organizar os uploads dessa loja no bucket:
     * "{nome-slugificado}-{id}", ex.: "pizzaria-do-joao-7".
     */
    public function mediaFolderName(): string
    {
        $slug = \Illuminate\Support\Str::slug($this->name) ?: 'loja';

        return "{$slug}-{$this->id}";
    }

    public function categories(): HasMany
    {
        // Isso assume que sua tabela 'categories' tem uma 'store_id'
        return $this->hasMany(Category::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Store $store) {
            // Se a loja for nova ou mudar de endereço...
            if ($store->isDirty(['street', 'number', 'neighborhood', 'city', 'state'])) {

                // Usa o seu accessor ou junta as variáveis
                $fullText = "{$store->street}, {$store->number}, {$store->neighborhood}, {$store->city}, {$store->state}";

                $coords = GeocodeService::getCoordinates($fullText);

                if ($coords) {
                    $store->latitude = $coords['latitude'];
                    $store->longitude = $coords['longitude'];
                }
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    // Os campos logo_url, cover_image_url e full_address agora são persistidos diretamente no banco.
    // Se quiser lógica extra, crie accessors ou mutators conforme necessário.
}
