<?php

namespace Tests\Unit\LandShare;

use App\Services\LandShare\ShareCalculator;
use App\Services\LandShare\ShareResult;
use App\Services\LandShare\StudyData;
use App\Services\LandShare\StudyValidator;
use App\Support\Fraction;
use PHPUnit\Framework\TestCase;

class ShareCalculatorTest extends TestCase
{
    private function remaining(ShareResult $r, string $key): string
    {
        foreach ($r->rows as $row) {
            if ($row->key === $key) {
                return (string) $row->remaining;
            }
        }

        return '(bulunamadı)';
    }

    private function sold(ShareResult $r, string $key): string
    {
        foreach ($r->rows as $row) {
            if ($row->key === $key) {
                return (string) $row->sold;
            }
        }

        return '(bulunamadı)';
    }

    /**
     * Referans Excel — Erzincan Kavakyolu 298 Ada / 8 Parsel, A+B Blok,
     * 24 bağımsız bölüm, BLOK/DAİRE yöntemi. Sonuçlar "2-Tapu Çıktısı"
     * sayfasıyla birebir eşleşmeli.
     */
    public function test_blok_daire_matches_reference_excel(): void
    {
        $data = $this->erzincanStudy();
        $result = (new ShareCalculator())->calculate($data, ShareCalculator::BLOK_DAIRE);

        // Kalan (satış sonu) hisseler — Tapu Çıktısı L/M sütunları.
        $this->assertSame('5/48', $this->remaining($result, 'enes'));
        $this->assertSame('1/16', $this->remaining($result, 'recep'));
        $this->assertSame('1/16', $this->remaining($result, 'pehlul'));
        $this->assertSame('1/64', $this->remaining($result, 'sultan'));
        $this->assertSame('1/64', $this->remaining($result, 'batuhan'));
        $this->assertSame('1/64', $this->remaining($result, 'emirhan'));
        $this->assertSame('1/64', $this->remaining($result, 'aslihan'));
        $this->assertSame('17/24', $this->remaining($result, 'erdal'));

        // Satılan hisseler — Tapu Çıktısı G sütunu.
        $this->assertSame('7/48', $this->sold($result, 'enes'));
        $this->assertSame('3/16', $this->sold($result, 'recep'));
        $this->assertSame('3/64', $this->sold($result, 'sultan'));
        $this->assertSame('0', $this->sold($result, 'erdal')); // müteahhit satmaz, devralır

        // Matematiksel tutarlılık: kalanlar 1/1, satılan toplam = müteahhidin aldığı.
        $this->assertTrue($result->isBalanced(), 'Kalan hisseler toplamı 1/1 olmalı.');
        $this->assertSame('17/24', (string) $result->totalSold());
    }

    /** ARSA PAYLI — büyük dükkan (3/4) küçük daireden (1/4) ağır basar. */
    public function test_arsa_payli_weights_sections_by_land_share(): void
    {
        $data = new StudyData(
            shareholders: [
                ['key' => 'ali', 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2],
                ['key' => 'veli', 'name' => 'Veli', 'current_pay' => 1, 'current_payda' => 2],
            ],
            allocations: [
                ['section' => 'D1', 'holder' => 'ali', 'pay' => 1, 'payda' => 1],
                ['section' => 'D2', 'holder' => 'veli', 'pay' => 1, 'payda' => 1],
            ],
            sections: [
                'D1' => ['arsa_pay' => 3, 'arsa_payda' => 4],
                'D2' => ['arsa_pay' => 1, 'arsa_payda' => 4],
            ],
        );

        $result = (new ShareCalculator())->calculate($data, ShareCalculator::ARSA_PAYLI);

        $this->assertSame('3/4', $this->remaining($result, 'ali'));
        $this->assertSame('1/4', $this->remaining($result, 'veli'));
        $this->assertTrue($result->isBalanced());
    }

    /** Aynı veri BLOK/DAİRE ile eşit dağıtılır: her biri 1/2. */
    public function test_blok_daire_treats_sections_equally(): void
    {
        $data = new StudyData(
            shareholders: [
                ['key' => 'ali', 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2],
                ['key' => 'veli', 'name' => 'Veli', 'current_pay' => 1, 'current_payda' => 2],
            ],
            allocations: [
                ['section' => 'D1', 'holder' => 'ali', 'pay' => 1, 'payda' => 1],
                ['section' => 'D2', 'holder' => 'veli', 'pay' => 1, 'payda' => 1],
            ],
        );

        $result = (new ShareCalculator())->calculate($data, ShareCalculator::BLOK_DAIRE);

        $this->assertSame('1/2', $this->remaining($result, 'ali'));
        $this->assertSame('1/2', $this->remaining($result, 'veli'));
    }

    /** GRUP — grubun daire hakkı, üyelerin mevcut hissesine göre bölünür. */
    public function test_grup_splits_group_credits_by_current_share(): void
    {
        $data = new StudyData(
            shareholders: [
                ['key' => 'ali', 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2, 'group_key' => 'G1'],
                ['key' => 'veli', 'name' => 'Veli', 'current_pay' => 1, 'current_payda' => 2, 'group_key' => 'G1'],
            ],
            // 4 BB var; grup dağılımda BB anahtarı önemli değil, sectionCount 4 olsun.
            allocations: [
                ['section' => 'D1', 'holder' => 'ali', 'pay' => 1, 'payda' => 1],
                ['section' => 'D2', 'holder' => 'ali', 'pay' => 1, 'payda' => 1],
                ['section' => 'D3', 'holder' => 'veli', 'pay' => 1, 'payda' => 1],
                ['section' => 'D4', 'holder' => 'veli', 'pay' => 1, 'payda' => 1],
            ],
            groups: ['G1' => 4], // grup 4 daire hakkı alır
        );

        $result = (new ShareCalculator())->calculate($data, ShareCalculator::GRUP);

        $this->assertSame('1/2', $this->remaining($result, 'ali'));
        $this->assertSame('1/2', $this->remaining($result, 'veli'));
        $this->assertTrue($result->isBalanced());
    }

    public function test_validator_detects_available_methods(): void
    {
        $validator = new StudyValidator();

        // Arsa payı yok → sadece BLOK/DAİRE.
        $blokOnly = new StudyData(
            shareholders: [
                ['key' => 'ali', 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 1],
            ],
            allocations: [
                ['section' => 'D1', 'holder' => 'ali', 'pay' => 1, 'payda' => 1],
            ],
        );

        $this->assertSame(ShareCalculator::BLOK_DAIRE, $validator->defaultMethod($blokOnly));

        // Arsa payı tam → akıllı varsayılan ARSA PAYLI.
        $withArsa = new StudyData(
            shareholders: [
                ['key' => 'ali', 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 1],
            ],
            allocations: [
                ['section' => 'D1', 'holder' => 'ali', 'pay' => 1, 'payda' => 1],
            ],
            sections: [
                'D1' => ['arsa_pay' => 1, 'arsa_payda' => 1],
            ],
        );

        $this->assertSame(ShareCalculator::ARSA_PAYLI, $validator->defaultMethod($withArsa));
    }

    public function test_current_shares_incomplete_is_flagged(): void
    {
        $validator = new StudyValidator();

        $incomplete = new StudyData(shareholders: [
            ['key' => 'ali', 'name' => 'Ali', 'current_pay' => 1, 'current_payda' => 2],
        ]);

        $this->assertFalse($validator->currentSharesComplete($incomplete));
    }

    /** Erzincan verisi: 8 hissedar + 24 BB dağılımı (Excel satır 18-68). */
    private function erzincanStudy(): StudyData
    {
        $shareholders = [
            ['key' => 'enes', 'name' => 'Enes Perçem', 'current_pay' => 1, 'current_payda' => 4],
            ['key' => 'recep', 'name' => 'Recep Perçem', 'current_pay' => 1, 'current_payda' => 4],
            ['key' => 'pehlul', 'name' => 'Pehlül Perçem', 'current_pay' => 1, 'current_payda' => 4],
            ['key' => 'sultan', 'name' => 'Sultan Perçem', 'current_pay' => 1, 'current_payda' => 16],
            ['key' => 'batuhan', 'name' => 'Batuhan Perçem', 'current_pay' => 1, 'current_payda' => 16],
            ['key' => 'emirhan', 'name' => 'Emirhan Perçem', 'current_pay' => 1, 'current_payda' => 16],
            ['key' => 'aslihan', 'name' => 'Aslıhan Perçem Düzenli', 'current_pay' => 1, 'current_payda' => 16],
            ['key' => 'erdal', 'name' => 'Erdal İlter İnşaat Ltd. Şti.', 'current_pay' => 0, 'current_payda' => 1, 'is_contractor' => true],
        ];

        $a = fn (string $section, string $holder, int $pay, int $payda) => compact('section', 'holder', 'pay', 'payda');

        $allocations = [
            // A1 — 7 kişi paylaşımlı (mevcut hisse yapısıyla aynı)
            $a('A1', 'enes', 1, 4), $a('A1', 'recep', 1, 4), $a('A1', 'pehlul', 1, 4),
            $a('A1', 'sultan', 1, 16), $a('A1', 'batuhan', 1, 16), $a('A1', 'emirhan', 1, 16), $a('A1', 'aslihan', 1, 16),
            // A2-A4 müteahhit
            $a('A2', 'erdal', 1, 1), $a('A3', 'erdal', 1, 1), $a('A4', 'erdal', 1, 1),
            // A5 Enes
            $a('A5', 'enes', 1, 1),
            // A6 — 4 kişi 1/4
            $a('A6', 'aslihan', 1, 4), $a('A6', 'sultan', 1, 4), $a('A6', 'batuhan', 1, 4), $a('A6', 'emirhan', 1, 4),
            // A7-A12 müteahhit
            $a('A7', 'erdal', 1, 1), $a('A8', 'erdal', 1, 1), $a('A9', 'erdal', 1, 1),
            $a('A10', 'erdal', 1, 1), $a('A11', 'erdal', 1, 1), $a('A12', 'erdal', 1, 1),
            // B1-B2 müteahhit
            $a('B1', 'erdal', 1, 1), $a('B2', 'erdal', 1, 1),
            // B3 — 7 kişi paylaşımlı
            $a('B3', 'enes', 1, 4), $a('B3', 'recep', 1, 4), $a('B3', 'pehlul', 1, 4),
            $a('B3', 'sultan', 1, 16), $a('B3', 'batuhan', 1, 16), $a('B3', 'emirhan', 1, 16), $a('B3', 'aslihan', 1, 16),
            // B4-B5 müteahhit
            $a('B4', 'erdal', 1, 1), $a('B5', 'erdal', 1, 1),
            // B6 Pehlül, B7 Recep
            $a('B6', 'pehlul', 1, 1), $a('B7', 'recep', 1, 1),
            // B8-B9 müteahhit
            $a('B8', 'erdal', 1, 1), $a('B9', 'erdal', 1, 1),
            // B10 Enes
            $a('B10', 'enes', 1, 1),
            // B11-B12 müteahhit
            $a('B11', 'erdal', 1, 1), $a('B12', 'erdal', 1, 1),
        ];

        return new StudyData(shareholders: $shareholders, allocations: $allocations);
    }
}
