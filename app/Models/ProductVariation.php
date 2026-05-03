<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'product_id',
        'name',
        'sku',
        'price',
        'additional_price',
        'stock_quantity',
        'attributes',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'additional_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'attributes' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'product',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
