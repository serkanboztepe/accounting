<?php

namespace App\Services\Whatsapp;

/**
 * Bir WhatsApp turunun teşhis kimliği (TurnTrace). Arka plan devamları (ertelenen tur, sıradaki kalem) bu nesneyi
 * closure'a alıp açıkça taşır — dosya yolu buradaki firma/tur kimliğinden kurulur, o anki firmadan değil.
 */
final class TraceContext
{
    /** Bu turda açılan son bölüm numarası (1 = isteğin kendisi). */
    public int $lastSeq = 0;

    /** Henüz yazılmamış ama beklenen devam bölümü sayısı (ertelenen tur). */
    public int $expected = 0;

    public function __construct(
        public readonly string $firmId,
        public readonly string $turnId,
        public readonly string $phone,
        public readonly string $day,
    ) {}
}
