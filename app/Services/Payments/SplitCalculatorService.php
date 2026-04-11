<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\Order;
use App\Models\Plan;
use Carbon\CarbonInterface;

class SplitCalculatorService
{
    public const START_ADVANCE_FEE_PERCENT = 3.5;

    /**
     * @return array<string, mixed>
     */
    public function calculateForOrder(Order $order, string $deliveryMode = 'store'): array
    {
        /** @var Company|null $company */
        $company = $order->relationLoaded('company') ? $order->company : $order->company()->with('plan')->first();

        return $this->calculateFromAmounts(
            (float) $order->subtotal,
            (float) $order->delivery_fee,
            $deliveryMode,
            $company?->plan,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function calculateFromAmounts(float $productsAmount, float $deliveryFeeAmount, string $deliveryMode, ?Plan $plan): array
    {
        $deliveryMode = $deliveryMode === 'platform' ? 'platform' : 'store';
        $productsAmount = round(max(0, $productsAmount), 2);
        $deliveryFeeAmount = round(max(0, $deliveryFeeAmount), 2);

        $feePercent = (float) ($plan?->fee_percent ?? 0);
        $feeFixed = (float) ($plan?->fee_fixed ?? 0);
        $commissionOnProducts = round($productsAmount * ($feePercent / 100), 2);
        $applicationFee = round($commissionOnProducts + $feeFixed, 2);

        $platformAmount = $applicationFee;
        $sellerAmount = round($productsAmount - $applicationFee, 2);

        if ($deliveryMode === 'platform') {
            $platformAmount = round($platformAmount + $deliveryFeeAmount, 2);
        } else {
            $sellerAmount = round($sellerAmount + $deliveryFeeAmount, 2);
        }

        return [
            'plan_slug' => $plan?->slug,
            'fee_percent' => $feePercent,
            'fee_fixed' => round($feeFixed, 2),
            'products_amount' => $productsAmount,
            'delivery_fee_amount' => $deliveryFeeAmount,
            'delivery_mode' => $deliveryMode,
            'commission_on_products' => $commissionOnProducts,
            'application_fee' => max(0, round($applicationFee, 2)),
            'platform_amount' => max(0, round($platformAmount, 2)),
            'seller_amount' => max(0, round($sellerAmount, 2)),
            'total_amount' => round($productsAmount + $deliveryFeeAmount, 2),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function quoteAdvance(float $amount): array
    {
        $amount = round(max(0, $amount), 2);
        $feeAmount = round($amount * (self::START_ADVANCE_FEE_PERCENT / 100), 2);

        return [
            'amount_requested' => $amount,
            'fee_percent' => self::START_ADVANCE_FEE_PERCENT,
            'fee_amount' => $feeAmount,
            'net_amount' => round(max(0, $amount - $feeAmount), 2),
        ];
    }

    public function resolveSellerReleaseAt(?Plan $plan, string $paymentType, ?CarbonInterface $approvedAt = null): CarbonInterface
    {
        $approvedAt ??= now();

        if ($paymentType === 'pix') {
            return $approvedAt;
        }

        if ($plan?->slug === 'turbo') {
            return $approvedAt;
        }

        return $approvedAt->copy()->addDays(30);
    }

    public function resolvePayoutMode(?Plan $plan, string $paymentType): string
    {
        if ($paymentType === 'pix' || $plan?->slug === 'turbo') {
            return 'd0';
        }

        return 'd30';
    }
}