<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariationGroup extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'required',
    ];

    protected $casts = [
        'required' => 'boolean',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(VariationGroupOption::class)->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('name');
    }
}