<?php

namespace App\Domain\Orders\States;

class PreparingOrderState implements OrderState
{
    public function value(): string
    {
        return 'preparing';
    }

    public function canTransitionTo(string $nextState): bool
    {
        return in_array($nextState, ['out_for_delivery', 'cancelled'], true);
    }
}
