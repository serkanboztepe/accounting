<?php

/**
 * Maliyet takibi fiyatları (USD). Fiyat değişirse burayı güncelle.
 *
 * AI: milyon token başına [giriş, çıkış] — Anthropic birinci taraf API fiyatları (2026-09-25 tablosu).
 * Önbellek: yazma = girişin 1,25 katı (5 dk), okuma = 0,1 katı.
 * WhatsApp: Twilio mesaj başı ücret (gelen + giden) ve bizim başlattığımız şablon mesajın
 * Meta ücreti (Türkiye "utility" — TAHMİNİ; ay sonu Twilio faturasıyla doğrula).
 */
return [
    'ai_per_mtok' => [
        'claude-sonnet-5' => [2.00, 10.00],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-opus-4-8' => [5.00, 25.00],
        'claude-opus-5' => [5.00, 25.00],
        'claude-opus-5-5' => [4.00, 20.00],
        'claude-haiku-4-5' => [1.00, 5.00],
    ],
    'cache_write_multiplier' => 1.25,
    'cache_read_multiplier' => 0.10,

    'twilio_per_message' => (float) env('COST_TWILIO_PER_MESSAGE', 0.005),
    'meta_template_utility' => (float) env('COST_META_TEMPLATE_UTILITY', 0.0053),
];
