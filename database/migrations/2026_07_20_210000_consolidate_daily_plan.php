<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $dailyPlan = [
            'slug' => 'diario',
            'name' => 'Plano Zapediu',
            'tagline' => 'Receba as vendas todos os dias.',
            'pitch' => 'Um plano único, simples e transparente para manter o caixa da loja girando diariamente.',
            'fee_percent' => 10.00,
            'fee_fixed' => 0.00,
            'features' => json_encode([
                'Recebimento diário de Pix e cartão.',
                'Taxa única de 10% sobre as vendas.',
                'Split automático e painel de gestão.',
                'Repasse de entregadores automático.',
            ]),
            'is_active' => true,
            'sort_order' => 1,
            'updated_at' => now(),
        ];

        $existingPlan = DB::table('plans')->where('slug', 'diario')->first();
        if ($existingPlan === null) {
            $dailyPlan['created_at'] = now();
            $dailyPlanId = DB::table('plans')->insertGetId($dailyPlan);
        } else {
            DB::table('plans')->where('id', $existingPlan->id)->update($dailyPlan);
            $dailyPlanId = $existingPlan->id;
        }

        DB::table('companies')->where('plan_id', '!=', $dailyPlanId)->update(['plan_id' => $dailyPlanId]);

        if (Schema::hasTable('wallets')) {
            DB::table('wallets')->where('plan_id', '!=', $dailyPlanId)->update(['plan_id' => $dailyPlanId]);
        }

        DB::table('plans')->where('id', '!=', $dailyPlanId)->delete();
    }

    public function down(): void
    {
    }
};
