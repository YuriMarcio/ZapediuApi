<?php

namespace App\Services\Nlp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
{
    private const FALLBACK = ['intent' => null, 'tipo' => null, 'item' => null, 'match' => false];

    /**
     * Classifica a intenção do cliente na entrada do bot (spec §1): `{intent, tipo, item,
     * match}`, onde `tipo` é "loja"|"produto"|null e `item` é o termo pra buscar. Qualquer
     * falha (sem chave, API fora, timeout, JSON inválido) devolve `match:false` — o chamador
     * sempre recebe um array bem formado e cai no fallback por palavra-chave sem quebrar.
     */
    public function classifyIntent(string $message): array
    {
        if (! (bool) config('services.gemini.enabled')) {
            return self::FALLBACK;
        }

        $apiKey = (string) config('services.gemini.api_key');
        if ($apiKey === '') {
            return self::FALLBACK;
        }

        try {
            $model = (string) config('services.gemini.model', 'gemini-2.0-flash');
            $timeout = (int) config('services.gemini.timeout', 4);

            $response = Http::timeout($timeout)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                    'contents' => [
                        ['parts' => [['text' => $this->buildPrompt($message)]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini classifyIntent: resposta não-2xx.', ['status' => $response->status()]);

                return self::FALLBACK;
            }

            $text = (string) ($response->json('candidates.0.content.parts.0.text') ?? '');
            $decoded = json_decode($text, true);

            if (! is_array($decoded)) {
                return self::FALLBACK;
            }

            $tipo = $decoded['tipo'] ?? null;

            return [
                'intent' => is_string($decoded['intent'] ?? null) ? $decoded['intent'] : null,
                'tipo' => in_array($tipo, ['loja', 'produto'], true) ? $tipo : null,
                'item' => is_string($decoded['item'] ?? null) ? trim($decoded['item']) : null,
                'match' => (bool) ($decoded['match'] ?? false),
            ];
        } catch (\Throwable $e) {
            Log::warning('Gemini classifyIntent falhou.', ['error' => $e->getMessage()]);

            return self::FALLBACK;
        }
    }

    private function buildPrompt(string $message): string
    {
        return <<<PROMPT
            Você classifica mensagens de clientes de um bot de delivery por WhatsApp (Zapediu).
            Responda SOMENTE com um JSON no formato exato:
            {"intent": "<resumo curto da intenção>", "tipo": "loja"|"produto"|null, "item": "<termo de busca>"|null, "match": true|false}

            Regras:
            - "tipo":"loja" quando o cliente menciona o NOME de um estabelecimento (ex: "quero pedir na Burger House").
            - "tipo":"produto" quando o cliente descreve o que quer comer/beber, sem citar loja (ex: "quero uma pizza de calabresa", "tem açaí?").
            - "match":false quando a mensagem não é um pedido de comida reconhecível (ex: saudação genérica, pergunta fora do escopo, spam).
            - "item" é o termo de busca extraído (nome da loja ou do produto/categoria), sem palavras de preenchimento.

            Mensagem do cliente: "{$message}"
            PROMPT;
    }
}
