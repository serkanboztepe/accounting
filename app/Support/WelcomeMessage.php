<?php

namespace App\Support;

use App\Models\HubPhone;
use App\Services\Whatsapp\WhatsappSender;
use App\Tenancy\Tenancy;

/**
 * Hub'da numara eklenince giden karşılama (Meta onaylı "hos_geldin" şablonu — kişi henüz yazmadığı
 * için serbest metin gitmez). {{1}} = adın ilk kelimesi (yoksa firma adı). Firma içinde gönderilir → şablon ücreti
 * firmanın kullanım kaydına yazılır. İlk mesajında ayrıca sektöre göre rehber gider.
 */
class WelcomeMessage
{
    public static function configured(): bool
    {
        return (string) config('services.whatsapp.welcome_content_sid') !== '';
    }

    public static function send(HubPhone $phone): bool
    {
        // Ad yoksa firma adı ("Merhaba merhaba" olmasın); Meta boş değişken kabul etmez.
        $firstName = trim((string) strtok(trim((string) $phone->name), ' ')) ?: (trim((string) $phone->firm?->name) ?: 'dostum');
        $send = fn () => app(WhatsappSender::class)->sendTemplate(
            $phone->phone,
            (string) config('services.whatsapp.welcome_content_sid'),
            ['1' => $firstName],
            'hos_geldin',
        );

        $firm = $phone->firm;

        return $firm && $firm->isLocal() ? Tenancy::run($firm, $send) : $send();
    }
}
