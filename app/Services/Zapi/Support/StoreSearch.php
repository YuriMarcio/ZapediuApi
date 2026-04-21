<?php

namespace App\Services\Zapi\Support;

use App\Models\Store;
use Illuminate\Support\Str;

class StoreSearch
{
    public function byQuery(string $query): array
    {
        $normalized = trim((string) Str::of($query)->lower()->ascii()->toString());
        if ($normalized === '') return [];

        $tokens = array_values(array_filter(explode(' ', $normalized)));
        $storesQuery = Store::query()->where('is_active', true);

        foreach ($tokens as $token) {
            $storesQuery->where(function ($builder) use ($token) {
                $like = '%' . $token . '%';
                $builder->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereHas('category', fn($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like]));
            });
        }

        return $storesQuery->orderBy('name')->pluck('slug')->all();
    }
}