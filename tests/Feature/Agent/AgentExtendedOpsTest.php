<?php

namespace Tests\Feature\Agent;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\WhatsappPendingExpense;
use App\Services\Agent\Ops\AgentProposal;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use App\Support\SalaryLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Ajanın eski asistana DEVRETMEDEN yazdığı işler (10.11, kullanıcı: "hepsini ajan yapsın"): ürünlü satış, kasa, maaş, personel,
 * hatırlatma, düzenli gider. Kayıt kuralları yeniden yazılmadı — kart, hesap ve kayıt eski taslak sınıflarından (TradeDraft,
 * EmployeeDraft, RecurringDraft, Reminders) gelir; burada o yolun ajan önerisiyle uçtan uca çalıştığı doğrulanır.
 */
class AgentExtendedOpsTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880044';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true]), 'app.profile' => 'trade']);
        $this->travelTo(now('Europe/Istanbul')->setDate(2026, 11, 10)->setTime(12, 0)->setTimezone(config('app.timezone')));
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function save(string $raw, array $entry): array
    {
        $p = AgentProposal::propose(self::PHONE, [['type' => 'record', 'entry' => $entry]], 'test');
        $this->assertTrue($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));
        AgentProposal::markSent($p['proposal_id']);

        return AgentProposal::confirm(self::PHONE, $p['proposal_id'], $raw) + ['card' => $p['card']];
    }

    private function issue(array $entry): string
    {
        $p = AgentProposal::propose(self::PHONE, [['type' => 'record', 'entry' => $entry]], 'test');
        $this->assertFalse($p['ok'], json_encode($p, JSON_UNESCAPED_UNICODE));

        return $p['issues'][0];
    }

    /** Akkuş: "bugün 12 bin nakit 8 bin kart" — eskiden devrediliyordu. */
    public function test_daily_till_is_recorded_as_a_cash_sale_document(): void
    {
        $sales = Sale::count();
        $r = $this->save('evet', ['kind' => 'trade_sale', 'amount' => 20000, 'cash_amount' => 12000, 'card_amount' => 8000, 'description' => 'Günlük ciro']);

        $this->assertTrue($r['ok'], $r['reason'] ?? '');
        $this->assertStringContainsString('20.000,00', $r['card']);
        $this->assertStringContainsString('12.000,00', $r['card']);
        $this->assertStringContainsString('Kaydedeyim mi?', $r['card']);
        $this->assertStringContainsString('Satış kaydedildi', $r['text'], 'onay metni eski yolun kendi metni');
        $this->assertSame($sales + 1, Sale::count());
        $sale = Sale::latest('id')->first();
        $this->assertEquals([20000.0, 12000.0, 8000.0, null], [(float) $sale->total_amount, (float) $sale->cash_amount, (float) $sale->bank_amount, $sale->party_id]);
    }

    /** Çiftlik: "Akkuş fırın 74 litre süt" — fiyat söylenmedi, karttaki fiyattan; stoktan düşer, kişiye borç yazılır. */
    public function test_product_sale_to_a_party_uses_the_card_price_and_moves_stock(): void
    {
        $milk = Product::create(['name' => 'Ajan Testi Süt', 'type' => Product::TYPE_PRODUCT, 'default_price' => 30, 'is_active' => true]);
        $party = Party::create(['name' => 'Ajan Testi Fırın']);

        $r = $this->save('evet', ['kind' => 'trade_sale', 'party_id' => $party->id, 'amount' => 0,
            'items' => [['description' => 'Ajan Testi Süt', 'product_id' => $milk->id, 'quantity' => 74, 'unit_price' => null, 'amount' => null]]]);

        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('2.220,00', $r['card']);
        $this->assertStringContainsString('Ajan Testi Fırın', $r['card']);
        $this->assertStringNotContainsString('📒', $r['card'], 'bakiye eski kartta zaten var — iki kez yazılmaz');
        $this->assertEqualsWithDelta(2220, (float) PartyStatement::build($party)['balance'], 0.01);
        $move = StockMovement::where('product_id', $milk->id)->latest('id')->first();
        $this->assertEquals([74.0, StockMovement::DIRECTION_OUT], [(float) $move->quantity, $move->direction]);
        // Çocuk taslak eski biçimde: "son kaydı sil" bu belgeyi bulur.
        $child = WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'confirmed')->latest('id')->first();
        $this->assertSame('sale', $child->extracted['created_records'][0][0]);
    }

    /** Tok: "Yusuf Şöför bugün işe başladı" → maaş/gün yoksa bulgu (ajan sorar); verilince personel kartı. */
    public function test_new_employee_needs_salary_and_day_then_is_saved(): void
    {
        $this->assertStringContainsStringIgnoringCase('maaş', $this->issue(['kind' => 'employee_setting', 'party_name' => 'Ajan Testi Yusuf', 'new_party' => true]));
        $this->assertNull(Party::where('name', 'Ajan Testi Yusuf')->first());

        $r = $this->save('evet', ['kind' => 'employee_setting', 'party_name' => 'Ajan Testi Yusuf', 'new_party' => true,
            'salary_amount' => 60000, 'salary_day' => 5, 'first_salary' => 'skip']);
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('60.000,00', $r['card']);
        $party = Party::where('name', 'Ajan Testi Yusuf')->sole();
        $this->assertTrue((bool) $party->is_employee);
        $this->assertEquals([60000.0, 5], [(float) $party->salary_amount, (int) $party->salary_day]);
    }

    /** Personele verilen para maaş hesabına bağlanır (en eski borçtan düşer) — ajan "payment" yazsa da. */
    public function test_payment_to_an_employee_becomes_a_salary_payment(): void
    {
        $emp = Party::create(['name' => 'Ajan Testi Usta', 'is_employee' => true, 'salary_amount' => 50000, 'salary_day' => 8, 'salary_auto' => true]);
        PartyLedgerEntry::create(['party_id' => $emp->id, 'type' => 'alis', 'amount' => 50000, 'entry_date' => '2026-11-08',
            'description' => 'Maaş — Kasım 2026', 'salary_period' => '2026-11']);

        $r = $this->save('evet', ['kind' => 'payment', 'party_id' => $emp->id, 'amount' => 20000]);
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('20.000,00', $r['card']);
        $this->assertEqualsWithDelta(30000, SalaryLedger::state($emp)['owed'], 0.01);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $emp->id)->where('type', 'odeme')->count());
    }

    public function test_reminder_is_created_with_the_time_computed_by_the_system(): void
    {
        $this->assertStringContainsString('Ne zaman', $this->issue(['kind' => 'reminder', 'description' => 'Belediyeye git']));
        $this->assertStringContainsString('geçmiş', $this->issue(['kind' => 'reminder', 'description' => 'Belediyeye git', 'event_date' => '2026-11-01', 'event_time' => '09:00', 'is_alarm' => true]));

        $before = Reminder::count();
        $r = $this->save('evet', ['kind' => 'reminder', 'description' => 'Belediyeye git', 'event_date' => '2026-11-11', 'event_time' => '15:00', 'is_alarm' => true]);
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('11 Kasım', $r['card']);
        $this->assertStringContainsString('15:00', $r['card']);
        $this->assertSame($before + 1, Reminder::count());
        $reminder = Reminder::latest('id')->first();
        $this->assertSame('2026-11-11 15:00', $reminder->next_fire_at->timezone('Europe/Istanbul')->format('Y-m-d H:i'));
        $this->assertStringContainsString('hatırlatacağım', $r['text']);
    }

    public function test_recurring_expense_is_created(): void
    {
        $before = Reminder::whereNotNull('amount')->count();
        $r = $this->save('evet', ['kind' => 'recurring_expense', 'amount' => 34.6, 'description' => 'Çobanlar ekmek', 'recurring_frequency' => 'daily']);
        $this->assertTrue($r['ok'], ($r['reason'] ?? '') . $r['card']);
        $this->assertStringContainsString('34,60', $r['card']);
        $this->assertSame($before + 1, Reminder::whereNotNull('amount')->count());
    }

    /** Ürün kartı tutmayan "alış" düz alışa/gidere döner ve ONUN kuralları geçerli olur (ödendi mi sorulur) — sessiz kayıt yok. */
    public function test_product_purchase_without_a_matching_card_falls_back_to_the_plain_rules(): void
    {
        $party = Party::create(['name' => 'Ajan Testi Toptancı']);
        $issue = $this->issue(['kind' => 'trade_purchase', 'party_id' => $party->id, 'amount' => 5000, 'description' => 'nakliye',
            'items' => [['description' => 'nakliye', 'product_id' => null, 'quantity' => null, 'unit_price' => null, 'amount' => 5000]]]);
        $this->assertNotSame('', $issue);
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $party->id)->count());
    }
}
