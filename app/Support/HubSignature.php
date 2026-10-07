<?php

namespace App\Support;

/**
 * Hub → firma kurulumu imzası. Hub, Twilio'dan aldığı mesajı firmaya iletirken
 * firmaya özel gizli anahtarla imzalar; firma sadece Twilio'dan ya da imzası
 * doğru hub'dan gelen isteği kabul eder.
 *
 * İmza = base64(HMAC-SHA256(secret, zaman + "\n" + sıralı POST anahtar/değerleri)).
 * Zaman damgası 5 dakikadan eskiyse geçersiz (yeniden gönderme saldırısı olmasın).
 */
class HubSignature
{
    public const HEADER = 'X-Hub-Signature';

    public const TIMESTAMP_HEADER = 'X-Hub-Timestamp';

    private const MAX_AGE_SECONDS = 300;

    /** @return array<string, string> İletimde eklenecek başlıklar */
    public static function headers(array $params, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= now()->timestamp;

        return [
            self::HEADER => self::sign($params, $secret, $timestamp),
            self::TIMESTAMP_HEADER => (string) $timestamp,
        ];
    }

    public static function verify(array $params, string $secret, string $given, string $timestamp): bool
    {
        if ($secret === '' || $given === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($params, $secret, (int) $timestamp), $given);
    }

    private static function sign(array $params, string $secret, int $timestamp): string
    {
        ksort($params);
        $payload = $timestamp . "\n";
        foreach ($params as $key => $value) {
            $payload .= $key . (is_array($value) ? json_encode($value) : $value);
        }

        return base64_encode(hash_hmac('sha256', $payload, $secret, true));
    }
}
