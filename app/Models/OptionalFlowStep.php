<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OptionalFlowStep extends Model
{
    protected $fillable = [
        'optional_flow_id',
        'title',
        'description',
        'trigger_when',
        'customer_hint',
        'items_source',
        'allow_price_override',
        'is_required',
        'charge_type',
        'min_select',
        'max_select',
        'position',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'allow_price_override' => 'boolean',
        'min_select' => 'integer',
        'max_select' => 'integer',
        'position' => 'integer',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(OptionalFlow::class, 'optional_flow_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(OptionalFlowStepOption::class)->orderBy('position');
    }
}
