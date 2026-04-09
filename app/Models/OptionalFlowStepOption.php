<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionalFlowStepOption extends Model
{
    protected $fillable = [
        'optional_flow_step_id',
        'source_type',
        'product_id',
        'category_id',
        'title',
        'description',
        'price',
        'base_price',
        'merchant_price',
        'is_active',
        'position',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'base_price' => 'decimal:2',
        'merchant_price' => 'decimal:2',
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
}
