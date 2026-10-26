<?php

namespace App\Support;

/**
 * Firmanın açık özellikleri — kodun "bu firmada X açık mı?" sorusunu sorduğu TEK yer.
 * Kod config('modules.*') okumaz, buradaki kapıyı çağırır; kural değişince yalnız bu dosya değişir.
 *
 * Plan (optimized-kindling-rain, 26.10): Çekirdek + 7 özellik (Gider, Yerler, Stok, Anlaşmalar, Hisse, Emlak,
 * Hayvanlarım). Faz A: her kapı bugünkü anahtarı birebir okur (davranış aynı — GateSnapshotTest).
 * Faz B'de kapılar özellik listesine bağlanır (ör. stockSales/stockPurchases/salesDesk → stock()).
 */
final class Features
{
    private static function on(string $key): bool
    {
        return (bool) config("modules.{$key}");
    }

    // ── Çekirdek (Faz B'de herkeste açık) ─────────────────────────────

    /** Caride tedarikçi tarafı: Alım Yaptım / Ödeme Yaptım, WhatsApp alış-ödeme-maaş. */
    public static function supplierSide(): bool
    {
        return self::on('cari_supplier');
    }

    public static function checks(): bool
    {
        return self::on('checks');
    }

    // ── Gider ─────────────────────────────────────────────────────────

    public static function expenses(): bool
    {
        return self::on('expenses');
    }

    // ── Yerler (proje / şube / şantiye / yer) ─────────────────────────

    public static function places(): bool
    {
        return self::on('projects');
    }

    /** Yerler alt kutusu: Raporlar (proje raporu). */
    public static function placeReports(): bool
    {
        return self::on('report_project');
    }

    /** Yerler alt kutusu: ana sayfa özeti. */
    public static function dashboard(): bool
    {
        return self::on('dashboard');
    }

    // ── Stok ──────────────────────────────────────────────────────────

    public static function stock(): bool
    {
        return self::on('stock');
    }

    /** Kalemli satış (ürün kartı, Satış Yaptım kalemli, iade). */
    public static function stockSales(): bool
    {
        return self::on('direct_sales');
    }

    /** Stoklu alış (Alışlar ekranı, Alım Yaptım stoklu). */
    public static function stockPurchases(): bool
    {
        return self::on('purchases');
    }

    /** Satışlar ekranı (peşin + cariye satış, toplu ciro). */
    public static function salesDesk(): bool
    {
        return self::on('sales_desk');
    }

    public static function stockReport(): bool
    {
        return self::on('report_stock');
    }

    public static function salesReport(): bool
    {
        return self::on('report_sales');
    }

    /**
     * Stok alt seçimi "Alışı yere yaz" (çiftlik, 26.10): ürünlü alış da seçilen yere yazılır (yem → ahır), yer sonucu
     * genel gideri dağıtmaz, yerin sezon sonucu gösterilir. Kapalıyken fırın düzeni: stok işletme geneli (16.10).
     */
    public static function placeCost(): bool
    {
        return self::on('place_cost') && self::stock() && self::places();
    }

    /** Ay Sonucu (ciro − alış − gider). */
    public static function monthlyResult(): bool
    {
        return self::on('report_monthly');
    }

    // ── Anlaşmalar, Hisse, Emlak ──────────────────────────────────────

    public static function agreements(): bool
    {
        return self::on('agreements');
    }

    public static function landShare(): bool
    {
        return self::on('land_share');
    }

    public static function propertyTax(): bool
    {
        return self::on('property_tax');
    }

    // ── Eski (yalnız açık olan firmada) ───────────────────────────────

    public static function contracts(): bool
    {
        return self::on('contracts');
    }

    public static function purchaseContracts(): bool
    {
        return self::on('purchase_contracts');
    }

    public static function salesContracts(): bool
    {
        return self::on('sales_contracts');
    }

    public static function quotes(): bool
    {
        return self::on('quotes');
    }

    // ── Asistan ayarları ──────────────────────────────────────────────

    public static function smartAssistant(): bool
    {
        return self::on('smart_assistant');
    }

    public static function dailyNudge(): bool
    {
        return self::on('daily_nudge');
    }

    public static function revenuePrompt(): bool
    {
        return self::on('revenue_prompt');
    }

    public static function naturalVoice(): bool
    {
        return self::on('natural_voice');
    }

    public static function proactiveAlerts(): bool
    {
        return self::on('proactive_alerts');
    }

    public static function fastSave(): bool
    {
        return self::on('fast_save');
    }

    public static function assistantPilot(): bool
    {
        return self::on('assistant_pilot');
    }
}
