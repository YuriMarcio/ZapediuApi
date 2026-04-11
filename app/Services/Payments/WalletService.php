<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\WalletAdvance;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletService
{
    public function __construct(
        private readonly SplitCalculatorService $splitCalculator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Company $company): array
    {
        $company->loadMissing('plan');

        $transactions = PaymentTransaction::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('provider', 'mercado_pago')
            ->where('payment_status', 'approved');

        $now = now();

        $pixAvailable = (float) (clone $transactions)
            ->where('payment_type', 'pix')
            ->sum('seller_amount');

        $cardReleased = (float) (clone $transactions)
            ->where('payment_type', 'credit_card')
            ->whereNotNull('seller_release_at')
            ->where('seller_release_at', '<=', $now)
            ->sum('seller_amount');

        $cardPending = (float) (clone $transactions)
            ->where('payment_type', 'credit_card')
            ->where(function ($query) use ($now): void {
                $query->whereNull('seller_release_at')
                    ->orWhere('seller_release_at', '>', $now);
            })
            ->sum('seller_amount');

        $reservedAdvances = (float) WalletAdvance::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('status', ['requested', 'approved'])
            ->sum('amount_requested');

        $advanceAvailable = $company->plan?->slug === 'start'
            ? round(max(0, $cardPending - $reservedAdvances), 2)
            : 0.0;

        return [
            'plan' => [
                'slug' => $company->plan?->slug,
                'name' => $company->plan?->name,
            ],
            'balances' => [
                'pix_available' => round($pixAvailable, 2),
                'card_available' => round($cardReleased, 2),
                'card_pending_release' => round($cardPending, 2),
                'advance_available' => round($advanceAvailable, 2),
            ],
            'advance' => [
                'enabled' => $company->plan?->slug === 'start',
                'fee_percent' => SplitCalculatorService::START_ADVANCE_FEE_PERCENT,
            ],
        ];
    }

    public function requestAdvance(Company $company, User $user, float $amount, ?string $notes, ?Request $request = null): WalletAdvance
    {
        $summary = $this->summary($company);

        if (($summary['advance']['enabled'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'amount' => ['Antecipacao disponivel apenas para o plano Start.'],
            ]);
        }

        $available = (float) ($summary['balances']['advance_available'] ?? 0);

        if ($amount <= 0 || $amount > $available) {
            throw ValidationException::withMessages([
                'amount' => ['Valor solicitado indisponivel para antecipacao.'],
            ]);
        }

        $quote = $this->splitCalculator->quoteAdvance($amount);

        $advance = WalletAdvance::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'amount_requested' => $quote['amount_requested'],
            'fee_percent' => $quote['fee_percent'],
            'fee_amount' => $quote['fee_amount'],
            'net_amount' => $quote['net_amount'],
            'status' => 'requested',
            'metadata' => [
                'notes' => $notes,
            ],
        ]);

        $this->auditLogger->log('wallet.advance.requested', [
            'company_id' => $company->id,
            'user_id' => $user->id,
            'entity_type' => WalletAdvance::class,
            'entity_id' => $advance->id,
            'metadata' => [
                'amount_requested' => $quote['amount_requested'],
                'fee_amount' => $quote['fee_amount'],
                'net_amount' => $quote['net_amount'],
            ],
        ], $request);

        return $advance;
    }
}