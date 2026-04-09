<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'icon',
        'slug',
        'image_url',
        'ordem_exibicao',
        'color',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'ordem_exibicao' => 'integer',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (self $category): void {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::updating(function (self $category): void {
            if ($category->isDirty('name')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function optionalFlows(): MorphToMany
    {
        return $this->morphToMany(OptionalFlow::class, 'assignable', 'optional_flow_assignments');
    }
}
