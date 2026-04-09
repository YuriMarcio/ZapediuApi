<?php

namespace App\Domain\Orders\States;

class ConfirmedOrderState implements OrderState
{
    public function value(): string
    {
        return 'accepted';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return in_array($nextState, ['preparing', 'cancelled'], true);
    }
}
