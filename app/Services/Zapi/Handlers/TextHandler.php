<?php

namespace App\Services\Zapi\Handlers;

use App\Services\Zapi\Flows\FlowManager;
use App\Services\Zapi\Flows\GreetingFlow;
use App\Services\Zapi\Flows\CheckoutFlow;
use App\Services\Zapi\Flows\CartFlow;
use App\Services\Zapi\Handlers\CategoriesHandle;
use App\Services\Zapi\Handlers\ProductsHandler;
use App\Services\Zapi\Handlers\StoreHandle;
use App\Services\Zapi\Support\StoreSearch;
use App\Services\Nlp\GroqClient;
use App\Services\Whatsapp\WhatsAppClientInterface;

class TextHandler
{
    public function __construct(
        private FlowManager $flow,
        private GreetingFlow $greetingFlow,
        private StoreHandle $storeHandle,
        private WhatsAppClientInterface $zapiClient,
        private CheckoutFlow $checkoutFlow,
        private CategoriesHandle $categoriesHandle,
        private CartFlow $cartFlow,
        private StoreSearch $search,
        private GroqClient $groq,
        private ProductsHandler $productsHandler

    ) {
    }

    public function handle(string $phone, string $messageText): bool
    {
        // Normaliza o texto da mensagem (ex: minúsculas, sem acentos)
        $normalized = $this->flow->normalize($messageText);
        // Recupera o estado atual do usuário (por telefone)
        $state = $this->flow->getState($phone);

        // Comando global: se o usuário digitar "limpar"
        if ($normalized === 'limpar') {
            // Reseta o estado da conversa do usuário
            $this->flow->resetState($phone);
            try {
                // Envia mensagem avisando que a sessão foi resetada
                $this->zapiClient->sendText($phone, "🗑️ Sessão resetada! Digite *oi* para começar.");
            } catch (\Throwable) {
                // Ignora erros ao enviar mensagem
            }
            // Retorna true indicando que o comando foi tratado
            return true;
        }

        // Loja pizzaria aguardando a observação do cliente pro item que acabou de ser
        // montado (ver CartFlow::finishCustomizationAndCommit) — precisa vir antes do
        // checkout_step pra não ser interpretado como dado de checkout.
        if (!empty($state['pending_add']['awaiting_observation'])) {
            return $this->cartFlow->handleObservationTextInput($phone, $messageText);
        }

        // §5.2 — cliente clicou em "10+" e está digitando a quantidade
        if (!empty($state['awaiting_qty'])) {
            $pending = $state['awaiting_qty'];
            unset($state['awaiting_qty']);
            $this->flow->saveState($phone, $state);

            $qty = (int) preg_replace('/\D+/', '', $messageText);
            if ($qty < 1 || $qty > 99) {
                $this->zapiClient->sendText($phone, '⚠️ Quantidade inválida. Toque de novo em "🔢 Quero mais de um" e digite um número entre 1 e 99.');

                return true;
            }

            return $this->cartFlow->startAddProductFlow(
                $phone,
                (string) $pending['store_id'],
                (string) $pending['product_id'],
                $qty
            );
        }

        // §11.2 — aguardando o texto da observação vinculada a um item do carrinho
        if (array_key_exists('awaiting_cart_observation', $state)) {
            return $this->cartFlow->handleCartObservationText($phone, $messageText);
        }

        // Se o usuário está em um fluxo de checkout (ex: preenchendo dados)
        if (!empty($state['checkout_step'])) {
            // Delega o tratamento do texto para o CheckoutFlow
            // Passa o texto original, o texto normalizado e o passo atual do checkout
            return $this->checkoutFlow->handleCheckoutTextInput(
                $phone,
                $messageText, // Texto original (importante para e-mails e nomes próprios)
                $normalized,  // Texto em minúsculas (para comandos como 'cancelar')
                $state['checkout_step']
            );
        }

        // Trata palavras-chave conhecidas usando match
        return match ($normalized) {
            // Se o usuário digitar "carrinho"
            'carrinho' => $this->handleViewCart($phone),
            // Se digitar "finalizar", "pagar" ou "checkout"
            'finalizar', 'pagar', 'checkout' => $this->handleFinalize($phone),
            // Se digitar "lojas", "ver lojas" ou "mostrar lojas"
            'lojas', 'ver lojas', 'mostrar lojas' => $this->storeHandle->sendStoresPage($phone, 0),
            // Se digitar "oi", "ola", "oie", "menu", "inicio" ou "start"
            'oi', 'ola', 'oie', 'menu', 'inicio', 'start' => $this->greetingFlow->sendWelcomePrompt($phone),
            // Qualquer outro texto cai no tratamento genérico de busca
            default => $this->handleGenericSearch($phone, $messageText, $normalized)
        };
    }

    private function handleViewCart(string $phone): bool
    {
        return $this->cartFlow->sendCartSummary($phone);
    }

    private function handleFinalize(string $phone): bool
    {
        return $this->checkoutFlow->finalizeCart($phone);
    }

    private function handleGenericSearch(string $phone, string $messageText, string $normalized): bool
    {
        // Spec §1: mensagens >13 caracteres tentam o NLP primeiro, pra pular direto pro
        // carrossel certo em vez do cliente ter que navegar. Sem chave/desabilitado/erro,
        // handleNlpIntent devolve null e cai no fallback por palavra-chave abaixo.
        if (mb_strlen($normalized) > 13) {
            $handled = $this->handleNlpIntent($phone, $messageText);
            if ($handled !== null) {
                return $handled;
            }
        }

        // Fallback por palavra-chave (mensagem curta, ou NLP indisponível): mesma prioridade
        // do caminho de IA (§1.1.a/§1.1.b) — loja primeiro, senão produto cruzando lojas —
        // só que via match direto em vez de classificação. Sem isso, buscas curtas de um
        // produto só (ex.: "batata", "pizza") nunca chegavam a olhar produto, só loja.
        try {
            if ($this->search->byQuery($messageText) !== []) {
                return $this->storeHandle->sendStoreSearchResults($phone, $messageText);
            }

            return $this->handleProductSearch($phone, $messageText);
        } catch (\Throwable $e) {
            return $this->categoriesHandle->returnToStores($phone);
        }
    }

    /**
     * `null` quando o Gemini não decidiu nada (desabilitado, sem chave, erro de API) — nesse
     * caso o chamador segue pro fallback por palavra-chave. `match:false` só vira mensagem de
     * "não entendi" quando o Gemini de fato rodou e classificou a mensagem (intent preenchido).
     */
    private function handleNlpIntent(string $phone, string $messageText): ?bool
    {
        $intent = $this->groq->classifyIntent($messageText, $this->search->knownGeneralCategories());

        if (! $intent['match']) {
            if ($intent['intent'] === null) {
                return null;
            }

            try {
                $this->zapiClient->sendText($phone, 'Não entendi, pode repetir? 🤔');
            } catch (\Throwable) {
            }

            return $this->greetingFlow->sendWelcomePrompt($phone);
        }

        $item = trim((string) ($intent['item'] ?? ''));
        if ($item === '') {
            return null;
        }

        if ($intent['tipo'] === 'produto') {
            // String vazia (não null) sinaliza "já tentei classificar, não achei categoria" —
            // handleProductSearch não repete a chamada à IA nesse caso (só repetiria o mesmo
            // resultado). null é reservado pro caminho de busca curta, que nunca chamou a IA.
            $categoria = trim((string) ($intent['categoria'] ?? ''));

            return $this->handleProductSearch($phone, $item, $categoria);
        }

        return $this->handleStoreSearch($phone, $item);
    }

    /**
     * §1.1.a — cliente pediu uma loja específica: busca por nome (exato ou aproximado) e
     * manda o carrossel direto, sem passar por categorias gerais.
     */
    private function handleStoreSearch(string $phone, string $item): bool
    {
        $storeIds = $this->search->byQuery($item);

        $state = $this->flow->getState($phone);
        $state['last_search'] = $item;
        $state['store_results'] = $storeIds;
        $state['store_offset'] = 0;
        $this->flow->saveState($phone, $state);

        if ($storeIds === []) {
            try {
                $this->zapiClient->sendText($phone, "Poxa, não encontrei nada com \"{$item}\" por aqui. 😕 Dá uma olhada nessas opções:");
            } catch (\Throwable) {
            }

            return $this->storeHandle->sendStoresPage($phone, 0);
        }

        $title = count($storeIds) === 1
            ? '🏪 Encontrei a loja que você procura:'
            : '🏪 Encontrei essas lojas pra você:';

        return $this->storeHandle->sendStoresPage($phone, 0, $title);
    }

    /**
     * §1.1.b — cliente descreveu um produto: busca cruzando todas as lojas ativas (fuzzy match
     * no nome/descrição) e manda um carrossel de PRODUTOS (não de lojas) — cada card já
     * carrega loja + produto no botão "🛒 Adicionar por R$ X", reaproveitando o mesmo caminho
     * de adicionar do carrossel normal (§5), incluindo mono-loja (§10) e customização (§6).
     */
    private function handleProductSearch(string $phone, string $item, ?string $categoriaGeral = null): bool
    {
        // Nível 1 — título/descrição (comportamento de sempre).
        if ($this->productsHandler->sendProductSearchCarousel($phone, $item, 0)) {
            return true;
        }

        // Nível 2 — categoria GERAL/segmento (ex: "HAMBURGUERIA"), pra produto que não tem o
        // termo no título (ex: "hambúrguer" não aparece em "Bacon Inferno", só na categoria
        // "Hambúrgueres" da loja). `null` = busca curta, nunca tentou IA — tenta agora, só
        // nesse ponto (nível 1 já falhou). `''` = já veio do NLP e não achou categoria, não
        // repete a chamada.
        if ($categoriaGeral === null) {
            $fresh = $this->groq->classifyIntent($item, $this->search->knownGeneralCategories());
            $categoriaGeral = trim((string) ($fresh['categoria'] ?? ''));
        }

        if ($categoriaGeral !== ''
            && $this->productsHandler->sendProductSearchByCategoryCarousel($phone, $categoriaGeral, $item, 0)) {
            return true;
        }

        // Nível 3 — nem título nem categoria acharam nada: lojas abertas agora, carrossel de
        // verdade (mensagem empática vai como título do carrossel, mensagem única).
        $state = $this->flow->getState($phone);
        $state['store_results'] = null;
        $state['store_offset'] = 0;
        $this->flow->saveState($phone, $state);

        $apologia = "😔 Não encontrei nada parecido com \"{$item}\" no momento. Mas dá uma olhada nessas lojas abertas agora: 👇";
        if ($this->storeHandle->sendStoresPage($phone, 0, $apologia)) {
            return true;
        }

        // Último recurso: nem loja aberta tem agora.
        try {
            $this->zapiClient->sendButtonActions(
                $phone,
                "😔 Não encontrei nenhum \"{$item}\" no momento.\nQuer dar uma olhada no cardápio geral?",
                [
                    ['id' => 'btn_ver_lojas', 'label' => '🏪 Ver Lojas'],
                    ['id' => 'btn_ver_categorias', 'label' => '🍔 Ver Categorias'],
                ],
                'Zapediu'
            );
        } catch (\Throwable) {
        }

        return true;
    }
}
