<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OptionalFlowStepOption extends Model
{
    protected $fillable = [
        'optional_flow_step_id',
        'source_type',
        'product_id',
        'category_id',
        'title',
        'description',
        'image_url',
        'price',
        'base_price',
        'merchant_price',
        'pricing_mode',
        'available_size_ids',
        'max_quantity',
        'is_active',
        'position',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'base_price' => 'decimal:2',
        'merchant_price' => 'decimal:2',
        'available_size_ids' => 'array',
        'max_quantity' => 'integer',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function step(): BelongsTo
    {
        return $this->belongsTo(OptionalFlowStep::class, 'optional_flow_step_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function sizePrices(): HasMany
    {
        return $this->hasMany(OptionalFlowStepOptionSize::class, 'optional_flow_step_option_id');
    }

    public function linkedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_optional_flow_step_options');
    }

    /**
     * `null` (não configurado) = disponível em todos os tamanhos ativos da loja.
     */
    public function isAvailableForSize(int $storePizzaSizeId): bool
    {
        return $this->available_size_ids === null || in_array($storePizzaSizeId, $this->available_size_ids, true);
    }
}
