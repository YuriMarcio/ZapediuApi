<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
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
        'segment',
        'category_id',
        'whatsapp_phone',
        'phone',
        'cnpj',
        'logo_path',
        'cover_image_path',
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
        'business_hours',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'business_hours' => 'array',
    ];

    protected $appends = [
        'logo_url',
        'cover_image_url',
        'full_address',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->absoluteMediaUrl('logo_path', 'logo'));
    }

    protected function coverImageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->absoluteMediaUrl('cover_image_path', 'cover'));
    }

    protected function fullAddress(): Attribute
    {
        return Attribute::get(function (): ?string {
            $parts = array_filter([
                $this->street,
                $this->number,
                $this->complement,
                $this->neighborhood,
                $this->city,
                $this->state,
                $this->zip_code,
            ], fn (?string $value): bool => $value !== null && $value !== '');

            if ($parts === []) {
                return null;
            }

            return implode(', ', $parts);
        });
    }

    private function absoluteMediaUrl(string $column, string $collection): ?string
    {
        $fromMedia = $this->getFirstMediaUrl($collection);

        if ($fromMedia !== '') {
            return str_starts_with($fromMedia, 'http') ? $fromMedia : url($fromMedia);
        }

        $path = (string) ($this->{$column} ?? '');

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('/storage/'.ltrim($path, '/'));
    }
}
