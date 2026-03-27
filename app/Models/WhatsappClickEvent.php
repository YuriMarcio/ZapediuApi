<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappClickEvent extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'order_id',
        'customer_phone',
        'button_payload',
        'intent',
        'converted',
        'payload',
        'clicked_at',
    ];

    protected $casts = [
        'converted' => 'boolean',
        'payload' => 'array',
        'clicked_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
