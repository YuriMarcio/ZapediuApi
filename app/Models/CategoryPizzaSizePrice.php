<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryPizzaSizePrice extends Model
{
    protected $fillable = [
        'category_id',
        'store_pizza_size_id',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function storePizzaSize(): BelongsTo
    {
        return $this->belongsTo(StorePizzaSize::class);
    }
}
