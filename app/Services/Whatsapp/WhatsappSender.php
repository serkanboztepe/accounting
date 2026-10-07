<?php

namespace App\Services\Whatsapp;

use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bizim başlattığımız WhatsApp mesajları (Twilio REST). Kullanıcı son 24 saatte
 * yazmadıysa WhatsApp sadece Meta onaylı şablona izin verir → ContentSid + değişkenler.
 */
class WhatsappSender
{
    /** @param  array<int|string, string>  $variables  {"1": "...", "2": "..."} */
    public function sendTemplate(string $to, string $contentSid, array $variables): bool
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        $from = (string) config('services.whatsapp.from');

        if ($sid === '' || $token === '' || $from === '' || $contentSid === '') {
            Log::warning('WhatsApp şablon gönderimi: ayar eksik (TWILIO_SID/TOKEN, WHATSAPP_FROM, Content SID)');

            return false;
        }

        $response = Http::withBasicAuth($sid, $token)
            ->asForm()
            ->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => 'whatsapp:+' . Phone::normalize($from),
                'To' => 'whatsapp:+' . Phone::normalize($to),
                'ContentSid' => $contentSid,
                'ContentVariables' => json_encode((object) array_map('strval', $variables), JSON_UNESCAPED_UNICODE),
            ]);

        if (! $response->successful()) {
            Log::error('WhatsApp şablon gönderilemedi', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);

            return false;
        }

        return true;
    }
}
