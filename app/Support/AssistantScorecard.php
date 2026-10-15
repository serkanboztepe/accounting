<?php

namespace App\Support;

use App\Models\HubFirm;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Asistan karnesi (hub, Faz 0): firma firma son 7 gün — ne kadar yazıldı, ne kadarı kayda geçti, ne kadarı geri alındı /
 * iptal edildi / anlaşılmadı, cevap ne kadar sürdü (Twilio 15 sn bekler). Yalnız okuma; firma başına 10 dk önbellek.
 */
final class AssistantScorecard
{
    public const DAYS = 7;

    /** Twilio cevabı bu kadar bekler; aşan mesaja cevap gitmez. */
    public const TWILIO_LIMIT_SECONDS = 15;

    public static function overview(): array
    {
        $rows = HubFirm::where('is_active', true)->orderBy('name')->get()
            ->map(fn (HubFirm $f) => ['name' => $f->name] + (self::firm($f) ?? ['error' => true]))
            ->all();
        $ok = array_filter($rows, fn ($r) => empty($r['error']));
        $sum = fn (string $k) => array_sum(array_column($ok, $k));

        return [
            'days' => self::DAYS,
            'rows' => $rows,
            'total' => ['in' => $sum('in'), 'saved' => $sum('saved'), 'undo' => $sum('undo'), 'cancelled' => $sum('cancelled'),
                'help' => $sum('help'), 'errors' => $sum('errors'), 'open' => $sum('open'), 'slow' => $sum('slow'),
                'max' => $ok ? max(array_column($ok, 'max')) : null],
            'eval' => self::lastEval(),
        ];
    }

    public static function firm(HubFirm $firm): ?array
    {
        return Cache::remember('hub-scorecard:' . $firm->id, 600, function () use ($firm) {
            try {
                return Tenancy::run($firm, fn () => self::compute());
            } catch (Throwable) {
                return null;
            }
        });
    }

    /** Firma içinde çalışır (Tenancy::run). */
    public static function compute(): array
    {
        $since = now()->subDays(self::DAYS);
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

    /** Son deneme seti sonucu (yerelde `php artisan test --group=eval` yazar; canlıda yoksa null). */
    private static function lastEval(): ?array
    {
        $path = storage_path('app/eval/son.json');
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? ['at' => $data['tarih'] ?? null, 'summary' => $data['özet'] ?? []] : null;
    }
}
