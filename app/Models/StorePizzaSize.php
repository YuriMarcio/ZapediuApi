<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorePizzaSize extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'slice_count',
        'max_flavors',
        'is_active',
        'position',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'slice_count' => 'integer',
        'max_flavors' => 'integer',
        'position' => 'integer',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function categoryPrices(): HasMany
    {
        return $this->hasMany(CategoryPizzaSizePrice::class);
    }

    public function productVariations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }
}
