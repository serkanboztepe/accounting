<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\EmployeeDraft;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\MonthlyResult;
use App\Support\ProjectCost;
use App\Support\SalaryFollowUp;
use App\Support\SalaryLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Maaş planı v4 (07.11): en eski borçtan kapatma, Kasım 2026 sınırı, harf duyarsız ay, kart = kayıt, ilk kayıt sorusu, aylık sonuç maaş
 * ayına göre, proje maliyetinde tek sayım, "ödeme görünmüyor" takibi. Örnekler canlı vakalardan (Yıldız 30.10, Akkuş Ekim/Kasım, Tok).
 */
class SalaryLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880077';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['report_monthly' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 77'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $this->at('2026-11-07 12:00');
    }

    /** Rakamsız ad (rakam "yazılan tutar" sayılır). */
    private static function letters(): string
    {
        return substr(str_shuffle('abcdefghijklmnoprstuvyz'), 0, 8);
    }

    private function at(string $istanbul): void
    {
        $this->travelTo(Carbon::parse($istanbul, 'Europe/Istanbul'));
    }

    private function employee(array $o = []): Party
    {
        return Party::create($o + ['name' => 'Maaş Testi ' . self::letters(), 'is_employee' => true, 'salary_amount' => 40000, 'salary_day' => 15,
            'salary_auto' => true])->fresh();
    }

    private function row(Party $p, string $type, string $date, string $description, float $amount, array $extra = []): PartyLedgerEntry
    {
        return PartyLedgerEntry::create(['party_id' => $p->id, 'type' => $type, 'entry_date' => $date, 'description' => $description,
            'amount' => $amount] + $extra);
    }

    private function remaining(Party $p, string $period): float
    {
        return SalaryLedger::state($p)['periods'][$period]['remaining'] ?? -1.0;
    }

    private function draft(array $o): array
    {
        return array_merge(['items' => [], 'amount' => 0, 'paid' => null, 'date' => now('Europe/Istanbul')->format('Y-m-d'), 'description' => '',
            'party_id' => null, 'party_name' => null, 'project_id' => null, 'project_name' => null, 'confidence' => 'high',
            'question' => null, 'is_new_entry' => true], $o);
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    // ── Ay okuma ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_month_is_read_case_insensitive_turkish(): void
    {
        $p = $this->employee();
        $cases = ['Maaş — KASIM 2026' => '2026-11', 'maaş - kasım 2026' => '2026-11', 'MAAŞ — NİSAN 2027' => '2027-04',
            'Maaş  –  Aralık 2026 (ilk ay)' => '2026-12', 'MAAS — ŞUBAT 2027' => '2027-02'];
        foreach ($cases as $text => $period) {
            $e = new PartyLedgerEntry(['type' => PartyLedgerEntry::TYPE_PURCHASE, 'description' => $text]);
            $this->assertSame($period, SalaryLedger::accrualPeriod($e), $text);
        }
        foreach (['Yevmiye — 5 Kasım', 'Maaş — test', 'Borç kaydı — maaş'] as $text) {
            $this->assertNull(SalaryLedger::accrualPeriod(new PartyLedgerEntry(['type' => PartyLedgerEntry::TYPE_PURCHASE, 'description' => $text])), $text);
        }
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-10-30', 'Maaş — KASIM 2026', 40000);
        $this->assertNotNull(EmployeeDraft::accrued($p, Carbon::parse('2026-11-15')));
        $this->assertNull(EmployeeDraft::accrued($p, Carbon::parse('2025-11-15')), 'yıl farklı');
    }

    /** Yıldız 30.10: panelde "Maaş — KASIM 2026" yazıldı → 15.11'de ikinci maaş yazılacaktı. */
    public function test_uppercase_month_row_prevents_double_accrual(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-10-25', 'Avans', 1000);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-10-30', 'Maaş — KASIM 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-10-30', 'Maaş ödemesi — KASIM 2026', 19000);

        $this->at('2026-11-15 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->artisan('salaries:accrue')->assertSuccessful();

        $this->assertSame(1, PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->count(), 'ikinci maaş yok');
        $this->assertEqualsWithDelta(20000, $this->remaining($p, '2026-11'), 0.01, 'Faruk: Kasım kalan 20.000');
        $this->assertStringContainsString('Kasım maaşı: maaş 40.000,00 ₺ − avans 20.000,00 = 20.000,00 ₺ ödemen gerekiyor',
            EmployeeDraft::reminderNote($p->fresh(), Carbon::parse('2026-11-15', 'Europe/Istanbul')));
    }

    /** Sonat: ödeme açıklaması "Ekim", maaş "KASIM" — sonuç yine Kasım kalan 20.000. */
    public function test_payment_labelled_other_month_flows_to_current_salary(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-10-30', 'Maaş — KASIM 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-10-30', 'Maaş ödemesi — Ekim 2026', 20000);

        $this->assertEqualsWithDelta(20000, $this->remaining($p, '2026-11'), 0.01);
        $this->assertSame(0.0, SalaryLedger::state($p)['legacy_open']);
    }

    // ── Kasım öncesi ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Akkuş Erkan: Ekim maaşı yazıldı, ödeme yok → Kasım ödemesi Ekim'i kapatmaz, ayrı satır. */
    public function test_legacy_unpaid_salary_is_shown_not_consumed(): void
    {
        $p = $this->employee(['salary_amount' => 65000, 'salary_day' => 30]);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-10-16', 'Maaş avansı', 5000);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-10-30', 'Maaş — Ekim 2026', 65000);
        $this->assertEqualsWithDelta(60000, SalaryLedger::state($p)['legacy_open'], 0.01);

        $this->at('2026-11-30 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $pv = SalaryLedger::preview($p, 65000, null, '2026-11-30');
        $this->assertContains('• Kasım maaşı: kapandı ✅', $pv['lines']);
        $this->assertContains("ℹ️ Ekim'den 60.000,00 ₺ açık görünüyor (bu ödemeye katmadım)", $pv['lines']);
        $this->assertSame('Maaş ödemesi — Kasım 2026', $pv['description']);

        // "Ekim'i de ödedim": ay söylenince eski borç kapanır.
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-30', 'Maaş ödemesi — Ekim 2026', 60000, ['salary_period' => '2026-10']);
        $this->assertSame(0.0, SalaryLedger::state($p)['legacy_open']);
        $this->assertEqualsWithDelta(65000, $this->remaining($p, '2026-11'), 0.01, 'Kasım hâlâ açık');
    }

    /** "Erkan'ın Ekim maaşını ödedim" (tutarsız): Ekim'den kalan 60.000 önerilir, kapanır; Kasım'a dokunulmaz. */
    public function test_whatsapp_naming_legacy_month_pays_legacy_open(): void
    {
        $p = $this->employee(['salary_amount' => 65000, 'salary_day' => 30]);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-10-16', 'Maaş avansı', 5000);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-10-30', 'Maaş — Ekim 2026', 65000);
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $p->id, 'paid' => true, 'month' => '2026-10']));

        $card = $this->send($p->name . ' Ekim maaşını ödedim');
        $this->assertStringContainsString('60.000,00 ₺ ödeme', $card);
        $this->assertStringContainsString('• Ekim maaşı: kapandı ✅', $card);
        $this->send('evet');
        $this->assertSame(0.0, SalaryLedger::state($p)['legacy_open']);
        $this->assertSame('2026-10', PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)
            ->where('amount', 60000)->sole()->salary_period);
    }

    /** Akkuş Özkan / Abdullah: Kasım öncesi avans Kasım maaşından düşer. */
    public function test_legacy_advance_carries_into_november(): void
    {
        $p = $this->employee(['salary_amount' => 50000, 'salary_day' => 8]);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-10-16', 'Maaş avansı', 25250);
        $this->at('2026-11-08 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->assertEqualsWithDelta(24750, $this->remaining($p, '2026-11'), 0.01);
        $this->assertEqualsWithDelta(24750, SalaryLedger::defaultAmount($p), 0.01, 'tutarsız "maaşı ödendi" = kalan');
    }

    // ── En eski borçtan ──────────────────────────────────────────────────────────────────────────────────────────────

    public function test_fifo_60_80_100(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-12-15', 'Maaş — Aralık 2026', 40000);

        $pv = SalaryLedger::preview($p, 60000, null, '2026-12-16');
        $this->assertSame(['• Kasım maaşı: kapandı ✅', '• Aralık maaşı: 20.000,00 ₺ ödendi, kalan 20.000,00 ₺'], $pv['lines']);
        $this->assertSame('Maaş ödemesi — Kasım + Aralık 2026', $pv['description']);

        $pv = SalaryLedger::preview($p, 80000, null, '2026-12-16');
        $this->assertSame(['• Kasım maaşı: kapandı ✅', '• Aralık maaşı: kapandı ✅'], $pv['lines']);

        $pv = SalaryLedger::preview($p, 100000, null, '2026-12-16');
        $this->assertContains('• 20.000,00 ₺ avans — Ocak maaşından düşülür', $pv['lines']);
        $this->assertSame('Maaş ödemesi — Kasım + Aralık 2026 (20.000,00 avans)', $pv['description']);
    }

    public function test_partial_payment_while_owed_is_not_advance(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-16', 'Avans', 19000);
        $this->assertEqualsWithDelta(21000, $this->remaining($p, '2026-11'), 0.01);
        $this->assertSame(0.0, SalaryLedger::state($p)['advance']);
    }

    public function test_advance_and_deduction_before_payday(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-03', 'Avans', 10000);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE_RETURN, '2026-11-10', 'Kesinti — 2 gün izin', 3000);
        $f = SalaryLedger::forecast($p);
        $this->assertEqualsWithDelta(27000, $f['net'], 0.01);
        $this->assertStringContainsString('maaş 40.000,00 ₺ − avans/kesinti 13.000,00 = 27.000,00 ₺ ödeyeceksin (tahmini)',
            EmployeeDraft::reminderNote($p, Carbon::parse('2026-11-15', 'Europe/Istanbul')));

        $this->at('2026-11-15 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->assertEqualsWithDelta(27000, $this->remaining($p, '2026-11'), 0.01);
        $this->assertEqualsWithDelta(27000, SalaryLedger::defaultAmount($p), 0.01);
    }

    public function test_full_advance_closes_month_when_accrued(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-05', 'Avans', 40000);
        $this->at('2026-11-15 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->assertSame(0.0, $this->remaining($p, '2026-11'));
    }

    public function test_named_future_month_payment_is_reserved_for_that_month(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-20', 'Maaş ödemesi — Aralık 2026', 40000, ['salary_period' => '2026-12']);
        $this->assertEqualsWithDelta(40000, $this->remaining($p, '2026-11'), 0.01, 'Aralık için verilen Kasım\'ı kapatmaz');
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-12-15', 'Maaş — Aralık 2026', 40000);
        $this->assertSame(0.0, $this->remaining($p, '2026-12'));
    }

    public function test_daily_wage_loan_and_on_behalf_are_outside_salary(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-16', 'Yevmiye ödemesi — 16 Kasım', 2000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-16', 'Borç verildi — maaştan önce', 5000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-16', 'Adına ödendi — maaş haczi', 3000);
        $this->assertEqualsWithDelta(40000, $this->remaining($p, '2026-11'), 0.01);
    }

    // ── WhatsApp: kart = kayıt ───────────────────────────────────────────────────────────────────────────────────────

    /** Yıldız 30.10: 19.000 dekont → "maaş mı avans mı?" yok; kısmi ödeme kalanıyla; "Maaş" cevabı tutarı ezmez. */
    public function test_whatsapp_payment_to_employee_partial_and_receipt_amount_kept(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->at('2026-11-16 12:00');
        $this->ai(
            $this->draft(['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_id' => $p->id, 'amount' => 19000, 'paid' => true,
                'question' => "Faruk Turan'a gönderdiğin 19.000 ₺ maaşı mı, yoksa avans mı?"]),
            $this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $p->id, 'amount' => 40000, 'paid' => true, 'is_new_entry' => false]),
        );

        $card = $this->send('19000 gönderdim');
        $this->assertStringContainsString('19.000,00 ₺ ödeme', $card);
        $this->assertStringContainsString('• Kasım maaşı: 19.000,00 ₺ ödendi, kalan 21.000,00 ₺', $card);
        $this->assertStringNotContainsString('avans mı', $card);
        $this->assertStringNotContainsString('Hangi', $card, 'proje sorulmaz');

        $card = $this->send('Maaş');
        $this->assertStringContainsString('19.000,00 ₺ ödeme', $card, 'dekont tutarı korunur');

        $this->assertStringContainsString('Kasım maaşından 21.000,00 ₺ kaldı', $this->send('evet'));
        $row = PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->sole();
        $this->assertEqualsWithDelta(19000, (float) $row->amount, 0.01);
        $this->assertSame('Maaş ödemesi — Kasım 2026', $row->description);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->count(), 'ödeme maaş satırı yazmaz');
    }

    /** "Maaşı ödendi" (rakamsız): maaş − avans; AI'ın hatırlatmadan aldığı sayı kullanılmaz. */
    public function test_whatsapp_salary_without_number_pays_remaining(): void
    {
        $p = $this->employee(['salary_amount' => 55000]);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-10', 'Avans', 5500);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 55000);
        $this->at('2026-11-15 12:00');
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $p->id, 'amount' => 55000, 'paid' => true]));

        $card = $this->send($p->name . ' maaşı ödendi');
        $this->assertStringContainsString('49.500,00 ₺ ödeme', $card);
        $this->assertStringContainsString('• Kasım maaşı: kapandı ✅', $card);
        $this->send('evet');
        $this->assertSame(0.0, $this->remaining($p, '2026-11'));
    }

    /** Onay beklerken başka kayıt girdi → yazılmaz, güncel kart. */
    public function test_stale_card_is_not_written(): void
    {
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->at('2026-11-16 12:00');
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_SALARY, 'party_id' => $p->id, 'amount' => 30000, 'paid' => true]));
        $this->send('30 bin verdim');
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-16', 'Avans', 20000); // panelden araya giren

        $reply = $this->send('evet');
        $this->assertStringContainsString('hesap değişti', $reply);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->count());
        $this->assertStringContainsString('10.000,00 ₺ avans', $reply, 'yeni kart fazlayı gösterir (Kasım kalan 20.000)');
    }

    // ── İlk kayıt ────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_new_employee_first_month_question_and_auto_on(): void
    {
        $name = 'Maaş Testi Yeni ' . self::letters();
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_EMPLOYEE_SETTING, 'party_name' => $name, 'salary_amount' => 40000, 'salary_day' => 15]));

        $card = $this->send("$name personelim 40 bin ayın 15'i");
        $this->assertStringContainsString('Maaş gününe 8 gün var, bu ay için ne yapayım?', $card);
        $this->assertStringContainsString('*2* 8 günlük maaş yaz (10.666,67 ₺), ödemedim', $card);
        $this->assertStringContainsString('bir gün önce ve o sabah 08:00', $card);

        $card = $this->send('2');
        $this->assertStringContainsString('İlk maaş: 15 Kasım\'da 10.666,67 ₺ (8 gün)', $card);
        $this->send('evet');

        $p = Party::where('name', $name)->sole();
        $this->assertTrue($p->salary_auto, 'WhatsApp\'tan açılan personelde kendiliğinden işle açık (Tok 05.11)');
        $this->assertSame('2026-11', $p->salary_first_month);
        $this->assertEqualsWithDelta(10666.67, $p->salary_first_amount, 0.01);

        $this->at('2026-11-15 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $row = PartyLedgerEntry::where('party_id', $p->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->sole();
        $this->assertEqualsWithDelta(10666.67, (float) $row->amount, 0.01);
        $this->assertSame('Maaş — Kasım 2026 (ilk ay)', $row->description);
        $this->assertSame('2026-11', $row->salary_period);
    }

    public function test_registered_on_payday_no_question_and_this_month_skipped(): void
    {
        $this->at('2026-11-05 13:57');
        $name = 'Maaş Testi Şoför ' . self::letters();
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_EMPLOYEE_SETTING, 'party_name' => $name, 'salary_amount' => 60000, 'salary_day' => 5]));
        $card = $this->send("$name bugün işe başladı 60 bin ayın 5'i");
        $this->assertStringNotContainsString('ne yapayım', $card);
        $this->send('evet');
        $p = Party::where('name', $name)->sole();
        $this->assertSame('2026-12', $p->salary_first_month);

        $this->at('2026-11-06 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $p->id)->count(), 'aynı ay telafiyle de yazılmaz');
        $this->at('2026-12-05 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $p->id)->count());
    }

    public function test_choice_skip_and_after_payday_asks_for_next_date(): void
    {
        $this->at('2026-11-20 12:00');
        $name = 'Maaş Testi Geç ' . self::letters();
        $this->ai($this->draft(['kind' => ExpenseExtractor::KIND_EMPLOYEE_SETTING, 'party_name' => $name, 'salary_amount' => 40000, 'salary_day' => 15]));
        $this->assertStringContainsString("*3* 15 Aralık'ta tam maaş yaz", $this->send("$name personel 40 bin ayın 15'i"));
        $this->assertStringContainsString('Bu ay maaş yazılmaz — ilk maaş 15 Ocak\'ta', $this->send('1'));
        $this->send('evet');
        $p = Party::where('name', $name)->sole();
        $this->assertSame('2026-12', $p->salary_first_month);
        $this->assertSame(0.0, (float) $p->salary_first_amount);
    }

    public function test_job_catches_up_missed_day_in_same_month_only(): void
    {
        $p = $this->employee(['salary_day' => 5, 'salary_first_month' => '2026-11']);
        $this->at('2026-11-07 07:50');
        $this->artisan('salaries:accrue')->assertSuccessful();
        $this->assertNotNull(EmployeeDraft::accrued($p, Carbon::parse('2026-11-05')), '5\'inde kaçtı, 7\'sinde yazıldı');
        $this->assertSame('2026-11-05', PartyLedgerEntry::where('party_id', $p->id)->sole()->entry_date->toDateString());
    }

    // ── Rapor ────────────────────────────────────────────────────────────────────────────────────────────────────────

    /** Kullanıcı 07.11: maaş kendi ayında ("Kasım maaşı" 30 Ekim'de girildiyse Kasım'da). */
    public function test_month_result_counts_salary_in_its_own_month(): void
    {
        $feb = MonthlyResult::forMonth(Carbon::parse('2031-02-10'));
        $mar = MonthlyResult::forMonth(Carbon::parse('2031-03-10'));
        $p = $this->employee(['salary_day' => 5]);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2031-02-27', 'Maaş — Mart 2031', 40000);

        $this->assertEqualsWithDelta($feb['personnel'], MonthlyResult::forMonth(Carbon::parse('2031-02-10'))['personnel'], 0.01);
        $this->assertEqualsWithDelta($mar['personnel'] + 40000, MonthlyResult::forMonth(Carbon::parse('2031-03-10'))['personnel'], 0.01);
    }

    /** Akkuş 31.10: ödeme yokken "Bugün maaş ödendi 133.000" dedi. */
    public function test_day_line_says_accrued_not_paid(): void
    {
        $day = Carbon::parse('2031-04-05');
        $p = $this->employee(['salary_day' => 5]);
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2031-04-05', 'Maaş — Nisan 2031', 40000);
        $text = MonthlyResult::whatsappText(MonthlyResult::forRange($day, $day));
        $this->assertStringContainsString('👷 Bugün maaş işlendi (' . $p->name . '): 40.000,00 ₺ — henüz ödenmedi', $text);
        $this->assertStringNotContainsString('Bugün maaş ödendi', $text);
    }

    public function test_project_cost_does_not_count_payroll_payment_twice(): void
    {
        $project = \App\Models\Project::create(['name' => 'Maaş Testi Proje ' . uniqid(), 'status' => 'active']);
        $p = $this->employee();
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);
        $this->row($p, PartyLedgerEntry::TYPE_PAYMENT, '2026-11-16', 'Maaş ödemesi — Kasım 2026', 40000, ['project_id' => $project->id]);
        $this->assertSame(0.0, ProjectCost::unbookedPayments($project->id));
    }

    // ── Ödeme görünmüyor ────────────────────────────────────────────────────────────────────────────────────────────

    private function followed(): Party
    {
        $p = $this->employee();
        EmployeeDraft::syncReminder($p, '905558880077');
        $this->row($p, PartyLedgerEntry::TYPE_PURCHASE, '2026-11-15', 'Maaş — Kasım 2026', 40000);

        return $p;
    }

    public function test_followup_asks_two_days_after_and_weekly_then_stops(): void
    {
        $p = $this->followed();
        $this->at('2026-11-16 08:10');
        $this->assertFalse(SalaryFollowUp::due()->has('905558880077'), '1 gün sonra sorulmaz');

        foreach (['2026-11-17', '2026-11-24', '2026-12-01'] as $day) {
            $this->at($day . ' 08:10');
            $items = SalaryFollowUp::due()->get('905558880077');
            $this->assertNotNull($items, $day);
            $this->assertStringContainsString('*1* Ödedim (40.000,00 ₺)', SalaryFollowUp::text($items));
            SalaryFollowUp::markAsked('905558880077', $items, 'soru');
            $this->at($day . ' 08:10');
            $this->assertFalse(SalaryFollowUp::due()->has('905558880077'), 'aynı hafta tekrar yok');
        }
        $this->at('2026-12-08 08:10');
        $this->assertFalse(SalaryFollowUp::due()->has('905558880077'), '3 cevapsız → durur');
        $this->assertNotEmpty(SalaryFollowUp::stoppedLines());
    }

    public function test_followup_answers_paid_later_and_stop(): void
    {
        $p = $this->followed();
        $this->at('2026-11-17 08:10');
        SalaryFollowUp::markAsked('905558880077', SalaryFollowUp::due()->get('905558880077'), 'soru');
        $this->at('2026-11-17 13:00');

        $card = $this->send('1');
        $this->assertStringContainsString('40.000,00 ₺ ödeme', $card);
        $this->assertStringContainsString('• Kasım maaşı: kapandı ✅', $card);
        $this->send('evet');
        $this->assertSame(0.0, $this->remaining($p, '2026-11'));
        $this->at('2026-11-24 08:10');
        $this->assertFalse(SalaryFollowUp::due()->has('905558880077'), 'ödendi → takip biter');

        $q = $this->followed();
        $q->update(['salary_followup' => null]);
        $this->at('2026-11-17 09:00');
        SalaryFollowUp::markAsked('905558880077', SalaryFollowUp::due()->get('905558880077'), 'soru');
        $this->assertStringContainsString('bir daha sormayacağım', $this->send('3'));
        $this->at('2026-12-20 08:10');
        $this->assertFalse(SalaryFollowUp::due()->has('905558880077'));
    }
}
