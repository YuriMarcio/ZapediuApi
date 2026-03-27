<?php

namespace App\Actions\Webhooks;

use App\DataTransferObjects\Whatsapp\ButtonClickData;
use App\Domain\Orders\OrderStateMachine;
use App\Models\Order;
use App\Models\WhatsappClickEvent;

class HandleButtonClickAction
{
    public function __construct(private readonly OrderStateMachine $stateMachine)
    {
    }

    public function execute(ButtonClickData $clickData): ?Order
    {
        $intent = $this->resolveIntent($clickData->payload);

        $order = null;

        if ($intent === 'buy') {
            $order = Order::query()
                ->when($clickData->customerPhone !== null, fn ($query) => $query->where('customer_phone', $clickData->customerPhone))
                ->latest('id')
                ->first();

            if ($order !== null) {
                $this->stateMachine->transition($order, 'confirmed');
                $order->increment('whatsapp_clicks');
            }
        }

        WhatsappClickEvent::query()->create([
            'order_id' => $order?->id,
            'customer_phone' => $clickData->customerPhone,
            'button_payload' => $clickData->payload,
            'intent' => $intent,
            'converted' => $order !== null,
            'payload' => $clickData->rawPayload,
            'clicked_at' => now(),
        ]);

        return $order;
    }

    private function resolveIntent(string $payload): string
    {
        if (str_contains($payload, 'comprar') || str_contains($payload, 'buy')) {
            return 'buy';
        }

        if (str_contains($payload, 'menu')) {
            return 'menu';
        }

        if (str_contains($payload, 'cart') || str_contains($payload, 'carrinho')) {
            return 'cart';
        }

        return 'unknown';
    }
}
