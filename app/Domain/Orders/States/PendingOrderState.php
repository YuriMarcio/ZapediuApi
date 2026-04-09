<?php

namespace App\Domain\Orders\States;

class PendingOrderState implements OrderState
{
    public function value(): string
    {
        return 'pending';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return in_array($nextState, ['accepted', 'cancelled'], true);
    }
}