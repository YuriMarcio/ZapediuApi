<?php

namespace App\Services\Zapi; // Define o namespace da classe

// Importa as classes e modelos necessários
use App\Jobs\Whatsapp\SendCartFeedbackJob;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Store;
use App\Models\UserAddress;
use App\Models\UserPhone;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
// Importa as novas classes organizadas
use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Support\PayloadExtractor;
use App\Services\Zapi\Handlers\StoreHandle;
use App\Services\Zapi\Flows\GreetingFlow;
use App\Services\Zapi\Handlers\TextHandler;
use App\Services\Zapi\Handlers\ButtonHandler;
use App\Services\Zapi\Handlers\ProductsHandler;

class ZapiWebhookService
{
    // Prefixo para cache de estado do fluxo
    private const FLOW_STATE_CACHE_PREFIX = 'zapi:flow:state:';

    // Quantidade de lojas por página no carrossel
    private const STORE_PAGE_SIZE = 9;

    // Quantidade de produtos por página no carrossel
    private const PRODUCT_PAGE_SIZE = 5;

    // Injeta as dependências necessárias via construtor
    public function __construct(
        private readonly ZapiClient $zapiClient,
        private readonly FlowManager $flow,
        private readonly PayloadExtractor $extractor,
        private readonly StoreHandle $storeHandle, // Nome corrigido aqui
        private readonly GreetingFlow $greetingFlow,
        private readonly TextHandler $textHandler,
        private readonly ButtonHandler $buttonHandler
    ) {
    }

    // Método principal que recebe o payload do webhook
    public function ingest(array $payload): WebhookEvent
    {
        Log::info('Ingesting Zapi webhook event.', ['payload' => $payload]);
        // Cria um registro do evento recebido
        $event = WebhookEvent::create([
            'provider' => 'zapi', // Define o provedor
            'event_type' => $this->eventType($payload), // Tipo do evento
            'external_id' => $this->resolveExternalId($payload), // ID externo
            'payload' => $payload, // Payload completo
            'processed_at' => now(), // Data/hora de processamento
        ]);

        Log::info('Webhook event recebido', ['event' => $event]);

        // Extrai atributos de delivery do payload
        $deliveryAttributes = $this->extractDeliveryAttributes($payload);

        // Se houver atributos de delivery, salva ou atualiza o registro
        if ($deliveryAttributes !== null) {
            $lookupExternalId = $deliveryAttributes['external_id'] ?? null;
            $lookupOrderCode = $deliveryAttributes['order_code'] ?? null;

            if ($lookupExternalId !== null) {
                $delivery = Delivery::firstOrNew(['external_id' => $lookupExternalId]);
            } elseif ($lookupOrderCode !== null) {
                $delivery = Delivery::firstOrNew(['order_code' => $lookupOrderCode]);
            } else {
                $delivery = new Delivery();
            }

            $delivery->fill($deliveryAttributes); // Preenche os dados
            $delivery->save(); // Salva no banco
        }

        // Chama o método para possivelmente enviar uma resposta automática
        $this->maybeSendAutoReply($payload);

        // Retorna o evento criado
        return $event;
    }
    // Decide se deve enviar uma resposta automática ao usuário
    public function maybeSendAutoReply(array $payload): void
    {
        if (! (bool) config('services.zapi.auto_reply_enabled')) {
            return;
        }

        if ($this->isOutgoingMessage($payload)) {
            return;
        }

        $phone = $this->resolveIncomingPhone($payload);

        if ($phone === null) {
            return;
        }

        if ($this->handleCommerceFlow($payload, $phone)) {
            return;
        }

        $messageText = $this->resolveIncomingMessageText($payload);

        if ($messageText === null) {
            return;
        }

        $this->sendWelcomePrompt($phone);
    }

    private function isOutgoingMessage(array $payload): bool
    {
        return $this->extractor->isOutgoingMessage($payload);
    }

    private function resolveIncomingPhone(array $payload): ?string
    {
        return $this->extractor->resolveIncomingPhone($payload);
    }

    private function resolveIncomingMessageText(array $payload): ?string
    {
        return $this->extractor->resolveIncomingMessageText($payload);
    }

    public function resolveButtonReplyId(array $payload): ?string
    {
        $id = $payload['buttonReply']['buttonId']  // ← era 'id', agora 'buttonId'
            ?? $payload['buttonReply']['id']
            ?? $payload['listReply']['buttonId']
            ?? $payload['listReply']['id']
            ?? $payload['buttonResponseMessage']['selectedButtonId']
            ?? null;

        if ($id === null || trim((string) $id) === '') {
            return null;
        }

        return strtolower(trim((string) $id));
    }

    private function resolveSelectedCategoryId(array $payload): ?string
    {
        return $this->extractor->resolveSelectedCategoryId($payload);
    }

    private function sendWelcomePrompt(string $phone): void
    {
        try {
            $this->greetingFlow->sendWelcomePrompt($phone);
        } catch (\Throwable $e) {
            Log::warning('Failed to send welcome prompt.', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
    // Lida com o fluxo de comércio (categorias, botões, texto)
    private function handleCommerceFlow(array $payload, string $phone): bool
    {
        $selectedCategorySlug = $this->resolveSelectedCategoryId($payload);
        Log::info('handleCommerceFlow', [
            'phone' => $phone,
            'selectedCategorySlug' => $selectedCategorySlug,
            'buttonId' => $this->resolveButtonReplyId($payload),
            'messageText' => $this->resolveIncomingMessageText($payload),
        ]);

        if ($selectedCategorySlug !== null) {
            return $this->sendCategoryStores($phone, $selectedCategorySlug);
        }

        $buttonId = strtolower(trim((string) ($this->resolveButtonReplyId($payload) ?? '')));

        if ($buttonId !== '') {
            Log::info('Handling button', ['buttonId' => $buttonId]);
            return $this->handleFlowButton($phone, $buttonId);
        }

        $messageText = $this->resolveIncomingMessageText($payload);

        if ($messageText === null) {
            return false;
        }

        return $this->handleFlowText($phone, $messageText);
    }
    private function handleFlowButton(string $phone, string $buttonId): bool
    {
        Log::info('handleFlowButton delegating to ButtonHandler', ['buttonId' => $buttonId]);
        try {
            return $this->buttonHandler->handle($phone, $buttonId);
        } catch (\Throwable $e) {
            Log::error('Button flow failed.', [
                'phone' => $phone,
                'buttonId' => $buttonId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    private function handleFlowText(string $phone, string $messageText): bool
    {
        try {
            return $this->textHandler->handle($phone, $messageText);
        } catch (\Throwable $e) {
            Log::error('Text flow failed.', [
                'phone' => $phone,
                'message' => $messageText,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
    // Envia mensagem explicando como funciona o sistema
    private function sendHowItWorks(string $phone): bool
    {
        $message = 'Voce escolhe uma categoria, seleciona uma loja e finaliza tudo no WhatsApp. Simples e rapido.';

        try {
            $this->zapiClient->sendText($phone, $message); // Envia mensagem para o usuário

            return true;
        } catch (\Throwable $exception) {
            // Loga o erro caso não consiga enviar
            Log::warning('Failed to send how-it-works message.', [
                'phone' => $phone,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
