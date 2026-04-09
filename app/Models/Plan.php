<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'tagline',
        'pitch',
        'fee_percent',
        'fee_fixed',
        'features',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'features'  => 'array',
        'is_active' => 'boolean',
        'fee_percent' => 'decimal:2',
        'fee_fixed'   => 'decimal:2',
    ];

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
