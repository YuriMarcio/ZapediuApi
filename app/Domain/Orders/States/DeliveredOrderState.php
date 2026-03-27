<?php

namespace App\Domain\Orders\States;

class DeliveredOrderState implements OrderState
{
    public function value(): string
    {
        return 'delivered';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return false;
    }
}
