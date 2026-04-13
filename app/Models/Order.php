<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'store_id',
        'product_ids',
        'delivery_id',
        'code',
        'status',
        'payment_status',
        'payment_method',
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
        'product_ids'        => 'array',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * Year of the first order placed by the same customer phone, or this
     * order's year if no phone is recorded. Used for "Cliente desde XXXX".
     */
    protected function customerSinceYear(): Attribute
    {
        return Attribute::get(function (): int {
            $phone = $this->user?->primaryPhone?->phone ?: $this->user?->phone;
            $fallbackDate = $this->ordered_at ?? $this->created_at ?? now();

            if ($phone === null || $phone === '') {
                return (int) $fallbackDate->format('Y');
            }

            /** @var self|null $first */
            $first = self::withoutGlobalScopes()
                ->where('company_id', $this->company_id)
                ->whereHas('user', function ($query) use ($phone): void {
                    $query->where('phone', $phone)
                        ->orWhereHas('phones', fn ($phoneQuery) => $phoneQuery->where('phone', $phone));
                })
                ->orderBy('ordered_at')
                ->orderBy('id')
                ->first(['ordered_at', 'created_at']);

            $date = $first?->ordered_at ?? $first?->created_at ?? $fallbackDate;

            return (int) $date->format('Y');
        });
    }

        public function getRouteKeyName()
    {
        return 'code';
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
