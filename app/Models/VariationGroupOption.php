<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariationGroupOption extends Model
{
    protected $fillable = [
        'variation_group_id',
        'name',
        'price',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function variationGroup(): BelongsTo
    {
        return $this->belongsTo(VariationGroup::class);
    }
}