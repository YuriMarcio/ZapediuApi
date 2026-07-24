<?php

namespace App\Services\Nlp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqClient
{
    private const FALLBACK = ['intent' => null, 'tipo' => null, 'item' => null, 'categoria' => null, 'match' => false];

    /**
     * Classifica a intenção do cliente na entrada do bot (spec §1): `{intent, tipo, item,
     * categoria, match}`, onde `tipo` é "loja"|"produto"|null, `item` é o termo pra buscar e
     * `categoria` (só quando tipo:"produto") é uma das categorias GERAIS/segmento da
     * plataforma (a que a loja escolhe no cadastro, ex: "HAMBURGUERIA", "PIZZARIA" — lista
     * fechada passada em `$knownCategories`), usada pra achar produto que não tem o termo no
     * título (ex: "hambúrguer" não aparece no nome de nenhum produto, só na categoria da
     * loja). Qualquer falha (sem chave, API fora, timeout, JSON inválido) devolve
     * `match:false` — o chamador sempre recebe um array bem formado e cai no fallback por
     * palavra-chave sem quebrar. API compatível com OpenAI (chat completions).
     */
    public function classifyIntent(string $message, array $knownCategories = []): array
    {
        if (! (bool) config('services.groq.enabled')) {
            return self::FALLBACK;
        }

        $apiKey = (string) config('services.groq.api_key');
        if ($apiKey === '') {
            return self::FALLBACK;
        }

        try {
            $model = (string) config('services.groq.model', 'llama-3.3-70b-versatile');
            $timeout = (int) config('services.groq.timeout', 4);

            $response = Http::withToken($apiKey)
                ->timeout($timeout)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'user', 'content' => $this->buildPrompt($message, $knownCategories)],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Groq classifyIntent: resposta não-2xx.', ['status' => $response->status()]);

                return self::FALLBACK;
            }

            $text = (string) ($response->json('choices.0.message.content') ?? '');
            $decoded = json_decode($text, true);

            if (! is_array($decoded)) {
                return self::FALLBACK;
            }

            $tipo = $decoded['tipo'] ?? null;
            $tipo = in_array($tipo, ['loja', 'produto'], true) ? $tipo : null;

            $categoria = is_string($decoded['categoria'] ?? null) ? trim($decoded['categoria']) : '';
            $categoria = ($tipo === 'produto' && $categoria !== '') ? $categoria : null;

            return [
                'intent' => is_string($decoded['intent'] ?? null) ? $decoded['intent'] : null,
                'tipo' => $tipo,
                'item' => is_string($decoded['item'] ?? null) ? trim($decoded['item']) : null,
                'categoria' => $categoria,
                'match' => (bool) ($decoded['match'] ?? false),
            ];
        } catch (\Throwable $e) {
            Log::warning('Groq classifyIntent falhou.', ['error' => $e->getMessage()]);

            return self::FALLBACK;
        }
    }

    private function buildPrompt(string $message, array $knownCategories = []): string
    {
        $categoriesLine = $knownCategories !== []
            ? "\nCategorias gerais conhecidas: ".implode(', ', $knownCategories)."\n"
            : '';

        return <<<PROMPT
            Você classifica mensagens de clientes de um bot de delivery por WhatsApp (Zapediu).
            Responda SOMENTE com um JSON no formato exato:
            {"intent": "<resumo curto da intenção>", "tipo": "loja"|"produto"|null, "item": "<termo de busca>"|null, "categoria": "<categoria geral>"|null, "match": true|false}

            Regras:
            - "tipo":"loja" quando o cliente menciona o NOME de um estabelecimento (ex: "quero pedir na Burger House").
            - "tipo":"produto" quando o cliente descreve o que quer comer/beber, sem citar loja (ex: "quero uma pizza de calabresa", "tem açaí?").
            - "match":false quando a mensagem não é um pedido de comida reconhecível (ex: saudação genérica, pergunta fora do escopo, spam).
            - "item" é o termo de busca extraído (nome da loja ou do produto/categoria), sem palavras de preenchimento.
            - "categoria" só é preenchida quando "tipo":"produto": é a categoria geral (segmento) do cardápio à qual esse produto pertence. Responda com uma das opções da lista abaixo, EXATAMENTE como está escrita, ou null se nenhuma combinar bem.
            {$categoriesLine}
            Mensagem do cliente: "{$message}"
            PROMPT;
    }
}
