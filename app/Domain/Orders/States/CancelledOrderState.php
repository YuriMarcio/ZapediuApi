<?php

namespace App\Domain\Orders\States;

class CancelledOrderState implements OrderState
{
    public function value(): string
    {
        return 'cancelled';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return false;
    }
}
