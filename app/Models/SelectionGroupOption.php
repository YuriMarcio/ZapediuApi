<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelectionGroupOption extends Model
{
    protected $fillable = [
        'selection_group_id',
        'label',
        'description',
        'price',
        'position',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    public function selectionGroup(): BelongsTo
    {
        return $this->belongsTo(SelectionGroup::class);
    }
}