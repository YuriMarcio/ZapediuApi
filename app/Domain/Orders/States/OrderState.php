<?php

namespace App\Domain\Orders\States;

interface OrderState
{
    public function value(): string;

    public function canTransitionTo(string $nextState): bool;
}
