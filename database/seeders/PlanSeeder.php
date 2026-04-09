<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug'        => 'start',
                'name'        => 'Start',
                'tagline'     => 'Maximize seu lucro pagando a menor taxa.',
                'pitch'       => 'Focado no lojista que tem caixa saudável e prefere pagar a menor taxa possível, usando o Pix para o giro diário e o Cartão como lucro no fim do mês.',
                'fee_percent' => 7.90,
                'fee_fixed'   => 1.00,
                'features'    => [
                    'Recebimento de Pix na hora (D+0).',
                    'Recebimento de Cartão em 30 dias.',
                    'Opção de antecipar o cartão avulso quando precisar.',
                    'Split automático e painel de gestão.',
                    'Repasse de entregadores automático.',
                ],
                'is_active'   => true,
                'sort_order'  => 1,
            ],
            [
                'slug'        => 'turbo',
                'name'        => 'Turbo',
                'tagline'     => 'Dinheiro na mão na hora, chova ou faça sol.',
                'pitch'       => 'Focado no lojista que precisa do dinheiro girando todo dia para comprar mercadoria e não quer se preocupar com taxas surpresas de antecipação.',
                'fee_percent' => 9.50,
                'fee_fixed'   => 1.00,
                'features'    => [
                    'Tudo do plano Start, mais:',
                    'Recebimento de Cartão na hora (D+0).',
                    'Zero taxa surpresa de antecipação.',
                    'Fluxo de caixa 100% livre todos os dias.',
                ],
                'is_active'   => true,
                'sort_order'  => 2,
            ],
        ];

        foreach ($plans as $data) {
            Plan::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
