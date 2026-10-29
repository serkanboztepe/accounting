<?php

namespace App\Support;

use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;

/**
 * Asistan karnesi: bir firmada belli bir andan beri ne kadar yazıldı, ne kadarı kayda geçti, ne kadarı geri alındı /
 * iptal edildi / anlaşılmadı, cevap ne kadar sürdü (Twilio 15 sn bekler). Yalnız okuma. Hub sayfası 29.10'da kalktı;
 * rakamlar sabah dökümünün (conversations:dump) başında.
 */
final class AssistantScorecard
{
    public const DAYS = 7;

    /** Twilio cevabı bu kadar bekler; aşan mesaja cevap gitmez. */
    public const TWILIO_LIMIT_SECONDS = 15;

    /** Firma içinde çalışır (Tenancy::run). $since verilmezse son 7 gün. */
    public static function compute(?\Carbon\CarbonInterface $since = null): array
    {
        $since ??= now()->subDays(self::DAYS);
        $in = WhatsappMessage::where('direction', 'in')->where('created_at', '>=', $since);
        $drafts = WhatsappPendingExpense::where('updated_at', '>=', $since);

        $durations = self::durations($since);
        sort($durations);
        $n = count($durations);

        return [
            'in' => (clone $in)->count(),
            'saved' => (clone $drafts)->where('status', 'confirmed')->count(),
            'undo' => (clone $in)->where('kind', 'undo')->count(),
            'cancelled' => (clone $drafts)->where('status', 'cancelled')->count(),
            'help' => (clone $in)->where('kind', 'help')->count(),
            'errors' => (clone $in)->where('kind', 'error')->count(),
            'open' => WhatsappPendingExpense::where('status', 'awaiting_confirmation')->where('created_at', '>=', $since)
                ->where('created_at', '<', now()->subHour())->count(),
            'median' => $n ? $durations[intdiv($n, 2)] : null,
            'max' => $n ? end($durations) : null,
            'slow' => count(array_filter($durations, fn ($s) => $s > self::TWILIO_LIMIT_SECONDS)),
        ];
    }

    /** @return list<int> gelen mesaj → ilk cevap arası saniye (kayıt saatleri saniye hassasiyetinde). */
    private static function durations($since): array
    {
        $open = [];
        $out = [];
        foreach (WhatsappMessage::where('created_at', '>=', $since)->orderBy('id')->get(['phone', 'direction', 'created_at']) as $m) {
            if ($m->direction === 'in') {
                $open[$m->phone] = $m->created_at;
            } elseif (isset($open[$m->phone])) {
                $out[] = (int) $open[$m->phone]->diffInSeconds($m->created_at);
                unset($open[$m->phone]);
            }
        }

        return $out;
    }
}
