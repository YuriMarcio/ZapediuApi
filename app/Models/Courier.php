<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Courier extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'operation_id',
        'delivery_group_id',
        'last_name',
        'full_name',
        'cpf',
        'email',
        'phone',
        'profile_image_path',
        'birth_date',
        'vehicle_type',
        'motorcycle_model',
        'license_plate',
        'cnh_number',
        'driver_license_path',
        'city',
        'state',
        'pix_key_type',
        'pix_key',
        'status',
        'registration_status',
        'confirmed_at',
    ];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryGroup(): BelongsTo
    {
        return $this->belongsTo(DeliveryGroup::class);
    }
}
