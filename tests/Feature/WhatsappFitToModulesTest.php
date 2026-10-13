<?php

namespace Tests\Feature;

use App\Services\Whatsapp\ExpenseExtractor;
use Tests\TestCase;

/**
 * Carili kayıt gidere değil cariye (alış) yazılır — gider açık da olsa (Taner, 13 Ekim). Gider takibi kapalı
 * firmada gider taslağı kalmaz: carili → alış, adsız kişi → alış + soru, kişisiz → açıklama.
 */
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

    public function test_expenses_on_named_party_becomes_purchase_keeps_paid_and_project(): void
    {
        config(['modules.expenses' => true, 'modules.projects' => true]);
        $d = ExpenseExtractor::fitToModules($this->expense(['party_name' => 'Sinan Sele', 'paid' => true, 'project_id' => 5]));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $d['kind']);
        $this->assertTrue($d['paid']);
        $this->assertNull($d['category_name']);
        $this->assertSame(5, $d['project_id']); // projeli alış proje maliyetine bağlı kalır
    }

    public function test_expenses_on_party_without_projects_module_drops_project(): void
    {
        config(['modules.expenses' => true, 'modules.projects' => false]);
        $d = ExpenseExtractor::fitToModules($this->expense(['party_id' => 3, 'project_id' => 5]));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $d['kind']);
        $this->assertFalse($d['paid']); // ödendiği söylenmedi → ona borçlandın
        $this->assertNull($d['project_id']);
    }

    public function test_expenses_on_personless_stays_expense(): void
    {
        config(['modules.expenses' => true]);
        $d = ExpenseExtractor::fitToModules($this->expense(['category_name' => 'Yakıt']));
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $d['kind']);
        $this->assertSame('Yakıt', $d['category_name']);
    }

    /** Eski ödenmemiş carili giderin ödemesi gider yolunda kapanır (yeni alış açılmaz). */
    public function test_settling_old_party_expense_stays_expense(): void
    {
        config(['modules.expenses' => true]);
        $d = ExpenseExtractor::fitToModules($this->expense(['party_id' => 3, 'paid' => true, 'settles_expense_id' => 9]));
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $d['kind']);
        $this->assertSame(9, $d['settles_expense_id']);
    }

    /** Tedarikçi tarafı kapalı (mimar): alış türü yok → eski davranış. */
    public function test_supplier_side_off_keeps_party_expense(): void
    {
        config(['modules.expenses' => true, 'modules.cari_supplier' => false]);
        $d = ExpenseExtractor::fitToModules($this->expense(['party_name' => 'Ahmet']));
        $this->assertSame(ExpenseExtractor::KIND_EXPENSE, $d['kind']);
    }

    /** Stoklu alış açık firma (fırın): AI'ya basit alış gösterilmez ("20 çuval un" stoğa girmeyen alışa düşmesin), kural yine geçerli. */
    public function test_trade_firm_hides_simple_purchase_from_ai_but_keeps_rule(): void
    {
        config(['modules' => \App\Support\ModuleProfiles::resolve('trade')]);
        $this->assertContains(ExpenseExtractor::KIND_TRADE_PURCHASE, ExpenseExtractor::aiKinds());
        $this->assertNotContains(ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::aiKinds());
        $this->assertContains(ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::allowedKinds());

        $d = ExpenseExtractor::fitToModules($this->expense(['party_name' => 'Tamirci Ahmet', 'description' => 'Fırın tamiri']));
        $this->assertSame(ExpenseExtractor::KIND_PURCHASE, $d['kind']); // ürünsüz carili iş → cari alışı, gider değil
    }
}
