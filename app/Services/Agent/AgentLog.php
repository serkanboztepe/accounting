<?php

namespace App\Services\Agent;

use App\Tenancy\Tenancy;
use Throwable;

/**
 * Ajan turlarının kaydı (plan v5 §6): firma başına günlük JSONL — storage/app/wa-agent/{firma}/{gün}.jsonl. Her tur tek satır:
 * mesaj, mod (live / shadow), model, adımlar (araç + girdi + sonuç özeti), cevap, kart, onay, maliyet, süre. Yeni tablo yok.
 * Gölge değerlendirmesi, sabah dökümü ve ileride kademeli model düşürme bu kayıttan okur. Yazma hatası turu bozmaz.
 */
final class AgentLog
{
    public static function write(array $entry): void
    {
        try {
            $dir = self::dir();
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $line = json_encode(['at' => now('Europe/Istanbul')->toIso8601String()] + $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            file_put_contents($dir . '/' . now('Europe/Istanbul')->toDateString() . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // günlük yazılamadıysa tur yine tamamlanır
        }
    }

    /** @return list<array<string, mixed>> */
    public static function read(string $day): array
    {
        $file = self::dir() . '/' . $day . '.jsonl';
        if (! is_file($file)) {
            return [];
        }

        return array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []), 'is_array'));
    }

    /**
     * Sabah dökümü için ajan turları: saat, mesaj, kullandığı araçlar, süre, maliyet, hata; altında kısa cevap.
     *
     * @return list<string>
     */
    public static function dumpLines(\Illuminate\Support\Carbon $since): array
    {
        $day = $since->copy()->setTimezone('Europe/Istanbul')->startOfDay();
        $today = now('Europe/Istanbul')->startOfDay();
        $turns = [];
        for (; $day->lte($today); $day->addDay()) {
            array_push($turns, ...self::read($day->toDateString()));
        }
        $turns = array_values(array_filter($turns, fn ($t) => isset($t['at']) && \Illuminate\Support\Carbon::parse($t['at'])->gte($since)));
        if ($turns === []) {
            return [];
        }
        $errors = count(array_filter($turns, fn ($t) => ! empty($t['error'])));
        $seconds = array_filter(array_column($turns, 'seconds'));
        $lines = [sprintf('  AJAN: %d tur · hata %d · devir %d · en uzun %s sn · %.2f $', count($turns), $errors,
            count(array_filter($turns, fn ($t) => ! empty($t['outcome']['handover']))), $seconds === [] ? '—' : max($seconds), array_sum(array_column($turns, 'cost_usd')))];
        foreach ($turns as $t) {
            $tools = implode(',', array_filter(array_map(fn ($s) => $s['tool'] ?? null, (array) ($t['steps'] ?? []))));
            $lines[] = sprintf('    %s [%s] %s%s | %s | %s sn', substr((string) $t['at'], 5, 14), $t['mode'] ?? '?', ! empty($t['has_media']) ? '📎 ' : '',
                mb_substr((string) preg_replace('/\s+/u', ' ', (string) ($t['message'] ?? '')), 0, 100), $tools ?: 'araçsız', $t['seconds'] ?? '?');
            foreach (array_filter([! empty($t['error']) ? '❗ HATA: ' . $t['error'] : null, ! empty($t['outcome']['handover']) ? '↪ devir: ' . $t['outcome']['handover'] : null]) as $note) {
                $lines[] = '        ' . mb_substr((string) $note, 0, 200);
            }
            foreach ((array) ($t['replies'] ?? []) as $reply) {
                $lines[] = '        → ' . mb_substr((string) preg_replace('/\s+/u', ' ', is_array($reply) ? (string) $reply[0] : (string) $reply), 0, 240);
            }
        }

        return $lines;
    }

    public static function dir(): string
    {
        $base = (string) (config('agent.log_path') ?: storage_path('app/wa-agent'));

        return rtrim($base, '/') . '/' . (Tenancy::current()?->getKey() ?? 'tek');
    }

    /** Araç sonucunun günlükteki kısa hâli. */
    public static function brief(mixed $value, int $max = 1500): string
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
