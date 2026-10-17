<?php

namespace App\Support;

use App\Models\AssistantLesson;
use App\Models\HubAnnouncement;
use App\Models\HubFirm;
use App\Models\HubMessageLog;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\WhatsappSender;
use App\Tenancy\Tenancy;

/**
 * "Müşteriye sor" (Faz 3): yönetici bir dersin doğru olup olmadığını bilemiyorsa soruyu müşteriye sorar. Son 24 saatte
 * yazmışsa hemen gider (konuşma geçmişine de yazılır — asistan cevabın bağlamını görür); yazmamışsa WhatsApp kuralı
 * gereği müşterinin bir SONRAKİ mesajından sonra iletilir (duyuru düzeni). Cevap AssistantLesson::captureAnswer ile gelir.
 */
final class LessonQuestion
{
    /**
     * Müşteriye tek mesaj (ders sorusu, kayıt kontrolü): son 24 saatte yazmışsa hemen (konuşma geçmişine $kind ile yazılır —
     * asistan cevabın bağlamını görür); yazmamışsa bir sonraki mesajından sonra (duyuru düzeni).
     *
     * @return array{sent:bool, announcement_id:?int}
     */
    public static function deliver(HubFirm $firm, string $phone, string $text, string $kind): array
    {
        $phone = Phone::normalize($phone);
        $inFirm = fn (callable $cb) => Tenancy::enabled() ? Tenancy::run($firm, $cb) : $cb();
        $recent = $inFirm(fn () => WhatsappMessage::where('phone', $phone)->where('direction', 'in')
            ->where('created_at', '>=', now()->subHours(23))->exists());
        if ($recent && app(WhatsappSender::class)->sendText($phone, $text)) {
            $inFirm(fn () => WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => $text, 'kind' => $kind]));
            HubMessageLog::create(['hub_firm_id' => $firm->id, 'phone' => $phone, 'direction' => 'out']);

            return ['sent' => true, 'announcement_id' => null];
        }

        return ['sent' => false, 'announcement_id' => HubAnnouncement::create(['hub_firm_id' => $firm->id, 'phone' => $phone,
            'body' => $text, 'status' => 'pending'])->id];
    }

    /** @return string sonuç açıklaması (bildirim için) */
    public static function send(AssistantLesson $lesson, HubFirm $firm, string $phone, string $question): string
    {
        $phone = Phone::normalize($phone);
        $inFirm = fn (callable $cb) => Tenancy::enabled() ? Tenancy::run($firm, $cb) : $cb(); // tek kurulumda doğrudan
        $text = "🙋 Bir şey sormak istiyorum: " . trim($question) . "\n\nCevabını buraya yazman yeterli, asistanı düzeltmeme yardım eder. 🙏";

        $recent = $inFirm(fn () => WhatsappMessage::where('phone', $phone)->where('direction', 'in')
            ->where('created_at', '>=', now()->subHours(23))->exists());

        if ($recent && app(WhatsappSender::class)->sendText($phone, $text)) {
            $inFirm(fn () => WhatsappMessage::create(['phone' => $phone, 'direction' => 'out', 'body' => $text, 'kind' => 'lesson_question']));
            HubMessageLog::create(['hub_firm_id' => $firm->id, 'phone' => $phone, 'direction' => 'out']);
            $lesson->update(['customer_question' => trim($question), 'asked_phone' => $phone, 'asked_at' => now(),
                'asked_announcement_id' => null, 'customer_answer' => null, 'answered_at' => null]);

            return 'Soru gönderildi; cevabı gelince burada görünecek.';
        }

        $a = HubAnnouncement::create(['hub_firm_id' => $firm->id, 'phone' => $phone, 'body' => $text, 'status' => 'pending']);
        $lesson->update(['customer_question' => trim($question), 'asked_phone' => $phone, 'asked_at' => now(),
            'asked_announcement_id' => $a->id, 'customer_answer' => null, 'answered_at' => null]);

        return 'Son 24 saatte yazmadığı için soru bekletiliyor — bir sonraki mesajından sonra iletilecek.';
    }
}
