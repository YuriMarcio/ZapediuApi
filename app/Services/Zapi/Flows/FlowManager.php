<?php

namespace App\Services\Zapi\Flows;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FlowManager
{
    // Prefixo usado para todas as chaves de cache do estado do fluxo
    private const CACHE_PREFIX = 'zapi:flow:state:';

    /**
     * Salva o estado do fluxo para um telefone.
     * Alias para manter compatibilidade com chamadores antigos (GreetingFlow, etc).
     */
    public function save(string $phone, array $state): void
    {
        $this->saveState($phone, $state);
    }

    /**
     * Recupera o estado salvo do fluxo para um telefone.
     * Retorna um array vazio se não houver estado salvo.
     */
    public function getState(string $phone): array
    {
        return Cache::get($this->flowStateCacheKey($phone), []);
    }

    /**
     * Salva o estado do fluxo para um telefone, com tempo de expiração configurável.
     * O TTL (em minutos) é definido em services.zapi.flow_state_ttl_minutes (padrão: 180).
     */
    public function saveState(string $phone, array $state): void
    {
        $ttl = (int) config('services.zapi.flow_state_ttl_minutes', 180);
        
        Cache::put(
            $this->flowStateCacheKey($phone), // chave única por telefone
            $state,                           // dados do estado (array)
            now()->addMinutes($ttl)           // tempo de expiração
        );
    }

    /**
     * Remove o estado salvo do fluxo para um telefone (reset da sessão).
     */
    public function resetState(string $phone): void
    {
        Cache::forget($this->flowStateCacheKey($phone));
    }

    /**
     * Normaliza um texto: deixa minúsculo, remove acentos, espaços extras, etc.
     * Útil para comparar comandos/textos do usuário.
     */
    public function normalize(string $text): string
    {
        return Str::of($text)
            ->lower() // minúsculo
            ->ascii() // remove acentos/caracteres especiais
            ->replaceMatches('/\s+/', ' ') // espaços múltiplos para um só
            ->trim() // remove espaços nas pontas
            ->toString();
    }

    /**
     * Gera a chave de cache única para o telefone informado.
     * Centraliza a lógica para evitar duplicidade de prefixos.
     */
    private function flowStateCacheKey(string $phone): string
    {
        return self::CACHE_PREFIX . $phone;
    }
}