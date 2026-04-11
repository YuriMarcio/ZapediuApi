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

    'admin' => [
        'api_token' => env('ADMIN_API_TOKEN', ''),
    ],

    'mercado_pago' => [
        'base_url' => env('MERCADO_PAGO_BASE_URL', 'https://api.mercadopago.com'),
        'access_token' => env('MERCADO_PAGO_ACCESS_TOKEN', ''),
        'public_key' => env('MERCADO_PAGO_PUBLIC_KEY', ''),
        'webhook_url' => env('MERCADO_PAGO_WEBHOOK_URL', ''),
        'success_url' => env('MERCADO_PAGO_SUCCESS_URL', ''),
        'failure_url' => env('MERCADO_PAGO_FAILURE_URL', ''),
        'pending_url' => env('MERCADO_PAGO_PENDING_URL', ''),
        'statement_descriptor' => env('MERCADO_PAGO_STATEMENT_DESCRIPTOR', 'DELIVERYZAP'),
        'marketplace_name' => env('MERCADO_PAGO_MARKETPLACE_NAME', 'DeliveryZap'),
        'timeout' => (int) env('MERCADO_PAGO_TIMEOUT', 20),
    ],

    'zapi' => [
        'base_url' => env('ZAPI_BASE_URL', 'https://api.z-api.io'),
        'instance_id' => env('ZAPI_INSTANCE_ID'),
        'instance_token' => env('ZAPI_INSTANCE_TOKEN', env('ZAPI_TOKEN')),
        'client_token' => env('ZAPI_CLIENT_TOKEN'),
        'webhook_token' => env('ZAPI_WEBHOOK_TOKEN'),
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
        'catalog_phone' => env('ZAPI_CATALOG_PHONE'),
        'catalog_translation' => env('ZAPI_CATALOG_TRANSLATION', 'PT'),
        'catalog_message' => env('ZAPI_CATALOG_MESSAGE', 'Acesse nosso catalogo no WhatsApp:'),
        'catalog_title' => env('ZAPI_CATALOG_TITLE', 'Catalogo de produtos'),
        'catalog_description' => env('ZAPI_CATALOG_DESCRIPTION', 'Toque para visualizar nossos produtos.'),
        'product_id' => env('ZAPI_PRODUCT_ID'),
        'flow_welcome_message' => env('ZAPI_FLOW_WELCOME_MESSAGE', 'Ola, digite o que procura ou digite filtro.'),
        'flow_state_ttl_minutes' => (int) env('ZAPI_FLOW_STATE_TTL_MINUTES', 180),
        'flow_more_image' => env('ZAPI_FLOW_MORE_IMAGE', 'https://picsum.photos/seed/mais-lojas/600/600'),
        'flow_back_to_stores_image' => env('ZAPI_FLOW_BACK_TO_STORES_IMAGE', 'https://picsum.photos/seed/outras-lojas/600/600'),
        'payment_base_url' => env('ZAPI_PAYMENT_BASE_URL', 'https://pagamento.deliveryzap.com/checkout'),
    ],

];
