<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'store_id',
        'delivery_id',
        'code',
        'channel',
        'whatsapp_clicks',
        'status',
        'payment_status',
        'payment_method',
        'customer_name',
        'customer_phone',
        'customer_address',
        'subtotal',
        'delivery_fee',
        'discount',
        'total',
        'notes',
        'rejection_reason',
        'ordered_at',
        'estimated_ready_at',
        'raw_payload',
    ];

    protected $casts = [
        'whatsapp_clicks'    => 'integer',
        'subtotal'           => 'decimal:2',
        'delivery_fee'       => 'decimal:2',
        'discount'           => 'decimal:2',
        'total'              => 'decimal:2',
        'ordered_at'         => 'datetime',
        'estimated_ready_at' => 'datetime',
        'raw_payload'        => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * Year of the first order placed by the same customer_phone, or this
     * order's year if no phone is recorded. Used for "Cliente desde XXXX".
     */
    protected function customerSinceYear(): Attribute
    {
        return Attribute::get(function (): int {
            if ($this->customer_phone === null || $this->customer_phone === '') {
                return (int) ($this->ordered_at ?? $this->created_at)->format('Y');
            }

            /** @var self|null $first */
            $first = self::withoutGlobalScopes()
                ->where('company_id', $this->company_id)
                ->where('customer_phone', $this->customer_phone)
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->first(['ordered_at', 'created_at']);

            $date = $first?->ordered_at ?? $first?->created_at ?? $this->created_at;

            return (int) $date->format('Y');
        });
    }

    /**
     * Remaining seconds until estimated_ready_at (null if not set or past).
     */
    protected function remainingSeconds(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->estimated_ready_at === null) {
                return null;
            }

            $diff = (int) now()->diffInSeconds($this->estimated_ready_at, false);

            return $diff > 0 ? $diff : 0;
        });
    }

    protected $appends = ['customer_since_year', 'remaining_seconds'];
}
