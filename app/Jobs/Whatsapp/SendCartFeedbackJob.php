<?php

namespace App\Jobs\Whatsapp;

use App\Services\Zapi\Flows\CartFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendCartFeedbackJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $phone,
        private readonly int $nonce,
    ) {
    }

    public function handle(CartFlow $service): void
    {
        // Another add came in after us — that job's feedback will include everything
        $current = (int) Cache::get('zapi:feedback:nonce:'.$this->phone, 0);

        if ($current !== $this->nonce) {
            return;
        }

        $service->sendCartFeedbackNow($this->phone);
    }
}
