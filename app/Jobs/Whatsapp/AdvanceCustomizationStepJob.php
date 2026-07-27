<?php

namespace App\Jobs\Whatsapp;

use App\Services\Zapi\Flows\CartFlow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class AdvanceCustomizationStepJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $phone,
        private readonly int $nonce,
    ) {
    }

    public function handle(CartFlow $service): void
    {
        // Another tap came in after us — that job's advance will pick up everything.
        $current = (int) Cache::get('zapi:customization:nonce:'.$this->phone, 0);

        if ($current !== $this->nonce) {
            return;
        }

        $service->advanceCustomizationStep($this->phone);
    }
}
