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

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'legal_name',
        'slug',
        'full_address',
        'segment',
        'category_id',
        'whatsapp_phone',
        'phone',
        'cnpj',
        'logo_url',
        'cover_image_url',
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
    ];
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
