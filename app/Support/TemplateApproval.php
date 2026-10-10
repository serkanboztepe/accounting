<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp şablonunun Meta onay durumu (approved / pending / rejected / unknown), 10 dk önbellek.
 * Onaysız şablon 24 saat penceresi dışındaki kişiye TESLİM EDİLMEZ (63016) ama Twilio yine "kabul"
 * der — göndermeden önce bakılır (hoş geldin, hatırlatma).
 */
class TemplateApproval
{
    public static function status(string $contentSid): string
    {
        if ($contentSid === '') {
            return 'unknown';
        }

        return Cache::remember('wa-template-approval:' . $contentSid, now()->addMinutes(10), function () use ($contentSid) {
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

    public static function approved(string $contentSid): bool
    {
        return self::status($contentSid) === 'approved';
    }
}
