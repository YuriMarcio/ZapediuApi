<?php

namespace App\Services\Zapi\Flows;

class Greeting extends BaseFlow
{
    private function sendWelcomePrompt(string $phone): bool
    {
        $message = "Olá! 👋 Bem-vindo ao Zapediu!\n\nEstou aqui para matar a sua fome em poucos segundos. 🛵💨\n\nVocê pode simplesmente me dizer o que quer comer, por exemplo:\n🍔 \"Quero um hambúrguer\"\n🍕 \"Me mostre as pizzarias\"\n🏪 \"Cardápio do Pastel do Zeca\"\n\nOu, se preferir, escolha uma das opções abaixo:";
        $fallbackMessage = "Olá! 👋 Bem-vindo ao Zapediu!\n\nEstou aqui para matar a sua fome em poucos segundos.\n\nVocê pode me dizer o que quer comer, por exemplo:\n- Quero um hambúrguer\n- Me mostre as pizzarias\n- Cardápio do Pastel do Zeca\n\nOu digite:\n- ver lojas\n- ver categorias\n- como funciona";

        $state = $this->flowState($phone);
        $state['welcomed'] = true;
        $this->saveFlowState($phone, $state);

        try {
            $this->zapiClient->sendButtonActions($phone, $message, [
                ['id' => 'btn_ver_lojas', 'label' => '🏪 Ver Lojas'],
                ['id' => 'btn_ver_categorias', 'label' => '🍔 Ver Categorias'],
                ['id' => 'btn_como_funciona', 'label' => '❓ Como funciona'],
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Failed to send Z-API welcome prompt.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            try {
                $this->zapiClient->sendText($phone, $fallbackMessage);

                return true;
            } catch (\Throwable $fallbackException) {
                Log::warning('Failed to send Z-API welcome prompt fallback.', [
                    'phone' => $phone,
                    'error' => $fallbackException->getMessage(),
                ]);

                return false;
            }
        }
    }
}
