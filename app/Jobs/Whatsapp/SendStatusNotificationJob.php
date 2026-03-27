<?php

namespace App\Jobs\Whatsapp;

use App\Services\Whatsapp\WhatsAppOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendStatusNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string>  $templateData
     */
    public function __construct(
        private readonly int $companyId,
        private readonly string $phone,
        private readonly string $template,
        private readonly array $templateData,
    ) {
    }

    public function handle(WhatsAppOrchestrator $orchestrator): void
    {
        $orchestrator->sendStatusNotificationNow($this->companyId, $this->phone, $this->template, $this->templateData);
    }
}
