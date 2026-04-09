<?php

namespace App\Domain\Orders\States;

class OutForDeliveryOrderState implements OrderState
{
    public function value(): string
    {
        return 'delivering';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return in_array($nextState, ['done', 'cancelled'], true);
    }
}
