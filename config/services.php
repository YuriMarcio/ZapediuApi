<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'flowbridge' => [
        'base_url' => env('FLOWBRIDGE_API_URL'),
        'api_key' => env('FLOWBRIDGE_API_KEY'),
        'instance_id' => env('FLOWBRIDGE_INSTANCE_ID'),
        'timeout' => (int) env('FLOWBRIDGE_TIMEOUT', 15),
        // Token no path de /api/webhooks/flowbridge/{token} — o FlowBridge não assina o POST
        // que manda pro callbackUrl, então esse token é a única barreira contra spoofing.
        'webhook_secret' => env('FLOWBRIDGE_WEBHOOK_SECRET'),
        // URL usada pra montar o callbackUrl que o FlowBridge chama de volta. Precisa ser
        // alcançável a partir de ONDE O FLOWBRIDGE RODA — em dev local o FlowBridge é remoto,
        // então APP_URL (localhost:8080) não serve; usar a URL pública do túnel (ngrok etc.).
        'callback_base_url' => env('FLOWBRIDGE_CALLBACK_BASE_URL', env('APP_URL')),
    ],

    'mercadopago' => [
        'client_id' => env('MP_CLIENT_ID'),
        'client_secret' => env('MP_CLIENT_SECRET'),
        'redirect_uri' => env('MP_REDIRECT_URI'),
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
        'access_token' => env('MERCADO_PAGO_ACCESS_TOKEN'),
        // URL que o MP chama ao mudar o status do pagamento. Fica aqui, e não em env()
        // direto no controller, porque `php artisan config:cache` faz env() devolver null
        // fora dos arquivos de config — e um notification_url nulo derruba a confirmação
        // de TODO pagamento sem erro visível.
        'webhook_url' => env('MERCADO_PAGO_WEBHOOK_URL'),
        // Fallback do checkout quando a wallet da loja ainda não tem public key própria.
        'public_key' => env('VITE_MP_PUBLIC_KEY'),
    ],

    'zapediu' => [
        // Comissão retida pela plataforma quando a company não tem plano vinculado
        // (plans.fee_percent manda quando existe). Ver PlatformFeeCalculator.
        'default_fee_percent' => (float) env('ZAPEDIU_DEFAULT_FEE_PERCENT', 10),

        // Número do WhatsApp do Zapediu usado como fallback no botão "voltar para a
        // conversa" do checkout, quando a operação não tem phone_number registrado na
        // whatsapp_session (o connectSession aceita esse campo, mas ele é opcional).
        'whatsapp_phone' => env('ZAPEDIU_WHATSAPP_PHONE'),
    ],

    'admin' => [
        'api_token' => env('ADMIN_API_TOKEN', ''),
    ],

    'google' => [
    'maps_key' => env('GOOGLE_MAPS_API_KEY'),
],

    // NLP de intenção na entrada do bot (spec §1) — classifica {intent, tipo, item, match}
    // pra pular direto pro carrossel certo quando o cliente já pede algo na primeira mensagem.
    // Gemini ficou sem quota liberada (free tier limit:0 no projeto Google) — Groq é quem
    // roda esse classificador agora (ver App\Services\Nlp\GroqClient).
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT_SECONDS', 4),
        'enabled' => (bool) env('GEMINI_ENABLED', true),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'timeout' => (int) env('GROQ_TIMEOUT_SECONDS', 4),
        'enabled' => (bool) env('GROQ_ENABLED', true),
    ],

    // Chaves de copy/config do motor de fluxo do chatbot (mensagens, paginação, grupo de
    // entregadores etc.) — agnósticas de provedor de transporte. Mantido o nome histórico
    // "zapi" para não inflar o diff da migração pra FlowBridge; ver App\Services\Zapi\*.
    'zapi' => [
        'auto_reply_enabled' => (bool) env('ZAPI_AUTO_REPLY_ENABLED', true),
        'carousel_enabled' => (bool) env('ZAPI_CAROUSEL_ENABLED', true),
        'carousel_intro' => env('ZAPI_CAROUSEL_INTRO', 'Confira nosso cardapio e escolha seu favorito:'),
        'carousel_button_label' => env('ZAPI_CAROUSEL_BUTTON_LABEL', 'Veja o cardapio'),
        'carousel_image_base' => env('ZAPI_CAROUSEL_IMAGE_BASE', 'https://picsum.photos/seed/cardapio/600/600'),
        'list_trigger_keyword' => env('ZAPI_LIST_TRIGGER_KEYWORD', 'filtro'),
        'list_message' => env('ZAPI_LIST_MESSAGE', 'Otima escolha! Agora, selecione uma categoria para ver os produtos:'),
        'list_button_text' => env('ZAPI_LIST_BUTTON_TEXT', 'Ver Cardapio'),
        'list_title' => env('ZAPI_LIST_TITLE', 'Categorias Disponiveis'),
        'list_description' => env('ZAPI_LIST_DESCRIPTION', 'Clique no botao abaixo para navegar.'),
        'drivers_group_jid' => env('ZAPI_DRIVERS_GROUP_JID'),
        'flow_welcome_message' => env('ZAPI_FLOW_WELCOME_MESSAGE', 'Ola, digite o que procura ou digite filtro.'),
        'flow_state_ttl_minutes' => (int) env('ZAPI_FLOW_STATE_TTL_MINUTES', 180),
        'flow_more_image' => env('ZAPI_FLOW_MORE_IMAGE', 'https://picsum.photos/seed/mais-lojas/600/600'),
        'flow_back_to_stores_image' => env('ZAPI_FLOW_BACK_TO_STORES_IMAGE', 'https://picsum.photos/seed/outras-lojas/600/600'),
        'payment_base_url' => env('ZAPI_PAYMENT_BASE_URL', 'https://pagamento.deliveryzap.com/checkout'),
    ],

];
