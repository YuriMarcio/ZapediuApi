<?php

namespace App\Actions\Webhooks;

use App\DataTransferObjects\Whatsapp\ButtonClickData;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\UserAddress;
use App\Models\UserPhone;
use App\Models\WebhookEvent;
use App\Services\Zapi\ZapiWebhookService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

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
            'status' => $this->mapIncomingStatus((string) ($orderData['status'] ?? 'pending')),
            'total_amount' => (float) ($orderData['total'] ?? 0),
            'source' => 'zapi',
            'last_update_at' => now(),
            'raw_payload' => $payload,
        ]);
        $delivery->save();

        $customerPhone = $this->normalizePhone((string) data_get($orderData, 'customer.phone', ''));
        $customerEmail = trim((string) data_get($orderData, 'customer.email', ''));
        $customerName = trim((string) data_get($orderData, 'customer.name', ''));
        $customerAddress = trim((string) data_get($orderData, 'customer.address', ''));
        $customerUser = $this->resolveCustomerUser($companyId, $customerName, $customerPhone, $customerEmail, $orderCode);
        $this->syncCustomerContacts($customerUser, $customerPhone, $customerAddress, null);
        $productIds = $this->extractProductIds((array) data_get($orderData, 'items', []));

        Order::query()->updateOrCreate(
            ['code' => $orderCode !== '' ? $orderCode : 'ORD-'.now()->format('YmdHis')],
            [
                'company_id' => $companyId,
                'user_id' => $customerUser?->id,
                'delivery_id' => $delivery->id,
                'product_ids' => $productIds === [] ? null : $productIds,
                'status' => $this->mapIncomingStatus((string) ($orderData['status'] ?? 'pending')),
                'payment_status' => (string) ($orderData['payment_status'] ?? 'pending'),
                'total' => (float) ($orderData['total'] ?? 0),
                'ordered_at' => now(),
                'raw_payload' => $payload,
            ],
        );
    }

    private function extractProductIds(array $items): array
    {
        return collect($items)
            ->map(fn ($item) => (int) data_get($item, 'product_id', data_get($item, 'product.id', 0)))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }

    private function syncCustomerContacts(?\App\Models\User $user, ?string $customerPhone, string $customerAddress, ?string $reference): void
    {
        if ($user === null) {
            return;
        }

        if ($customerPhone !== null && $customerPhone !== '') {
            UserPhone::query()->where('user_id', $user->id)->update(['is_primary' => false]);
            UserPhone::query()->updateOrCreate(
                ['user_id' => $user->id, 'phone' => $customerPhone],
                ['label' => 'principal', 'is_primary' => true]
            );
        }

        if ($customerAddress !== '') {
            UserAddress::query()->where('user_id', $user->id)->update(['is_primary' => false]);
            UserAddress::query()->updateOrCreate(
                ['user_id' => $user->id, 'formatted' => $customerAddress],
                [
                    'street' => $customerAddress,
                    'notes' => $reference,
                    'is_primary' => true,
                ]
            );
        }
    }

    private function resolveCustomerUser(?int $companyId, string $customerName, ?string $customerPhone, string $customerEmail, string $orderCode): ?\App\Models\User
    {
        if ($customerPhone === null && $customerEmail === '') {
            return null;
        }

        $query = \App\Models\User::query();

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        $query->where(function ($inner) use ($customerPhone, $customerEmail): void {
            if ($customerPhone !== null) {
                $inner->orWhere('phone', $customerPhone);
            }

            if ($customerEmail !== '') {
                $inner->orWhere('email', $customerEmail);
            }
        });

        $user = $query->first();

        if ($user !== null) {
            return $user;
        }

        return \App\Models\User::query()->create([
            'company_id' => $companyId,
            'name' => $customerName !== '' ? $customerName : 'Cliente '.($customerPhone ?? $orderCode),
            'email' => $customerEmail !== '' ? $customerEmail : 'cliente-'.($customerPhone ?? Str::lower(Str::slug($orderCode))).'@deliveryzap.local',
            'phone' => $customerPhone,
            'password' => Str::random(32),
            'is_admin' => false,
            'role' => 'customer',
        ]);
    }

    private function normalizePhone(string $phone): ?string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        return $normalized !== '' ? $normalized : null;
    }

    private function mapIncomingStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'new' => 'pending',
            'confirmed' => 'accepted',
            'out_for_delivery' => 'delivering',
            'delivered' => 'done',
            'pending', 'accepted', 'preparing', 'delivering', 'done', 'cancelled' => strtolower(trim($status)),
            default => 'pending',
        };
    }
}
