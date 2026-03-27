<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use BelongsToCompany;
    use InteractsWithMedia;

    protected $fillable = [
        'company_id',
        'store_id',
        'category_id',
        'name',
        'sku',
        'description',
        'category',
        'image_path',
        'price',
        'stock_quantity',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'image_url',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $fromMedia = $this->getFirstMediaUrl('products');

            if ($fromMedia !== '') {
                return str_starts_with($fromMedia, 'http') ? $fromMedia : url($fromMedia);
            }

            if ($this->image_path === null || $this->image_path === '') {
                return null;
            }

            if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
                return $this->image_path;
            }

            return url('/storage/'.ltrim($this->image_path, '/'));
        });
    }
}
