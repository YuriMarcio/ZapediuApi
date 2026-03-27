<?php

namespace App\Domain\Orders;

use App\Domain\Orders\States\CancelledOrderState;
use App\Domain\Orders\States\ConfirmedOrderState;
use App\Domain\Orders\States\DeliveredOrderState;
use App\Domain\Orders\States\NewOrderState;
use App\Domain\Orders\States\OrderState;
use App\Domain\Orders\States\OutForDeliveryOrderState;
use App\Domain\Orders\States\PreparingOrderState;
use App\Models\Order;

class OrderStateMachine
{
    public function transition(Order $order, string $targetState): bool
    {
        $state = $this->resolveState((string) $order->status);

        if (! $state->canTransitionTo($targetState)) {
            return false;
        }

        $order->status = $targetState;
        $order->save();

        return true;
    }

    private function resolveState(string $value): OrderState
    {
        return match ($value) {
            'confirmed' => new ConfirmedOrderState(),
            'preparing' => new PreparingOrderState(),
            'out_for_delivery' => new OutForDeliveryOrderState(),
            'delivered' => new DeliveredOrderState(),
            'cancelled' => new CancelledOrderState(),
            default => new NewOrderState(),
        };
    }
}
