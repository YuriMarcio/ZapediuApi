<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OptionalFlowStepOptionSize extends Model
{
    protected $fillable = [
        'optional_flow_step_option_id',
        'store_pizza_size_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function option(): BelongsTo
    {
        return $this->belongsTo(OptionalFlowStepOption::class, 'optional_flow_step_option_id');
    }

    public function storePizzaSize(): BelongsTo
    {
        return $this->belongsTo(StorePizzaSize::class);
    }
}
