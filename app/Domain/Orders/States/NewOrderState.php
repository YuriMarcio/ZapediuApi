<?php

namespace App\Domain\Orders\States;

class NewOrderState implements OrderState
{
    public function value(): string
    {
        return 'new';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return in_array($nextState, ['confirmed', 'cancelled'], true);
    }
}
