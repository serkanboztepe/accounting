<?php

namespace App\Services\Whatsapp;

use App\Http\Controllers\WhatsappWebhookController;
use App\Models\HubFirm;
use App\Models\HubMessageLog;
use App\Models\HubPhone;
use App\Support\HubSignature;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /** Kayıtsız numaraya tanıtım — yazan kişi potansiyel müşteri, kapıyı kapatma. */
    public const UNKNOWN_PHONE_REPLY = "Merhaba! 👋 Ben *Hesap Asistanım*: verdiğini, aldığını, harcadığını WhatsApp'tan yazarsın, hesabını ben tutarım.\n\n"
        . "Bu numara henüz bir hesaba bağlı değil.\n"
        . "✅ Kullanmak istersen bize yaz: wa.me/905453606783\n"
        . "🌐 Nasıl çalıştığını gör: hesapasistanim.com";

    /**
     * Kayıtsız numaraya tanıtım günde bir kez gider; aynı gün tekrar yazarsa sessiz
     * (boş TwiML) — kişiyi bıktırmasın, mesaj ücreti boşa gitmesin.
     */
    public static function unknownPhoneResponse(string $from)
    {
        $key = 'wa-unknown-intro:' . Phone::normalize($from);

        if (! Cache::add($key, true, now()->addDay())) {
            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
        }

        return self::twimlMessage(self::UNKNOWN_PHONE_REPLY);
    }

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
            $response = self::unknownPhoneResponse((string) $request->input('From', ''));
            $this->log(null, $phone, $response->getContent());

            return $response;
        }

        $params = $request->post();
        $firm = $route->firm;

        // Tek panel: firmanın veritabanı burada — HTTP'siz, firmayı açıp aynı istekte işle.
        if ($firm->isLocal()) {
            return $this->handleLocally($request, $firm, $phone);
        }

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

        $this->log($firm->id, $phone, $response->body());

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'text/xml',
        ]);
    }

    private function handleLocally(Request $request, HubFirm $firm, string $phone)
    {
        $request->attributes->set('via_hub', true);

        try {
            $response = Tenancy::run($firm, fn () => app()->call(
                [app(WhatsappWebhookController::class), '__invoke'],
                ['request' => $request],
            ));
        } catch (Throwable $e) {
            Log::error('Hub: firma mesajı işlenemedi', ['firm' => $firm->name, 'error' => $e->getMessage()]);
            report($e);

            return $this->twiml('Şu an cevap veremiyorum, birkaç dakika sonra tekrar yazar mısın?');
        }

        $this->log($firm->id, $phone, (string) $response->getContent());

        return $response;
    }

    /** Maliyet takibi: gelen 1 mesaj + cevapta <Message> varsa giden 1 mesaj. */
    private function log(?int $firmId, string $phone, string $twiml): void
    {
        try {
            HubMessageLog::create(['hub_firm_id' => $firmId, 'phone' => $phone, 'direction' => 'in']);
            if (str_contains($twiml, '<Message')) {
                HubMessageLog::create(['hub_firm_id' => $firmId, 'phone' => $phone, 'direction' => 'out']);
            }
        } catch (Throwable $e) {
            Log::warning('Hub mesaj kaydı yazılamadı', ['error' => $e->getMessage()]);
        }
    }

    private function twiml(string $text)
    {
        return self::twimlMessage($text);
    }

    private static function twimlMessage(string $text)
    {
        $esc = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return response(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>{$esc}</Message></Response>",
            200,
            ['Content-Type' => 'text/xml']
        );
    }
}
