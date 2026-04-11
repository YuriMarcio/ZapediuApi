<?php

namespace Tests\Unit\Payments;

use App\Models\Company;
use App\Models\Order;
use App\Models\Plan;
use App\Services\Payments\SplitCalculatorService;
use PHPUnit\Framework\TestCase;

class SplitCalculatorServiceTest extends TestCase
{
    public function test_it_calculates_turbo_split_with_store_delivery(): void
    {
        $service = new SplitCalculatorService();
        $plan = new Plan([
            'slug' => 'turbo',
            'fee_percent' => 9.5,
            'fee_fixed' => 1.00,
        ]);
        $company = new Company();
        $company->setRelation('plan', $plan);
        $order = new Order([
            'subtotal' => 100.00,
            'delivery_fee' => 10.00,
            'total' => 110.00,
        ]);
        $order->setRelation('company', $company);

        $result = $service->calculateForOrder($order, 'store');

        $this->assertSame(10.50, $result['application_fee']);
        $this->assertSame(10.50, $result['platform_amount']);
        $this->assertSame(99.50, $result['seller_amount']);
    }

    public function test_it_quotes_start_advance_fee(): void
    {
        $service = new SplitCalculatorService();

        $result = $service->quoteAdvance(100.00);

        $this->assertSame(3.5, $result['fee_percent']);
        $this->assertSame(3.50, $result['fee_amount']);
        $this->assertSame(96.50, $result['net_amount']);
    }
}