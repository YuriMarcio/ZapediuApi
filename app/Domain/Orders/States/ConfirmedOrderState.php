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
        // Inclui 'preparToDelivery' pra loja poder marcar como pronto direto de 'accepted',
        // sem passar obrigatoriamente por 'preparing' (pedido simples fica pronto na hora).
        return in_array($nextState, ['preparing', 'preparToDelivery', 'delivering', 'cancelled'], true);
    }
}
