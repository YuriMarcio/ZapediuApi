<?php

namespace App\Domain\Orders\States;

class OutForDeliveryOrderState implements OrderState
{
    public function value(): string
    {
        return 'out_for_delivery';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return in_array($nextState, ['delivered', 'cancelled'], true);
    }
}
