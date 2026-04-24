<?php

namespace App\Jobs\Whatsapp;

use App\Actions\Webhooks\ProcessIncomingWebhookAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessZapiWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 10;
    
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly ?int $companyId,
        private readonly array $payload,
    ) {
    }

    public function handle(ProcessIncomingWebhookAction $action): void
    {
        $action->execute($this->payload, $this->companyId);
    }
}
