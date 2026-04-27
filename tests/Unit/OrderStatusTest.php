<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Order;

class OrderStatusTest extends TestCase
{
    public function test_atualiza_status_pedido_real_73()
    {
        $order = Order::find(73);
        $this->assertNotNull($order, 'Pedido 73 não encontrado');

        $order->status = 'preparToDelivery';
        $order->save();
        $this->assertEquals('preparToDelivery', $order->fresh()->status);

        $order->status = 'pending';
        $order->save();
        $this->assertEquals('pending', $order->fresh()->status);
    }
    
    public function test_atualiza_status_para_preparToDelivery()
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $order->status = 'preparToDelivery';
        $order->save();

        $this->assertEquals('preparToDelivery', $order->fresh()->status);
    }

    public function test_atualiza_status_para_pending()
    {
        $order = Order::factory()->create(['status' => 'preparToDelivery']);
        $order->status = 'pending';
        $order->save();

        $this->assertEquals('pending', $order->fresh()->status);
    }
}
