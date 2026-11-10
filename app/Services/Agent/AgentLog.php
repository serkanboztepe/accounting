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
