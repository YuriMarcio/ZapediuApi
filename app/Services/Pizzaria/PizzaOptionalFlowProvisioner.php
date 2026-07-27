<?php

namespace App\Services\Pizzaria;

use App\Models\OptionalFlow;
use App\Models\Store;

class PizzaOptionalFlowProvisioner
{
    private const DEFAULTS = [
        'borda' => ['name' => 'Bordas recheadas', 'step_title' => 'Escolha a borda'],
        'ingrediente' => ['name' => 'Ingredientes adicionais', 'step_title' => 'Ingredientes extras'],
        'molho' => ['name' => 'Molhos', 'step_title' => 'Escolha o molho'],
    ];

    /**
     * Garante que a loja tenha os 3 fluxos (bordas/ingredientes/molhos), criando o que faltar.
     * Idempotente — seguro chamar em toda requisição.
     *
     * @return array<string, OptionalFlow> chaveado por feature_type
     */
    public function ensureFlowsForStore(Store $store): array
    {
        $flows = [];

        foreach (array_keys(self::DEFAULTS) as $featureType) {
            $flows[$featureType] = $this->ensureFlow($store, $featureType);
        }

        return $flows;
    }

    public function ensureFlow(Store $store, string $featureType): OptionalFlow
    {
        $flow = OptionalFlow::query()
            ->where('store_id', $store->id)
            ->forFeature($featureType)
            ->with('steps.options')
            ->first();

        if ($flow !== null) {
            return $flow;
        }

        $defaults = self::DEFAULTS[$featureType];

        $flow = OptionalFlow::query()->create([
            'company_id' => $store->company_id,
            'store_id' => $store->id,
            'feature_type' => $featureType,
            'name' => $defaults['name'],
            'is_active' => true,
        ]);

        $flow->steps()->create([
            'title' => $defaults['step_title'],
            'is_required' => false,
            'charge_type' => 'paid',
            'min_select' => 0,
            'max_select' => 1,
            'position' => 1,
        ]);

        return $flow->load('steps.options');
    }

    public function flowForFeature(Store $store, string $featureType): OptionalFlow
    {
        return $this->ensureFlow($store, $featureType);
    }
}
