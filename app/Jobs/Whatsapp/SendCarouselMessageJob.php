<?php

namespace App\Jobs\Whatsapp;

use App\Services\Whatsapp\WhatsAppOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCarouselMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    public function __construct(
        private readonly int $companyId,
        private readonly string $phone,
        private readonly string $message,
        private readonly array $cards,
    ) {
    }

    public function handle(WhatsAppOrchestrator $orchestrator): void
    {
        $orchestrator->sendCarouselNow($this->companyId, $this->phone, $this->message, $this->cards);
    }
}
