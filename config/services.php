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

    /*
    |--------------------------------------------------------------------------
    | Eleven Labs (Conversational AI)
    |--------------------------------------------------------------------------
    |
    | Modulo de solo lectura de llamadas. La key se lee SOLO aqui y nunca se
    | manda al navegador: el audio y el detalle pasan por el backend.
    |
    | La key del workspace debe tener el permiso `convai_read` como minimo
    | (conversaciones, agentes y numeros). `user_read` es opcional y solo hace
    | falta para mostrar el saldo de creditos.
    |
    */
    'elevenlabs' => [
        'key' => env('ELEVENLABS_API_KEY'),
        'base' => env('ELEVENLABS_API_BASE', 'https://api.elevenlabs.io'),
        'timeout' => (int) env('ELEVENLABS_TIMEOUT', 30),
        // page_size tope de la API: 100 (1000 devuelve 422)
        'page_size' => (int) env('ELEVENLABS_PAGE_SIZE', 100),
        'max_paginas' => (int) env('ELEVENLABS_MAX_PAGINAS', 40),
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

];
