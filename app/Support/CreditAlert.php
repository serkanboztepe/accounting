<?php

namespace App\Support;

use App\Services\Whatsapp\WhatsappSender;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic kredisi uyarısı (Faz 5, 17.10 — 16.10 akşamı kredi bitti, 6 mesaj cevapsız kaldı, kimse fark etmedi).
 *  - API "credit balance is too low" derse → yöneticiye (WHATSAPP_ADMIN_PHONES) hemen WhatsApp, saatte en çok 1;
 *  - hub'daki tahmini bakiye 5 $ altına inince → günde en çok 1 (ai:credit-check, saatlik).
 * Uyarı merkezde tekildir (firma anahtarı değil): kredi bütün firmaların ortak hesabı.
 */
final class CreditAlert
{
    public const LOW_USD = 5.0;

    /** API hata gövdesi kredi bitti diyorsa uyar. Asıl akışı asla bozmaz. */
    public static function fromApiError(string $body): void
    {
        if (! str_contains($body, 'credit balance is too low')) {
            return;
        }
        self::send('ai-credit-empty', 3600, "🔴 *Anthropic kredisi bitti* — asistan şu an müşterilere cevap veremiyor.\n"
            . "console.anthropic.com → Plans & Billing'den yükleyin; sonra hub → Toplu Maliyet'e yeni bakiyeyi girin.");
    }

    /** Tahmini kalan bakiye düşükse uyar (zamanlanmış kontrol). */
    public static function checkEstimate(): ?float
    {
        $credit = HubCosts::credit();
        if ($credit === null) {
            return null;
        }
        $remaining = (float) $credit['remaining'];
        if ($remaining < self::LOW_USD) {
            self::send('ai-credit-low', 86400, '🟠 *Anthropic kredisi azalıyor*: tahmini kalan ' . Money::format($remaining) . " \$.\n"
                . "Bitmeden yükleyin (console.anthropic.com → Plans & Billing); yükleyince hub → Toplu Maliyet'e yeni bakiyeyi girin.");
        }

        return $remaining;
    }

    private static function send(string $key, int $seconds, string $text): void
    {
        try {
            if (! Cache::add($key, true, $seconds)) {
                return;
            }
            foreach ((array) config('services.whatsapp.admin_phones', []) as $phone) {
                app(WhatsappSender::class)->sendText($phone, $text);
            }
            Log::warning('Kredi uyarısı gönderildi', ['key' => $key]);
        } catch (\Throwable $e) {
            Log::warning('Kredi uyarısı gönderilemedi', ['msg' => $e->getMessage()]);
        }
    }
}
