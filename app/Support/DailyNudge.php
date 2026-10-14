<?php

namespace App\Support;

use App\Models\HubPhone;
use App\Services\Whatsapp\WhatsappSender;
use App\Models\WhatsappMessage;
use Illuminate\Support\Carbon;

/**
 * Akşam mesajı: 19:00'da "Bugün aklında bir şey var mı?" — kullanmaya teşvik (Erkan/Burak: ilk gün yazıp
 * sessizleşen kullanıcılar). Kurallar:
 *   - o gün zaten yazmış olana gitmez (kullanıyor, rahatsız etme);
 *   - 3 kez üst üste cevapsız kalırsa haftada bire düşer; kişi bir şey yazınca sayaç sıfırlanır (HubRouter);
 *   - "bana yazma" / "akşam mesajı kapat" → kapanır, "akşam mesajı aç" → açılır (numara bazında, hub'da da görünür).
 * Düğmeli Meta şablonu (pazarlama) onaylıysa onunla; değilse yalnız son 24 saatte yazana düz metin (pencere içi).
 */
class DailyNudge
{
    /** Bu kadar üst üste cevapsızdan sonra haftada bir. */
    public const QUIET_AFTER = 3;

    public const QUIET_DAYS = 7;

    /** Şablon düğmeleri (Twilio quick-reply id'leri → ButtonPayload). */
    public const BUTTON_ENTRY = 'nudge_entry';

    public const BUTTON_REMINDER = 'nudge_reminder';

    public const BUTTON_NONE = 'nudge_none';

    private const WORDS = [
        self::BUTTON_ENTRY => ['kayıt gireceğim', 'kayit girecegim', 'kayıt gireceğim 📝'],
        self::BUTTON_REMINDER => ['hatırlatma kur', 'hatirlatma kur', 'hatırlatma kur ⏰'],
        self::BUTTON_NONE => ['bugün yok', 'bugun yok', 'bugün bir şey yok', 'bugün birşey yok'],
    ];

    private const OFF_WORDS = ['bana yazma', 'akşam mesajı kapat', 'akşam mesajını kapat', 'akşam mesajlarını kapat',
        'akşam mesajı gönderme', 'akşam mesajı istemiyorum', 'aksam mesaji kapat'];

    private const ON_WORDS = ['akşam mesajı aç', 'akşam mesajını aç', 'akşam mesajlarını aç', 'aksam mesaji ac'];

    /**
     * Firmanın numaralarına gönder (firma bağlamı açık olmalı: konuşma kaydı ve maliyet firmaya yazılır).
     *
     * @return list<string> komut çıktısı satırları
     */
    public static function run(int $firmId, WhatsappSender $sender, bool $dryRun = false): array
    {
        $now = Carbon::now();
        $contentSid = (string) config('services.whatsapp.nudge_content_sid');
        $useTemplate = TemplateApproval::approved($contentSid);
        $out = [];

        foreach (HubPhone::where('hub_firm_id', $firmId)->get() as $phone) {
            $lastIn = self::lastInbound($phone->phone);
            $label = $phone->name ?: $phone->phone;

            if ($reason = self::skipReason($phone, $lastIn, $now)) {
                $out[] = "  · {$label}: atlandı ({$reason})";

                continue;
            }
            if (! $useTemplate && ! self::inWindow($lastIn, $now)) {
                $out[] = "  · {$label}: atlandı (şablon onaysız, 24 saat penceresi dışında)";

                continue;
            }
            // Gizli numara (WhatsApp kimliği): şablon gitmez (Twilio 21211) — pencere içindeyse düz metin.
            $hidden = Phone::isWhatsappId($phone->phone);
            if ($hidden && ! self::inWindow($lastIn, $now)) {
                $out[] = "  · {$label}: atlandı (gizli numara, 24 saat penceresi dışında)";

                continue;
            }
            if ($dryRun) {
                $out[] = "  → {$label}: " . ($useTemplate && ! $hidden ? 'şablon' : 'düz metin');

                continue;
            }

            $ok = $useTemplate && ! $hidden
                ? $sender->sendTemplate($phone->phone, $contentSid, ['1' => self::firstName($phone->name)], 'aksam_mesaji')
                : $sender->sendText($phone->phone, self::plainText($phone->name));
            $out[] = "  → {$label}: " . ($ok ? '✓' : '✗ (log)');

            if ($ok) {
                $phone->forceFill(['nudge_sent_at' => $now, 'nudge_unanswered' => $phone->nudge_unanswered + 1])->saveQuietly();
                WhatsappMessage::create(['phone' => $phone->phone, 'direction' => 'out', 'body' => self::text($phone->name), 'kind' => 'nudge']);
            }
        }

        return $out;
    }

    public static function text(?string $name): string
    {
        return 'Merhaba ' . self::firstName($name) . " 👋 Bugün aklında bir şey var mı?\n"
            . 'Bir satış, alacak-verecek ya da hatırlatmamı istediğin bir şey varsa yaz, kaydedeyim.';
    }

    /** Şablon onaysızken (pencere içi düz metin) düğme yok — ne yazacağını söyle. */
    public static function plainText(?string $name): string
    {
        return self::text($name) . "\n\n(Bugün yoksa *bugün yok* yaz.)";
    }

    public static function firstName(?string $name): string
    {
        $first = trim((string) strtok(trim((string) $name), ' '));

        return $first !== '' ? $first : 'merhaba';
    }

    /**
     * Gönderilsin mi? null = gönder, yoksa atlama nedeni (komutun çıktısında görünür).
     */
    public static function skipReason(HubPhone $phone, ?Carbon $lastIn, Carbon $now): ?string
    {
        $today = $now->copy()->timezone(Reminders::TIMEZONE)->startOfDay();

        if (! $phone->is_active) {
            return 'pasif numara';
        }
        if (! $phone->nudge_enabled) {
            return 'kapattı';
        }
        if ($lastIn && $lastIn->copy()->timezone(Reminders::TIMEZONE)->gte($today)) {
            return 'bugün yazmış';
        }
        if ($phone->nudge_sent_at && $phone->nudge_sent_at->copy()->timezone(Reminders::TIMEZONE)->gte($today)) {
            return 'bugün gönderildi';
        }
        if ($phone->nudge_unanswered >= self::QUIET_AFTER && $phone->nudge_sent_at
            && $phone->nudge_sent_at->gt($now->copy()->subDays(self::QUIET_DAYS))) {
            return 'haftalık (cevapsız)';
        }

        return null;
    }

    /** Son 24 saatte yazdıysa düz metin teslim edilir (şablonsuz). */
    public static function inWindow(?Carbon $lastIn, Carbon $now): bool
    {
        return $lastIn !== null && $lastIn->gt($now->copy()->subHours(23));
    }

    public static function lastInbound(string $phone): ?Carbon
    {
        $at = WhatsappMessage::where('phone', Phone::normalize($phone))->where('direction', 'in')->max('created_at');

        return $at ? Carbon::parse($at) : null;
    }

    /** Gelen mesaj bir düğme cevabı mı? → BUTTON_* ya da null. */
    public static function button(string $payload, string $word): ?string
    {
        if (array_key_exists($payload, self::WORDS)) {
            return $payload;
        }
        foreach (self::WORDS as $id => $words) {
            if (in_array($word, $words, true)) {
                return $id;
            }
        }
        // Yazım farkları: "Bugün birsey yok" (Oktay, 14 Ekim — takvim sorusu sanıldı), "bugun hic bisey yok", "bir şey yok".
        // Tek başına "yok" sayılmaz (başka bir soruya cevap olabilir).
        $folded = strtr($word, ['ü' => 'u', 'ş' => 's', 'ı' => 'i', 'ğ' => 'g', 'ç' => 'c', 'ö' => 'o']);
        if (preg_match('/^(bu ?gun ?)?(hic ?)?(bir ?s[e]y|bisey) ?yok$|^bu ?gun ?(hic ?)?yok$/u', $folded)) {
            return self::BUTTON_NONE;
        }

        return null;
    }

    public static function reply(string $button): string
    {
        return match ($button) {
            self::BUTTON_ENTRY => "Yaz bakalım, ne oldu? 🙂\nÖrneğin: \"Ahmet'e 5 bin sattım\", \"Veli 2 bin verdi\", \"Mazota 3 bin verdim\"",
            self::BUTTON_REMINDER => "Neyi, ne zaman hatırlatayım? 🙂\nÖrneğin: \"Cuma kirayı hatırlat\", \"Ayın 15'inde Ahmet'ten parayı almayı hatırlat\"",
            default => 'Tamam, iyi akşamlar 👋',
        };
    }

    /** "bana yazma" → false, "akşam mesajı aç" → true, değilse null. */
    public static function toggleWord(string $word): ?bool
    {
        return match (true) {
            in_array($word, self::OFF_WORDS, true) => false,
            in_array($word, self::ON_WORDS, true) => true,
            default => null,
        };
    }

    /** Numaranın akşam mesajı ayarını değiştir (merkezdeki hub_phones). Numara bulunamazsa false. */
    public static function setEnabled(string $phone, bool $enabled): bool
    {
        return HubPhone::where('phone', Phone::normalize($phone))
            ->update(['nudge_enabled' => $enabled, 'nudge_unanswered' => 0]) > 0;
    }

    /** Kişi bir şey yazdı → cevapsız sayacı sıfır (haftalıktan günlüğe döner). */
    public static function answered(HubPhone $phone): void
    {
        if ($phone->nudge_unanswered > 0) {
            $phone->forceFill(['nudge_unanswered' => 0])->saveQuietly();
        }
    }

    /** Hub'da görünen durum. */
    public static function status(HubPhone $phone): string
    {
        return match (true) {
            ! $phone->nudge_enabled => 'Kapalı',
            $phone->nudge_unanswered >= self::QUIET_AFTER => 'Haftalık',
            default => 'Günlük',
        };
    }
}
