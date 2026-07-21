<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [[
            'slug'        => 'diario',
            'name'        => 'Plano Zapediu',
            'tagline'     => 'Receba as vendas todos os dias.',
            'pitch'       => 'Um plano único, simples e transparente para manter o caixa da loja girando diariamente.',
            'fee_percent' => 10.00,
            'fee_fixed'   => 0.00,
            'features'    => [
                'Recebimento diário de Pix e cartão.',
                'Taxa única de 10% sobre as vendas.',
                'Split automático e painel de gestão.',
                'Repasse de entregadores automático.',
            ],
            'is_active'   => true,
            'sort_order'  => 1,
        ]];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
