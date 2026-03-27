<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    protected $fillable = [
        'store_id',
        'external_id',
        'order_code',
        'customer_name',
        'customer_phone',
        'address',
        'status',
        'total_amount',
        'source',
        'last_update_at',
        'raw_payload',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'last_update_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
