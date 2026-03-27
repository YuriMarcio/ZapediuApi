<?php

namespace App\Services\Whatsapp;

use App\DataTransferObjects\Whatsapp\CarouselProductCardData;
use App\Jobs\Whatsapp\SendCarouselMessageJob;
use App\Jobs\Whatsapp\SendStatusNotificationJob;
use App\Models\Company;
use App\Services\Zapi\ZapiClient;
use Illuminate\Support\Str;

class WhatsAppOrchestrator
{
    public function __construct(private readonly ZapiClient $zapiClient)
    {
    }

    /**
     * @param  array<int, CarouselProductCardData>  $cards
     */
    public function queueCarousel(int $companyId, string $phone, string $message, array $cards): void
    {
        $payloadCards = array_values(array_map(
            fn (CarouselProductCardData $card): array => $card->toArray(),
            $cards,
        ));

        SendCarouselMessageJob::dispatch($companyId, $phone, $message, $payloadCards);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     */
    public function sendCarouselNow(int $companyId, string $phone, string $message, array $cards): array
    {
        $company = Company::query()->findOrFail($companyId);

        $this->swapCompanyConfig($company);

        return $this->zapiClient->sendCarousel($phone, $message, $cards);
    }

    /**
     * @param  array<string, string>  $templateData
     */
    public function queueStatusNotification(int $companyId, string $phone, string $template, array $templateData): void
    {
        SendStatusNotificationJob::dispatch($companyId, $phone, $template, $templateData);
    }

    /**
     * @param  array<string, string>  $templateData
     */
    public function sendStatusNotificationNow(int $companyId, string $phone, string $template, array $templateData): array
    {
        $company = Company::query()->findOrFail($companyId);
        $this->swapCompanyConfig($company);

        $message = Str::of($template)
            ->replaceMatches('/\{([a-zA-Z0-9_]+)\}/', function (array $matches) use ($templateData): string {
                $key = (string) ($matches[1] ?? '');

                return $templateData[$key] ?? '';
            })
            ->value();

        return $this->zapiClient->sendText($phone, $message);
    }

    private function swapCompanyConfig(Company $company): void
    {
        config()->set('services.zapi.instance_id', $company->zapi_instance_id ?: config('services.zapi.instance_id'));
        config()->set('services.zapi.instance_token', $company->zapi_instance_token ?: config('services.zapi.instance_token'));
        config()->set('services.zapi.client_token', $company->zapi_client_token ?: config('services.zapi.client_token'));
    }
}
