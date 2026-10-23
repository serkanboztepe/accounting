<?php

namespace Tests\Feature\Eval;

use App\Models\WhatsappMessage;
use App\Support\Phone;

/**
 * Yeniden oynatmada WhatsApp isteği gönderme (ölçüm altyapısı; ürün davranışı değil).
 *
 * Laravel test uygulaması istek sonrası geri çağrıları (terminating) her istekte BAŞTAN çalıştırır — listeyi boşaltmaz.
 * Canlıda süreç başına bir istek vardır; testte ise önceki istekte arka plana bırakılan tur (DeferTurn) sonraki istekte
 * tekrar çalışıp ikinci taslak açıyordu (23.10 ölçümü: Bahadır satışı iki kez). Her istekten sonra liste boşaltılır.
 */
trait ReplayRequests
{
    protected string $phone = 'whatsapp:+905559990001';

    /** Testte kapatılabilir (pozitif kontrol): false → eski davranış, geri çağrılar birikir. */
    protected bool $resetTerminating = true;

    protected function sendMessage(string $body): string
    {
        $res = $this->post('/whatsapp/webhook', ['From' => $this->phone, 'Body' => $body, 'NumMedia' => 0, 'MessageSid' => 'SM' . uniqid()]);
        if ($this->resetTerminating) {
            (fn () => $this->terminatingCallbacks = [])->call($this->app);
        }
        $reply = trim(strip_tags(html_entity_decode((string) $res->getContent(), ENT_QUOTES | ENT_XML1)));
        // "🔎 Bakıyorum" ya da geç cevap: asıl cevap istekten sonra ayrı mesajla gider (konuşma kaydındaki son giden mesaj).
        if ($reply === '' || str_contains($reply, 'Bakıyorum')) {
            $later = WhatsappMessage::where('phone', Phone::normalize($this->phone))->where('direction', 'out')->latest('id')->value('body');
            $reply = trim($reply . ' ⏩ ' . (string) $later);
        }

        return $reply;
    }
}
