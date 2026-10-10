<?php

namespace App\Support;

use App\Models\HubPhone;
use App\Services\Whatsapp\WhatsappSender;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

    /**
     * Meta onay durumu (approved / pending / rejected / unknown), 10 dk önbellek. Onaysız şablon hiç
     * yazmamış birine teslim EDİLMEZ (63016) ama Twilio yine "kabul" der — ekran "gönderildi" deyip
     * yanıltıyordu (Burak, 2026-10-10).
     */
    public static function approvalStatus(): string
    {
        $contentSid = (string) config('services.whatsapp.welcome_content_sid');

        return Cache::remember('wa-welcome-approval:' . $contentSid, now()->addMinutes(10), function () use ($contentSid) {
            try {
                $response = Http::withBasicAuth((string) config('services.twilio.sid'), (string) config('services.twilio.token'))
                    ->timeout(10)
                    ->get("https://content.twilio.com/v1/Content/{$contentSid}/ApprovalRequests");

                return (string) ($response->json('whatsapp.status') ?: 'unknown');
            } catch (\Throwable) {
                return 'unknown';
            }
        });
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
