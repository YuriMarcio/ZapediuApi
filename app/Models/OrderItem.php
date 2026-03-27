<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'product_id',
        'product_name',
        'quantity',
        'unit_price',
        'line_total',
        'metadata',
    ];

    protected $casts = [
        'quantity'   => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'metadata'   => 'array',
    ];

    protected $appends = ['product_image_url'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function productImageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            return $this->product?->image_url;
        });
    }
}
