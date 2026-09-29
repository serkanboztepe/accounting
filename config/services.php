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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // WhatsApp gider asistanı (Faz 0 ispat).
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Metin girişleri için ucuz model.
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        // Fotoğraf (el yazısı çek/fiş) için güçlü vision modeli — okuma kalitesi kritik.
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-opus-4-8'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
    ],

];
