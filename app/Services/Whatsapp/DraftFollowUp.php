<?php

namespace App\Services\Whatsapp;

use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Support\Phone;
use Illuminate\Support\Carbon;

/**
 * Yarım kalan kayıt takibi: onay almamış ya da eksik bilgili taslak sessizce kaybolmasın.
 *  ① Kullanıcı başka bir kaydı onaylayınca cevabın sonunda (7 güne kadar eski taslak).
 *  ② 30 dk sessizlikte ayrı mesajla (drafts:follow-up) — yalnız 24 saat penceresi içinde, gece değil.
 * Taslak başına en fazla 2 hatırlatma. Hatırlatılan taslak yeniden "etkin" olur (updated_at):
 * sonraki "evet" / "8500" ona bağlanır.
 */
final class DraftFollowUp
{
    public const MAX = 2;

    /** ① iş sonunda gösterilecek en eski yarım kayıt yaşı. */
    public const INLINE_DAYS = 7;

    public const QUIET_MINUTES = 30;

    /** Twilio serbest metin penceresi 24 saat; payı bırak. */
    public const WINDOW_HOURS = 23;

    public const TIMEZONE = 'Europe/Istanbul';

    /** ① Bu numaranın en eski yarım kaydı (az önce onaylanan hariç); kopyası zaten kaydedilmişse kapatılır. */
    public static function inlineCandidate(string $phone, int $exceptId): ?WhatsappPendingExpense
    {
        $drafts = self::open($phone)
            ->where('id', '!=', $exceptId)
            ->where('updated_at', '>=', now()->subDays(self::INLINE_DAYS))
            ->orderBy('id')
            ->get();

        return $drafts->first(fn (WhatsappPendingExpense $p) => ! self::closeIfDuplicate($p));
    }

    /**
     * ② Ayrı mesaj gidecek taslaklar (numara başına en yenisi). İlk hatırlatma 30 dk sessizlikten sonra,
     * ikincisi ertesi gün; kullanıcının son mesajı 23 saatten eskiyse gönderilmez (şablonsuz yazamayız).
     *
     * @return list<WhatsappPendingExpense>
     */
    public static function dueForMessage(): array
    {
        $local = now(self::TIMEZONE);
        if ($local->hour < 8 || $local->hour >= 23) {
            return []; // gece yazma, sabaha kalsın
        }

        $out = [];
        $drafts = self::open()
            ->where('updated_at', '<=', now()->subMinutes(self::QUIET_MINUTES))
            ->where('updated_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->get();

        foreach ($drafts->groupBy('phone') as $phone => $rows) {
            $lastIn = WhatsappMessage::where('phone', Phone::normalize((string) $phone))->where('direction', 'in')->max('created_at');
            if (! $lastIn || Carbon::parse($lastIn)->lt(now()->subHours(self::WINDOW_HOURS))
                || Carbon::parse($lastIn)->gt(now()->subMinutes(self::QUIET_MINUTES))) {
                continue; // pencere kapandı ya da kişi hâlâ yazıyor
            }
            foreach ($rows as $p) {
                if (self::closeIfDuplicate($p)) {
                    continue;
                }
                $secondDue = $p->follow_ups === 1 && $p->followed_up_at
                    && $p->followed_up_at->copy()->setTimezone(self::TIMEZONE)->toDateString() < $local->toDateString();
                if ($p->follow_ups === 0 || $secondDue) {
                    $out[] = $p;
                }
                break; // numara başına bir mesaj
            }
        }

        return $out;
    }

    /** ① cevabın sonuna eklenen satırlar. */
    public static function inlineText(WhatsappPendingExpense $p): string
    {
        $gap = self::gap($p);

        return '⏳ Bir de yarım kalan var: *' . self::name($p) . "*\n" . $gap['why'] . ' ' . self::ask($gap);
    }

    /**
     * Eksik sebebi; AI taslakta bir şüphe sorusu bıraktıysa (`question`) "onay vermedin" değil o soru sorulur (K8 —
     * Akkuş 14.10: "tam anlayamadım, ayrı mı toplam mı?" diyen 25.113 taslağı hatırlatmada "evet"le yanlış kaydedildi).
     *
     * @return array{code:string,ask:string,why:string}
     */
    public static function gap(WhatsappPendingExpense $p): array
    {
        $gap = DraftGaps::reason($p->extracted);
        $question = trim((string) ($p->extracted['question'] ?? ''));
        if ($gap['code'] === DraftGaps::CONFIRM && $question !== '') {
            return ['code' => DraftGaps::DETAIL, 'ask' => '❓ ' . $question . ' Cevabını yaz.',
                'why' => 'Bir sorum vardı, cevaplanmadığı için kaydetmedim.'];
        }

        return $gap;
    }

    /** ② ayrı mesaj. */
    public static function messageText(WhatsappPendingExpense $p): string
    {
        $gap = self::gap($p);

        return "⏳ Yarım kalan 1 kaydın var:\n• " . self::name($p) . "\n" . $gap['why'] . "\n" . self::ask($gap);
    }

    /** Hatırlatıldı: sayaç + etkin taslak (sonraki cevap buna bağlanır). */
    public static function markSent(WhatsappPendingExpense $p): void
    {
        $p->follow_ups++;
        $p->followed_up_at = now();
        $p->updated_at = now();
        $p->save();
    }

    private static function open(?string $phone = null)
    {
        return WhatsappPendingExpense::query()
            ->when($phone !== null, fn ($q) => $q->where('phone', $phone))
            ->where('status', 'awaiting_confirmation')
            ->where('follow_ups', '<', self::MAX);
    }

    /**
     * Kullanıcı aynı kaydı sonra yeniden yazıp onayladıysa (aynı tür, tutar, kişi) eski taslağı sorma.
     * Silme alanı (geri al) taslakları da takip edilmez.
     */
    private static function closeIfDuplicate(WhatsappPendingExpense $p): bool
    {
        $d = $p->extracted ?? [];
        $kind = $d['kind'] ?? ExpenseExtractor::KIND_EXPENSE;
        $skip = in_array($kind, ['undo', 'return'], true);
        if (! $skip && (float) ($d['amount'] ?? 0) > 0) {
            $skip = WhatsappPendingExpense::where('phone', $p->phone)->where('status', 'confirmed')->where('id', '>', $p->id)
                ->get(['extracted'])
                ->contains(fn ($c) => ($c->extracted['kind'] ?? ExpenseExtractor::KIND_EXPENSE) === $kind
                    && abs((float) ($c->extracted['amount'] ?? 0) - (float) $d['amount']) < 0.01
                    && ($c->extracted['party_id'] ?? null) == ($d['party_id'] ?? null)
                    && mb_strtolower((string) ($c->extracted['party_name'] ?? '')) === mb_strtolower((string) ($d['party_name'] ?? '')));
        }
        if ($skip) {
            $p->update(['status' => 'superseded']);
        }

        return $skip;
    }

    private static function name(WhatsappPendingExpense $p): string
    {
        $label = DraftGaps::label($p->extracted ?? []);
        $date = $p->extracted['date'] ?? null;
        if ($date && $date !== now(self::TIMEZONE)->toDateString()) {
            $label .= ' (' . Carbon::parse($date)->locale('tr')->translatedFormat('j F') . ')';
        }

        return $label;
    }

    /** @param  array{code:string,ask:string,why:string}  $gap */
    private static function ask(array $gap): string
    {
        return $gap['code'] === DraftGaps::CONFIRM
            ? 'Kaydetmem için *evet*, gerek yoksa *iptal* yaz.'
            : $gap['ask'] . ' Gerek yoksa *iptal* yaz.';
    }
}
