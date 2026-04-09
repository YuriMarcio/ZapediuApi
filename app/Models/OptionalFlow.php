<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class OptionalFlow extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'store_id',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OptionalFlowStep::class)->orderBy('position');
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'assignable', 'optional_flow_assignments');
    }

    public function categories(): MorphToMany
    {
        return $this->morphedByMany(Category::class, 'assignable', 'optional_flow_assignments');
    }
}
