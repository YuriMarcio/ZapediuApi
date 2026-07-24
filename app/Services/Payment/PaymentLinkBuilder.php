<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Str;

class PaymentLinkBuilder
{
    public function forOrder(Order $order): string
    {
        $token = '';
        if (is_array($order->raw_payload) && isset($order->raw_payload['checkout']['public_token'])) {
            $token = (string) $order->raw_payload['checkout']['public_token'];
        }

        if ($token === '') {
            $token = Str::random(32);
        }

        return $this->buildLink((string) $order->code, $token);
    }

    public function forOrderCode(?string $orderCode): string
    {
        $orderCodePath = $orderCode ?? Str::ulid()->toBase32();

        return $this->buildLink($orderCodePath, Str::random(32));
    }

    private function buildLink(string $orderCodePath, string $token): string
    {
        $base = trim((string) config('services.zapi.payment_base_url', 'https://localhost:5173/checkout'));
        if ($base === '') {
            $base = 'https://localhost:5173/checkout';
        }

        return rtrim($base, '/').'/'.$orderCodePath.'?token='.$token;
    }
}
