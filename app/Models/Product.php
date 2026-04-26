<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use BelongsToCompany;
    use InteractsWithMedia;

    protected $fillable = [
        'company_id',
        'store_id',
        'category_id',
        'selection_group_id',
        'variation_group_id',
        'name',
        'sku',
        'description',
        'category',
        'image_path',
        'price',
        'stock_quantity',
        'is_active',
        'has_variations',
        'variation_question',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'has_variations' => 'boolean',
    ];

    protected $appends = [
        'image_url',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('products')
            ->useDisk('r2'); // ou o disk correto
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function selectionGroup(): BelongsTo
    {
        return $this->belongsTo(SelectionGroup::class);
    }

    public function variationGroup(): BelongsTo
    {
        return $this->belongsTo(VariationGroup::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function optionalFlows(): MorphToMany
    {
        return $this->morphToMany(OptionalFlow::class, 'assignable', 'optional_flow_assignments');
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            $whatsapp = $this->getFirstMediaUrl('products', 'whatsapp');
            if ($whatsapp !== '') {
                return $whatsapp;
            }

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

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('whatsapp')
            ->width(800)
            ->format('webp')
            ->quality(75);
    }
}
