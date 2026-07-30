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
        // 'preparToDelivery' é o passo em que a loja termina o preparo e o pedido é
        // transmitido ao grupo de entregadores (OrderObserver). 'delivering' continua
        // permitido porque é o motoboy que aceita a corrida a partir daí.
        return in_array($nextState, ['preparToDelivery', 'delivering', 'cancelled'], true);
    }
}
