<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Fonte única da comissão da plataforma (o `application_fee` que o Mercado Pago retém
 * pra Zapediu no split). Usada tanto na cobrança (createPix/createCardPayment) quanto na
 * exibição pro master — se a regra divergir entre os dois, o painel mostra um número e o
 * MP cobra outro.
 */
class PlatformFeeCalculator
{
    public function forOrder(Order $order): float
    {
        $total = (float) $order->total;

        if ($total <= 0) {
            return 0.0;
        }

        $plan = $order->company?->plan;

        if ($plan === null) {
            // Company sem plano vinculado: cai no padrão da plataforma em vez de zerar a
            // comissão silenciosamente (venda sem receita nenhuma passaria despercebida).
            $percent = (float) config('services.zapediu.default_fee_percent', 10);
            $fixed = 0.0;

            Log::warning('Pedido sem plano vinculado — usando taxa padrão da plataforma.', [
                'order_code' => $order->code,
                'company_id' => $order->company_id,
                'fee_percent' => $percent,
            ]);
        } else {
            $percent = (float) $plan->fee_percent;
            $fixed = (float) $plan->fee_fixed;
        }

        $fee = round($total * ($percent / 100) + $fixed, 2);

        // O Mercado Pago rejeita application_fee >= transaction_amount. Acontece com
        // pedido de valor baixo quando o plano tem fee_fixed (ex.: total 1,00 e fixo 2,00).
        if ($fee >= $total) {
            Log::warning('Comissão calculada >= total do pedido; limitada pra não quebrar a cobrança.', [
                'order_code' => $order->code,
                'total' => $total,
                'fee_calculada' => $fee,
            ]);

            $fee = round($total * 0.99, 2);
        }

        return max(0.0, $fee);
    }

    /**
     * O Mercado Pago aceita a requisição e **descarta em silêncio** o `application_fee`
     * quando o collector do pagamento é a própria aplicação (não existe split de si mesmo)
     * ou quando a conta não tem marketplace habilitado. Sem essa checagem a plataforma
     * roda meses achando que retém 10% de cada venda enquanto recebe zero.
     */
    public function assertFeeWasApplied(Order $order, float $expectedFee, mixed $payment): void
    {
        if ($expectedFee <= 0) {
            return;
        }

        $applied = $payment->application_fee ?? null;

        if ($applied === null || abs((float) $applied - $expectedFee) > 0.01) {
            Log::error('COMISSÃO NÃO RETIDA: o Mercado Pago ignorou o application_fee.', [
                'order_code' => $order->code,
                'company_id' => $order->company_id,
                'fee_esperada' => $expectedFee,
                'fee_aplicada' => $applied,
                'collector_id' => $payment->collector_id ?? null,
                'payment_id' => $payment->id ?? null,
                'causa_provavel' => 'pagamento criado com o access_token da própria aplicação '
                    .'em vez do access_token do lojista (OAuth), ou conta sem split habilitado',
            ]);
        }
    }
}
