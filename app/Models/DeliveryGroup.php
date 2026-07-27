<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryGroup extends Model
{
    protected $fillable = ['operation_id', 'name', 'whatsapp_group_jid', 'invite_url', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
