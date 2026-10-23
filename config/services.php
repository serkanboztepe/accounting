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
        'vision_model' => env('ANTHROPIC_VISION_MODEL', 'claude-opus-5-5'), // 20.10: 4.8'den 5.5'e (daha yeni + ucuz)
        // Sohbet asistanı (deneme): son konuşmayı görür, araç kullanır. Varsayılan KAPALI.
        'chat_assistant' => env('WHATSAPP_CHAT_ASSISTANT', false),
        'chat_model' => env('ANTHROPIC_CHAT_MODEL'),
        // Süzgeç / ikinci göz (kayıt kullanıcının anlattığıyla çelişiyor mu?): günlük sohbet ucuz modelde olsa da kontrol güçlü
        // modelde (19.10 Haiku geçişi: Haiku süzgeci gereksiz alarm verip "A mı B mi?" sorusunda kilitledi).
        'check_model' => env('ANTHROPIC_CHECK_MODEL', 'claude-sonnet-5'),
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
        // Tur teşhis kaydı (TurnTrace): modele giden/gelen, işlem hattı adımları, karar okumaları. Davranışı değiştirmez.
        'trace' => (bool) env('WHATSAPP_TRACE', false),
        'trace_path' => env('WHATSAPP_TRACE_PATH'), // boşsa storage/app/wa-trace
        // Pilot: iz yalnız bu firma kimliklerinde ("12,7"); boşsa (firma sistemi açıkken) hiçbir firmada.
        'trace_firms' => array_values(array_filter(array_map('trim', explode(',', (string) env('WHATSAPP_TRACE_FIRMS', ''))), 'strlen')),
        'check_reminder_content_sid' => env('WHATSAPP_CHECK_REMINDER_SID'), // Twilio Content SID (HX…)
        // Kullanıcının kurduğu hatırlatmalar (şablon "hatirlatma", {{1}} = metin). Onaylı değilse serbest metin (yalnız 24 saat içinde teslim).
        'reminder_content_sid' => env('WHATSAPP_REMINDER_SID'),
        // Akşam ciro sorusu ("ciro_sorusu", {{1}} = ilk ad). Onaylanana kadar "hatirlatma" şablonuyla gider.
        'revenue_content_sid' => env('WHATSAPP_REVENUE_SID'),
        // Duyuru haberi ("yenilikler", düğme "Yenilikleri göster", {{1}} = ilk ad) — yazmayana da ulaşsın.
        'news_content_sid' => env('WHATSAPP_NEWS_SID'),
        // Hub'da numara eklenince giden karşılama şablonu ("hos_geldin", {{1}} = ad). Boşsa gönderilmez.
        'welcome_content_sid' => env('WHATSAPP_WELCOME_SID'),
        // Akşam mesajı ("aksam_sorusu", düğmeli, {{1}} = ilk ad). Onaylı değilse yalnız son 24 saatte yazana düz metin.
        'nudge_content_sid' => env('WHATSAPP_NUDGE_SID'),
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

    // Yerel gerçek AI denemelerinin maliyeti canlı hub'a gider (aynı Anthropic anahtarı → toplu maliyet).
    // Yerelde: DEV_USAGE_URL=https://panel.hesapasistanim.com/hub/dev-usage + DEV_USAGE_SECRET; sunucuda yalnız SECRET.
    'dev_usage' => [
        'url' => env('DEV_USAGE_URL'),
        'secret' => env('DEV_USAGE_SECRET'),
    ],

];
