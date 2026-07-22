<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'business_hours' => 'array',
        'size_template' => 'array',
        'pizza_settings' => 'array',
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
