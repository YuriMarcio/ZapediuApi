<?php

namespace App\Domain\Orders\States;

class DeliveredOrderState implements OrderState
{
    public function value(): string
    {
        return 'done';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return false;
    }
}
