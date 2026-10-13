<?php

namespace App\Support;

use App\Models\HubAnnouncement;
use App\Models\HubPhone;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Support\Carbon;

/**
 * Duyuru ("yeni özellikler") yazmayan kişiye de ulaşsın: hub'da numaraya bırakılan duyuru —
 *   · kişi son 24 saatte yazdıysa metin doğrudan gider (şablonsuz);
 *   · yazmadıysa sabit "yenilikler" şablonu (düğme: "Yenilikleri göster") → duyuru "notified";
 *     düğmeye basınca / "yenilikler" yazınca ya da bir sonraki mesajında metnin kendisi gider.
 * Şablonda duyuru metni yok (değişken yalnız ad) → Meta onayı kolay, duyuru istediği kadar uzun olabilir.
 */
class Announcements
{
    public const BUTTON = 'news_show';

    private const WORDS = ['yenilikler', 'yenilikleri göster', 'yenilikleri goster', 'yeni özellikler', 'yeni ozellikler',
        'yenilikler neler', 'ne yenilik var', 'yenilik var mı', 'yenilikleri göster 🆕'];

    public static function isRequest(string $payload, string $word): bool
    {
        return $payload === self::BUTTON || in_array($word, self::WORDS, true);
    }

    /** "Yenilikleri göster": bekleyen duyuru (gönderildi sayılır) ya da en son gönderilen. */
    public static function answer(string $phone): string
    {
        if ($pending = HubAnnouncement::pendingFor($phone)) {
            $pending->update(['status' => 'sent', 'sent_at' => now()]);

            return $pending->body;
        }

        return HubAnnouncement::latestSentFor($phone)?->body
            ?? 'Şu an yeni bir duyuru yok. Neler yapabildiğimi görmek için *nasıl* yaz. 🙂';
    }

    /**
     * Firmanın bekleyen duyurularını gönder (firma bağlamı açık olmalı: konuşma kaydı ve maliyet firmaya).
     *
     * @return list<string> komut çıktısı
     */
    public static function push(int $firmId, WhatsappSender $sender, bool $dryRun = false): array
    {
        $sid = (string) config('services.whatsapp.news_content_sid');
        $template = TemplateApproval::approved($sid);
        $now = Carbon::now();
        $out = [];

        foreach (HubAnnouncement::where('hub_firm_id', $firmId)->where('status', 'pending')->orderBy('id')->get() as $a) {
            $name = HubPhone::where('phone', $a->phone)->value('name');
            $label = ($name ?: $a->phone) . ' #' . $a->id;
            $inWindow = DailyNudge::inWindow(DailyNudge::lastInbound($a->phone), $now);

            if (! $inWindow && ! $template) {
                $out[] = "  · {$label}: bekliyor (24 saat dışında, şablon onaysız — yazınca gidecek)";

                continue;
            }
            if ($dryRun) {
                $out[] = "  → {$label}: " . ($inWindow ? 'metin' : 'şablon');

                continue;
            }

            $ok = $inWindow
                ? $sender->sendText($a->phone, $a->body)
                : $sender->sendTemplate($a->phone, $sid, ['1' => DailyNudge::firstName($name)], 'yenilikler');
            if ($ok) {
                $a->update($inWindow ? ['status' => 'sent', 'sent_at' => now()] : ['status' => 'notified']);
                \App\Models\WhatsappMessage::create(['phone' => $a->phone, 'direction' => 'out',
                    'body' => $inWindow ? $a->body : '[şablon: yenilikler]', 'kind' => 'announcement']);
            }
            $out[] = "  → {$label}: " . ($inWindow ? 'metin' : 'şablon') . ($ok ? ' ✓' : ' ✗ (log)');
        }

        return $out ?: ['  (bekleyen duyuru yok)'];
    }
}
