<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use BelongsToCompany;
    use InteractsWithMedia;

    protected $fillable = [
        'company_id',
        'store_id',
        'category_id',
        'selection_group_id',
        'variation_group_id',
        'name',
        'sku',
        'description',
        'category',
        'image_path',
        'price',
        'promotional_price',
        'stock_quantity',
        'track_stock',
        'is_active',
        'has_variations',
        'variation_question',
        'available_for_combo',
        'is_combo',
        'combo_units',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'promotional_price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'track_stock' => 'boolean',
        'is_active' => 'boolean',
        'has_variations' => 'boolean',
        'available_for_combo' => 'boolean',
        'is_combo' => 'boolean',
        'combo_units' => 'array',
    ];

    protected $hidden = [
        'image_url', // Hide from serialization to prevent infinite recursion
    ];

    protected $appends = [
        // 'image_url', // Temporarily removed to debug infinite recursion
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('products')
            ->useDisk('r2'); // ou o disk correto
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Ativo e (sem controle de estoque OU com saldo positivo). `track_stock=false` é o
     * default pra não esconder produtos que o lojista nunca configurou estoque.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where(function (Builder $q): void {
            $q->where('track_stock', false)->orWhere('stock_quantity', '>', 0);
        });
    }

    /**
     * @return array<int, array{product_id: int, variation_id: int|null, label: string|null}>
     */
    public function comboUnits(): array
    {
        $units = $this->combo_units;

        return is_array($units) ? $units : [];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function selectionGroup(): BelongsTo
    {
        return $this->belongsTo(SelectionGroup::class);
    }

    public function variationGroup(): BelongsTo
    {
        return $this->belongsTo(VariationGroup::class);
    }

    public function variations(): HasMany
    {
        return $this->hasMany(ProductVariation::class);
    }

    public function optionalFlows(): MorphToMany
    {
        return $this->morphToMany(OptionalFlow::class, 'assignable', 'optional_flow_assignments');
    }

    /**
     * Vínculo item-a-item de opcionais (bordas/ingredientes/molhos) para lojas pizzaria —
     * distinto de optionalFlows(), que vincula o fluxo inteiro. Ex.: uma pizza de chocolate
     * pode só permitir bordas doces, mesmo que a loja tenha outras bordas cadastradas.
     */
    public function optionalFlowStepOptions(): BelongsToMany
    {
        return $this->belongsToMany(
            OptionalFlowStepOption::class,
            'product_optional_flow_step_options',
        );
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            // Temporarily return null to avoid infinite recursion
            // The real implementation will be restored after fixing the media library issue
            return null;
            
            // Original implementation (commented out):
            // $whatsapp = $this->getFirstMediaUrl('products', 'whatsapp');
            // if ($whatsapp !== '') {
            //     return $whatsapp;
            // }
            //
            // $fromMedia = $this->getFirstMediaUrl('products');
            // if ($fromMedia !== '') {
            //     return str_starts_with($fromMedia, 'http') ? $fromMedia : url($fromMedia);
            // }
            //
            // if ($this->image_path === null || $this->image_path === '') {
            //     return null;
            // }
            //
            // if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            //     return $this->image_path;
            // }
            //
            // return url('/storage/'.ltrim($this->image_path, '/'));
        });
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('whatsapp')
            ->width(800)
            ->format('webp')
            ->quality(75);
    }
}
