<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'provider',
        'external_id',
        'external_reference',
        'payment_status',
        'payment_type',
        'payment_method',
        'gross_amount',
        'net_received_amount',
        'platform_fee_amount',
        'seller_amount',
        'products_amount',
        'delivery_fee_amount',
        'delivery_mode',
        'plan_slug',
        'payout_mode',
        'checkout_url',
        'seller_release_at',
        'approved_at',
        'last_webhook_at',
        'raw_payload',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'net_received_amount' => 'decimal:2',
        'platform_fee_amount' => 'decimal:2',
        'seller_amount' => 'decimal:2',
        'products_amount' => 'decimal:2',
        'delivery_fee_amount' => 'decimal:2',
        'seller_release_at' => 'datetime',
        'approved_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}