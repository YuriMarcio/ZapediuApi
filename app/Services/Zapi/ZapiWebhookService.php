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
    // Prefixo para cache de estado do fluxo (usado para armazenar o progresso do usuário)
    private const FLOW_STATE_CACHE_PREFIX = 'zapi:flow:state:';

    // Quantidade de lojas exibidas por página no carrossel
    private const STORE_PAGE_SIZE = 9;

    // Quantidade de produtos exibidos por página no carrossel
    private const PRODUCT_PAGE_SIZE = 5;

    /**
     * Injeta as dependências necessárias para o serviço de webhook do Zapi.
     * Cada handler/serviço é responsável por uma parte do fluxo de atendimento.
     */
    public function __construct(
        private readonly ZapiClient $zapiClient,
        private readonly FlowManager $flow,
        private readonly PayloadExtractor $extractor,
        private readonly StoreHandle $storeHandle,
        private readonly GreetingFlow $greetingFlow,
        private readonly TextHandler $textHandler,
        private readonly ButtonHandler $buttonHandler
    ) {
    }

    /**
     * Método principal chamado ao receber um webhook do Zapi.
     * Cria o registro do evento, processa delivery (se houver) e dispara resposta automática.
     */
    public function ingest(array $payload): WebhookEvent
    {
        Log::info('Ingesting Zapi webhook event.', ['payload' => $payload]);

        // Cria um registro do evento recebido no banco
        $event = WebhookEvent::create([
            'provider' => 'zapi',
            'event_type' => $this->eventType($payload),
            'external_id' => $this->resolveExternalId($payload),
            'payload' => $payload,
            'processed_at' => now(),
        ]);

        Log::info('Webhook event recebido', ['event' => $event]);

        // Extrai atributos de delivery do payload (caso seja um evento relacionado a entrega/pedido)
        $deliveryAttributes = $this->extractDeliveryAttributes($payload);

        // Se houver dados de delivery, salva ou atualiza o registro correspondente
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

            $delivery->fill($deliveryAttributes); // Preenche os dados do delivery
            $delivery->save(); // Salva no banco
        }

        // Dispara resposta automática ao usuário, se aplicável
        $this->maybeSendAutoReply($payload);

        // Retorna o evento criado
        return $event;
    }

    /**
     * Decide se deve enviar uma resposta automática ao usuário, conforme regras de negócio.
     * Pode disparar fluxos de categoria, botões ou mensagem de boas-vindas.
     */
    public function maybeSendAutoReply(array $payload): void
    {
        // Verifica se o auto-reply está habilitado na configuração
        if (! (bool) config('services.zapi.auto_reply_enabled')) {
            return;
        }

        // Ignora mensagens enviadas pelo próprio sistema (apenas responde a mensagens recebidas)
        if ($this->isOutgoingMessage($payload)) {
            return;
        }

        // Extrai o telefone do usuário que enviou a mensagem
        $phone = $this->resolveIncomingPhone($payload);

        if ($phone === null) {
            return;
        }

        // Se for um fluxo de comércio (categoria/botão/texto), trata e responde
        if ($this->handleCommerceFlow($payload, $phone)) {
            return;
        }

        // Se não for comércio, verifica se há texto para responder
        $messageText = $this->resolveIncomingMessageText($payload);

        if ($messageText === null) {
            return;
        }

        // Se não caiu em nenhum fluxo anterior, envia mensagem de boas-vindas
        $this->sendWelcomeWay($phone);
    }

    /**
     * Verifica se a mensagem recebida é uma mensagem enviada pelo sistema (outgoing).
     */
    private function isOutgoingMessage(array $payload): bool
    {
        return $this->extractor->isOutgoingMessage($payload);
    }

    /**
     * Extrai o telefone do usuário a partir do payload recebido.
     */
    private function resolveIncomingPhone(array $payload): ?string
    {
        return $this->extractor->resolveIncomingPhone($payload);
    }

    /**
     * Extrai o texto da mensagem recebida, se houver.
     */
    private function resolveIncomingMessageText(array $payload): ?string
    {
        return $this->extractor->resolveIncomingMessageText($payload);
    }

    /**
     * Resolve o ID do botão ou opção selecionada pelo usuário, se houver.
     */
    public function resolveButtonReplyId(array $payload): ?string
    {
        $id = $payload['buttonReply']['buttonId']
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

    /**
     * Extrai o slug da categoria selecionada, se houver.
     */
    private function resolveSelectedCategoryId(array $payload): ?string
    {
        return $this->extractor->resolveSelectedCategoryId($payload);
    }

    /**
     * Envia mensagem de boas-vindas ao usuário, usando o GreetingFlow.
     */
    private function sendWelcomeWay(string $phone): void
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

    /**
     * Lida com o fluxo de comércio: categoria, botões ou texto.
     * Se algum desses fluxos for tratado, retorna true.
     */
    /**
      * Lida com o fluxo de comércio: categoria, botões ou texto.
      * Se algum desses fluxos for tratado, retorna true.
      */
    private function handleCommerceFlow(array $payload, string $phone): bool
    {
        // 1. EXTRAI TUDO PRIMEIRO (Evita o erro de variável não definida)
        $buttonId = strtolower(trim((string) ($this->resolveButtonReplyId($payload) ?? '')));
        $messageText = $this->resolveIncomingMessageText($payload);
        $selectedCategorySlug = $this->resolveSelectedCategoryId($payload);

        // 2. BLOQUEIO DE GRUPO (Evita que o bot fique conversando com texto no grupo)
        if (str_contains($phone, '-group') || str_contains($phone, '@g.us')) {
            if ($buttonId === '') {
                // É só texto no grupo. Ignora e encerra!
                \Illuminate\Support\Facades\Log::info("Ignorando texto no grupo", ['grupo' => $phone, 'texto' => $messageText ?? 'vazio']);
                return true;
            }
        }

        // 3. FLUXOS DE BOTÃO
        if ($buttonId !== '') {
            Log::info('Handling button', ['buttonId' => $buttonId]);

            // 🟢 A NOSSA BARREIRA DO MOTOBOY!
            // Se for o botão de aceitar entrega, chama nossa trava blindada e encerra
            if (str_starts_with($buttonId, 'accept_order|')) {

            // 🕵️‍♂️ O ESPIÃO: Vai imprimir o JSON inteiro da Z-API no seu log!
                \Illuminate\Support\Facades\Log::info("🔍 PAYLOAD Z-API COMPLETO: ", $payload);

                // 👉 A MÁGICA AQUI: Pega o número real de quem clicou no grupo
                $motoboyPhone = $payload['participantPhone'] ?? $phone;

                $handler = new \App\Services\Whatsapp\AcceptDeliveryHandler();
                $handler->handle($motoboyPhone, $buttonId, $this->zapiClient);
                return true;
            }

            // Se for outro botão qualquer da loja, segue o fluxo normal
            return $this->handleFlowButton($phone, $buttonId);
        }

        // 4. FLUXO DE CATEGORIA
        if ($selectedCategorySlug !== null) {
            return $this->sendCategoryStores($phone, $selectedCategorySlug);
        }

        // 5. FLUXO DE TEXTO NORMAL
        if ($messageText === null) {
            return false;
        }

        return $this->handleFlowText($phone, $messageText);
    }

    /**
     * Encaminha o tratamento do botão para o ButtonHandler.
     */
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

    /**
     * Encaminha o tratamento do texto para o TextHandler.
     */
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

    /**
     * Envia mensagem explicando como funciona o sistema para o usuário.
     */
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
