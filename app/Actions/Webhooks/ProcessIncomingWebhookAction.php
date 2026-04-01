<?php

namespace App\Actions\Webhooks;

use App\DataTransferObjects\Whatsapp\ButtonClickData;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\Zapi\ZapiWebhookService;
use App\Support\Tenancy\TenantContext;

class ProcessIncomingWebhookAction
{
    public function __construct(
        private readonly HandleButtonClickAction $handleButtonClickAction,
        private readonly ZapiWebhookService $zapiWebhookService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(array $payload, ?int $companyId = null): WebhookEvent
    {
        $company = $this->resolveCompany($payload, $companyId);

        /** @var TenantContext $tenant */
        $tenant = app(TenantContext::class);
        $tenant->setCompanyId($company?->id);

        $event = WebhookEvent::query()->create([
            'company_id' => $company?->id,
            'provider' => 'zapi',
            'event_type' => (string) data_get($payload, 'event', 'message.received'),
            'external_id' => (string) (data_get($payload, 'messageId') ?? data_get($payload, 'order.id') ?? ''),
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        $this->syncOrderAndDelivery($payload, $company?->id);

        $buttonClick = ButtonClickData::fromWebhook($payload);

        if ($buttonClick !== null) {
            $this->handleButtonClickAction->execute($buttonClick);
        }

        if ($company !== null) {
            config()->set('services.zapi.instance_id', $company->zapi_instance_id ?: config('services.zapi.instance_id'));
            config()->set('services.zapi.instance_token', $company->zapi_instance_token ?: config('services.zapi.instance_token'));
            config()->set('services.zapi.client_token', $company->zapi_client_token ?: config('services.zapi.client_token'));
        }

        $this->zapiWebhookService->maybeSendAutoReply($payload);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveCompany(array $payload, ?int $companyId): ?Company
    {
        if ($companyId !== null) {
            return Company::query()->find($companyId);
        }

        $instanceId = (string) data_get($payload, 'instanceId', data_get($payload, 'instance.id', ''));

        if ($instanceId !== '') {
            return Company::query()->where('zapi_instance_id', $instanceId)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncOrderAndDelivery(array $payload, ?int $companyId): void
    {
        $orderData = data_get($payload, 'order');

        if (! is_array($orderData)) {
            return;
        }

        $orderCode = (string) ($orderData['code'] ?? '');
        $externalId = (string) ($orderData['id'] ?? '');

        if ($externalId !== '') {
            $delivery = Delivery::query()->firstOrNew(['external_id' => $externalId]);
        } elseif ($orderCode !== '') {
            $delivery = Delivery::query()->firstOrNew(['order_code' => $orderCode]);
        } else {
            $delivery = new Delivery();
        }

        $delivery->fill([
            'company_id' => $companyId,
            'order_code' => $orderCode !== '' ? $orderCode : null,
            'customer_name' => data_get($orderData, 'customer.name'),
            'customer_phone' => data_get($orderData, 'customer.phone'),
            'address' => data_get($orderData, 'customer.address'),
            'status' => (string) ($orderData['status'] ?? 'new'),
            'total_amount' => (float) ($orderData['total'] ?? 0),
            'source' => 'zapi',
            'last_update_at' => now(),
            'raw_payload' => $payload,
        ]);
        $delivery->save();

        Order::query()->updateOrCreate(
            ['code' => $orderCode !== '' ? $orderCode : 'ORD-'.now()->format('YmdHis')],
            [
                'company_id' => $companyId,
                'delivery_id' => $delivery->id,
                'customer_name' => data_get($orderData, 'customer.name'),
                'customer_phone' => data_get($orderData, 'customer.phone'),
                'customer_address' => data_get($orderData, 'customer.address'),
                'status' => (string) ($orderData['status'] ?? 'new'),
                'payment_status' => (string) ($orderData['payment_status'] ?? 'pending'),
                'total' => (float) ($orderData['total'] ?? 0),
                'ordered_at' => now(),
                'raw_payload' => $payload,
            ],
        );
    }
}
