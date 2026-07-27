<?php

namespace App\Services\Whatsapp;

use App\Models\Courier;

class CourierConfirmationService
{
    public function send(Courier $courier, WhatsAppClientInterface $whatsapp): void
    {
        $groupName = $courier->deliveryGroup?->name ?? 'grupo de entregas';
        $message = "Olá {$courier->full_name}! 👋\n\nVocê foi cadastrado para o grupo *{$groupName}*.\n\nConfirme seu cadastro neste WhatsApp para ativar sua conta e começar a receber corridas.";

        $whatsapp->sendButtonActions($courier->phone, $message, [
            ['id' => "courier_confirm|{$courier->id}", 'label' => '✅ Confirmar cadastro'],
            ['id' => "courier_group|{$courier->id}", 'label' => '👥 Entrar no grupo'],
        ]);
    }

    public function confirm(string $phone, int $courierId, WhatsAppClientInterface $whatsapp): bool
    {
        $courier = Courier::query()->with('deliveryGroup')->find($courierId);

        if ($courier === null || $this->normalizePhone($courier->phone) !== $this->normalizePhone($phone)) {
            return false;
        }

        if ($courier->registration_status !== 'confirmed') {
            $courier->update([
                'registration_status' => 'confirmed',
                'status' => 'disponivel',
                'confirmed_at' => now(),
            ]);
        }

        $groupLink = $courier->deliveryGroup?->invite_url;
        $message = '✅ Cadastro confirmado! Você já está ativo para receber corridas.';
        if ($groupLink) {
            $message .= "\n\nEntre no grupo de entregas: {$groupLink}";
        }
        $whatsapp->sendText($courier->phone, $message);

        return true;
    }

    public function sendGroupLink(string $phone, int $courierId, WhatsAppClientInterface $whatsapp): bool
    {
        $courier = Courier::query()->with('deliveryGroup')->find($courierId);
        if ($courier === null || $this->normalizePhone($courier->phone) !== $this->normalizePhone($phone)) {
            return false;
        }

        $link = $courier->deliveryGroup?->invite_url;
        $whatsapp->sendText($courier->phone, $link
            ? "Entre no grupo *{$courier->deliveryGroup->name}*: {$link}"
            : 'O seu grupo ainda não possui link de convite. Fale com o administrador.');

        return true;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
