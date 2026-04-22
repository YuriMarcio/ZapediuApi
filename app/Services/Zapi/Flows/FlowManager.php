<?php

namespace App\Services\Zapi\Flows;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlowManager
{
    private const CACHE_PREFIX = 'zapi:flow:state:';

    /**
     * Alias para manter compatibilidade com chamadores antigos (GreetingFlow, etc)
     */
    public function save(string $phone, array $state): void
    {
        $this->saveState($phone, $state);
    }

    public function getState(string $phone): array
    {
        return Cache::get($this->flowStateCacheKey($phone), []);
    }

    public function saveState(string $phone, array $state): void
    {
        $ttl = (int) config('services.zapi.flow_state_ttl_minutes', 180);
        
        Cache::put(
            $this->flowStateCacheKey($phone),
            $state,
            now()->addMinutes($ttl)
        );
    }

    public function resetState(string $phone): void
    {
        Cache::forget($this->flowStateCacheKey($phone));
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

    /**
     * Centraliza a geração da chave de cache
     */
    private function flowStateCacheKey(string $phone): string
    {
        return self::CACHE_PREFIX . $phone;
    }
}