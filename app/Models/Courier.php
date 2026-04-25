<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Courier extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'first_name',
        'last_name',
        'full_name',
        'cpf',
        'email',
        'phone',
        'birth_date',
        'vehicle_type',
        'motorcycle_model',
        'license_plate',
        'cnh_number',
        'city',
        'state',
        'pix_key_type',
        'pix_key',
        'status',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
