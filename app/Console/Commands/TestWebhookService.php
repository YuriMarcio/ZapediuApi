<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;

class TestWebhookService extends Command
{
    protected $signature = 'test:webhook';
    protected $description = 'Test webhook service binding';

    public function handle(): void
    {
        echo "STEP 1\n";
        
        try {
            $action = app(\App\Actions\Webhooks\ProcessIncomingWebhookAction::class);
            echo "ACTION OK\n";
        } catch (\Throwable $e) {
            echo "ACTION FAILED: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }

        echo "STEP 2\n";

        try {
            $service = app(\App\Services\Zapi\ZapiWebhookService::class);
            echo "SERVICE OK\n";
        } catch (\Throwable $e) {
            echo "SERVICE FAILED: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }

        echo "DONE\n";
    }
}