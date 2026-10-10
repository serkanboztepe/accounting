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
        // Günlük konuşma analizi (gecede bir, düşük hacim) — en güçlü model.
        'analysis_model' => env('ANTHROPIC_ANALYSIS_MODEL', 'claude-opus-5-5'),
        // Fotoğraf (el yazısı çek/fiş) için güçlü vision modeli — okuma kalitesi kritik.
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-opus-4-8'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        // Webhook isteklerinde X-Twilio-Signature doğrulaması (yalnız yerel denemede kapatılır).
        'verify_signature' => (bool) env('TWILIO_VERIFY_SIGNATURE', true),
        // Asistanı kullanabilecek telefonlar (virgülle; 0545..., +90545..., 90545... hepsi olur).
        // Boşsa KİMSE kullanamaz — üretim numarasına herkes yazabilir, finansal veri sızmasın.
        'allowed_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_ALLOWED_PHONES', ''))))),
    ],

    // WhatsApp'tan bizim başlattığımız mesajlar (Meta onaylı şablonla).
    'whatsapp' => [
        'from' => env('WHATSAPP_FROM'),                                     // +14786665916
        'check_reminder_content_sid' => env('WHATSAPP_CHECK_REMINDER_SID'), // Twilio Content SID (HX…)
        // Kullanıcının kurduğu hatırlatmalar (şablon "hatirlatma", {{1}} = metin). Onaylı değilse serbest metin (yalnız 24 saat içinde teslim).
        'reminder_content_sid' => env('WHATSAPP_REMINDER_SID'),
        // Hub'da numara eklenince giden karşılama şablonu ("hos_geldin", {{1}} = ad). Boşsa gönderilmez.
        'welcome_content_sid' => env('WHATSAPP_WELCOME_SID'),
        // Günlük konuşma analizi özeti kime gider (yönetici, virgülle). Boşsa gönderilmez.
        'admin_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_ADMIN_PHONES', ''))))),
        // Çek hatırlatması kime gider (virgülle). Boşsa gönderilmez.
        'reminder_phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_REMINDER_PHONES', ''))))),
        // Vadeden kaç gün önce hatırlatılsın (0 = vade günü).
        'check_reminder_days' => array_map('intval', array_filter(array_map('trim', explode(',', (string) env('CHECK_REMINDER_DAYS', '3,0'))), 'strlen')),
    ],

    // WhatsApp hub'ı: firma kurulumu, hub'dan gelen mesajı bu anahtarla doğrular.
    // Hub'daki "Firmalar" ekranında bu firmanın anahtarıyla aynı olmalı.
    'hub' => [
        'secret' => env('HUB_SECRET'),
    ],

];
