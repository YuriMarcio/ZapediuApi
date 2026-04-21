<?php

namespace App\Services\Zapi\Flows;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlowManager
{
    private const CACHE_PREFIX = 'zapi:flow:state:';

    public function getState(string $phone): array
    {
        return Cache::get(self::CACHE_PREFIX . $phone, []);
    }

    public function saveState(string $phone, array $state): void
    {
        Cache::put(
            self::CACHE_PREFIX . $phone,
            $state,
            now()->addMinutes((int) config('services.zapi.flow_state_ttl_minutes', 180))
        );
    }

    public function resetState(string $phone): void
    {
        Cache::forget(self::CACHE_PREFIX . $phone);
    }

    public function normalize(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}