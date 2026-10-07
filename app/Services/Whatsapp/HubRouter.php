<?php

namespace App\Services\Whatsapp;

use App\Models\HubPhone;
use App\Support\HubSignature;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hub yönlendiricisi (APP_ROLE=hub): tek WhatsApp numarasına gelen mesajı,
 * yazan telefonun kayıtlı olduğu firma kurulumuna iletir ve firmanın TwiML
 * cevabını aynen Twilio'ya döndürür. Hub müşteri verisi tutmaz.
 *
 * Twilio imzası VerifyTwilioSignature'da doğrulanmış olarak gelir; firmaya
 * HubSignature (firmaya özel gizli anahtar) ile imzalanıp iletilir.
 */
class HubRouter
{
    /** Twilio 15 sn bekler; foto okuma uzun sürebilir, bu yüzden biraz pay bırakıyoruz. */
    private const TIMEOUT_SECONDS = 14;

    public function forward(Request $request)
    {
        $phone = Phone::normalize((string) $request->input('From', ''));

        $route = $phone === '' ? null : HubPhone::query()
            ->where('phone', $phone)
            ->where('is_active', true)
            ->whereHas('firm', fn ($q) => $q->where('is_active', true))
            ->with('firm')
            ->first();

        if (! $route) {
            return $this->twiml("Bu numara Hesap Asistanım'a kayıtlı değil.\nBilgi için: hesapasistanim.com");
        }

        $params = $request->post();
        $firm = $route->firm;

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(HubSignature::headers($params, $firm->secret))
                ->post($firm->webhookUrl(), $params);
        } catch (Throwable $e) {
            Log::error('Hub: firmaya iletilemedi', ['firm' => $firm->name, 'error' => $e->getMessage()]);

            return $this->twiml('Şu an cevap veremiyorum, birkaç dakika sonra tekrar yazar mısın?');
        }

        if (! $response->successful()) {
            Log::error('Hub: firma hata döndü', ['firm' => $firm->name, 'status' => $response->status()]);

            return $this->twiml('Şu an cevap veremiyorum, birkaç dakika sonra tekrar yazar mısın?');
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'text/xml',
        ]);
    }

    private function twiml(string $text)
    {
        $esc = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return response(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$esc}</Message></Response>",
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
