<?php

namespace Tests\Feature;

use App\Services\Whatsapp\ExpenseExtractor;
use Tests\TestCase;

/** Gider takibi kapalı firmada gider taslağı kalmaz: carili → alış, adsız kişi → alış + soru, kişisiz → açıklama. */
class WhatsappFitToModulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.expenses' => false, 'modules.cari_supplier' => true]);
    }

    private function expense(array $o): array
    {
        return $o + ['kind' => ExpenseExtractor::KIND_EXPENSE, 'amount' => 12000, 'party_id' => null, 'party_name' => null,
            'question' => null, 'paid' => null, 'category_name' => 'Yem', 'project_id' => null];
    }

    public function test_named_supplier_becomes_purchase(): void
    {
        $d = ExpenseExtractor::fitToModules($this->expense(['party_name' => 'Yemci Hasan']));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $d['kind']);
        $this->assertNull($d['category_name']);
        $this->assertFalse($d['paid']);
    }

    /** Erkan (12 Ekim): "Yemciden 12 bin yem aldım" → "harcama takibi kapalı" deniyordu; "Hangi yemci?" sorulmalı. */
    public function test_unnamed_supplier_question_becomes_purchase_and_keeps_question(): void
    {
        $d = ExpenseExtractor::fitToModules($this->expense(['question' => 'Hangi yemci? Adını yazar mısın?']));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $d['kind']);
        $this->assertSame('Hangi yemci? Adını yazar mısın?', $d['question']);
    }

    public function test_personless_expense_explains_closed_module(): void
    {
        $d = ExpenseExtractor::fitToModules($this->expense(['category_name' => 'Yakıt']));
        $this->assertSame(ExpenseExtractor::KIND_HELP, $d['kind']);
        $this->assertStringContainsString('harcama takibi kapalı', $d['reply']);
    }
}
