<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    protected $fillable = [
        'name',
        'trade_name',
        'legal_name',
        'document',
        'phone',
        'whatsapp',
        'seller_id',
        'manager_id',
        'operation_id',
        'plan_id',
        'slug',
        'segment',
        'api_token',
        'zapi_instance_id',
        'zapi_instance_token',
        'zapi_client_token',
        'zapi_webhook_token',
        'flowbridge_instance_id',
        'flowbridge_webhook_secret',
        'shipping_rules',
        'business_hours',
        'settings',
        'is_active',
        'is_open',
    ];

    protected $casts = [
        'shipping_rules' => 'array',
        'business_hours' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_open' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function operation(): BelongsTo { return $this->belongsTo(Operation::class); }
}
