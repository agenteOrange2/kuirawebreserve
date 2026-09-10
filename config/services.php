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

    /*
    |--------------------------------------------------------------------------
    | Transcripción de notas de voz (STT)
    |--------------------------------------------------------------------------
    |
    | El huésped manda audio en WhatsApp más de lo que escribe. Se transcribe
    | con un endpoint compatible con OpenAI (/audio/transcriptions): sirve la
    | propia OpenAI y también Groq (whisper-large-v3-turbo), que es más barato.
    | No se cobra en tokens sino por minuto de audio.
    |
    | Sin key configurada el bot NO se queda mudo: avisa que por ahora solo
    | lee texto (App\Services\Channels\InboundVoiceService).
    |
    */

    'transcription' => [
        'enabled' => env('TRANSCRIPTION_ENABLED', true),
        'url' => env('TRANSCRIPTION_URL', 'https://api.openai.com/v1'),
        'api_key' => env('TRANSCRIPTION_API_KEY'),
        'model' => env('TRANSCRIPTION_MODEL', 'gpt-4o-mini-transcribe'),
        'language' => env('TRANSCRIPTION_LANGUAGE', 'es'),
        // Una nota de voz de recepción no pasa de un minuto; el tope evita
        // que un audio de media hora se lleve el webhook (y el dinero).
        'max_seconds' => (int) env('TRANSCRIPTION_MAX_SECONDS', 180),
        'max_bytes' => (int) env('TRANSCRIPTION_MAX_BYTES', 8388608),
        'timeout' => (int) env('TRANSCRIPTION_TIMEOUT', 20),
    ],

];
