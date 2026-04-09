<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'plan_id',
        'slug',
        'segment',
        'api_token',
        'zapi_instance_id',
        'zapi_instance_token',
        'zapi_client_token',
        'zapi_webhook_token',
        'shipping_rules',
        'business_hours',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'shipping_rules' => 'array',
        'business_hours' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
