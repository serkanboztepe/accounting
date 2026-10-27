<?php

namespace Tests\Feature;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Filament\Resources\Parties\Pages\ListParties;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\User;
use App\Services\Whatsapp\EmployeeDraft;
use App\Support\ModuleProfiles;
use App\Support\MonthlyResult;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cari tipi (kullanıcı 27.10): "Bu cariyle nasıl çalışıyorsun?" zorunlu; Cariler sekmeleri; cari sayfasında tipin düğmeleri; tipi boş cari
 * ilk hareketten tip alır (seçilmişe dokunulmaz). Personel: Maaş / Avans / Maaştan kes, maaş günü kendiliğinden işleme, ekstre etiketleri.
 */
class PartyKindTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['agreements' => true, 'report_monthly' => true]), 'app.profile' => 'muteahhit']);
        $this->actingAs(User::factory()->create());
    }

    private function page(Party $party)
    {
        return Livewire::test(EditParty::class, ['record' => $party->getRouteKey()]);
    }

    public function test_create_requires_kind_and_employee_fields_follow_it(): void
    {
        // Yeni cari pencerede (27.10: ayrı sayfa yok).
        Livewire::test(ListParties::class)->callAction('create', ['name' => 'PK Zorunlu'])->assertHasActionErrors(['kind' => 'required']);
        Livewire::test(ListParties::class)->callAction('create', ['name' => 'PK Usta', 'kind' => Party::KIND_EMPLOYEE, 'salary_amount' => 40000,
            'salary_day' => 5])->assertHasNoActionErrors();
        $this->get('/admin/parties/create')->assertNotFound();
        $usta = Party::where('name', 'PK Usta')->sole();
        $this->assertTrue($usta->is_employee, 'tip personel → personel işareti');
        $this->assertTrue($usta->salary_auto, 'yeni personelde kendiliğinden işleme açık');

        $usta->update(['is_employee' => false]);
        $this->assertNull($usta->fresh()->kind, 'personellikten çıkınca tip boşalır');
        $ali = Party::create(['name' => 'PK WhatsApp Ali']);
        $ali->update(['is_employee' => true]);   // WhatsApp "personelim"
        $this->assertSame(Party::KIND_EMPLOYEE, $ali->fresh()->kind);
    }

    public function test_buttons_follow_kind(): void
    {
        $customer = Party::create(['name' => 'PK Müşteri', 'kind' => Party::KIND_CUSTOMER]);
        $this->page($customer)->assertSee('Satış Yaptım')->assertSee('Ödeme Aldım')->assertDontSee('Alım Yaptım')->assertDontSee('Ödeme Yaptım')
            ->assertDontSee('Maaş / Avans');
        $supplier = Party::create(['name' => 'PK Tedarikçi', 'kind' => Party::KIND_SUPPLIER]);
        $this->page($supplier)->assertSee('Alım Yaptım')->assertSee('Ödeme Yaptım')->assertDontSee('Satış Yaptım')->assertDontSee('Ödeme Aldım');
        $plain = Party::create(['name' => 'PK Eski']);
        $this->page($plain)->assertSee('Satış Yaptım')->assertSee('Alım Yaptım');
        $employee = Party::create(['name' => 'PK Personel', 'kind' => Party::KIND_EMPLOYEE, 'salary_amount' => 30000]);
        $this->page($employee)->assertSee('Maaş / Avans')->assertDontSee('Satış Yaptım')->assertDontSee('Alım Yaptım')->assertDontSee('Anlaşma yap');
        // Eski personel (tipi boş — Akkuş): bugünkü düğmeler + Maaş / Avans; ayarlarda tip "Personelim" dolu gelir.
        $legacy = Party::create(['name' => 'PK Eski Personel']);
        Party::whereKey($legacy->id)->update(['is_employee' => true]);
        $this->page($legacy->fresh())->assertSee('Maaş / Avans')->assertSee('Satış Yaptım')->assertSee('Alım Yaptım')
            ->mountAction('settings')->assertSet('mountedActions.0.data.kind', Party::KIND_EMPLOYEE);

        // Anlaşma yönü tipe göre sabit (seçim yok).
        $this->page($customer)->mountAction('newAgreement')->assertSet('mountedActions.0.data.direction', 'sale');
        $this->page($supplier)->mountAction('newAgreement')->assertSet('mountedActions.0.data.direction', 'purchase');
        $this->page($customer)->callAction('settings', ['name' => 'PK Müşteri', 'kind' => Party::KIND_BOTH])->assertHasNoActionErrors();
        $this->assertSame(Party::KIND_BOTH, $customer->fresh()->kind, 'tip ayarlardan değişir');
    }

    public function test_tabs_filter_by_kind_and_both_shows_twice(): void
    {
        $c = Party::create(['name' => 'PK Sekme Müşteri', 'kind' => Party::KIND_CUSTOMER]);
        $s = Party::create(['name' => 'PK Sekme Tedarikçi', 'kind' => Party::KIND_SUPPLIER]);
        $b = Party::create(['name' => 'PK Sekme İkisi', 'kind' => Party::KIND_BOTH]);
        $e = Party::create(['name' => 'PK Sekme Personel', 'kind' => Party::KIND_EMPLOYEE]);

        Livewire::test(ListParties::class)->assertSeeHtml("\$set('activeTab', 'suppliers')")->assertSee('Tedarikçiler')
            ->assertDontSeeHtml('fi-tabs')->assertSee('Kişiler ve Firmalar');   // sekme değil yuvarlak düğme; yeni ad
        Livewire::test(ListParties::class)->set('activeTab', 'customers')->assertCanSeeTableRecords([$c, $b])->assertCanNotSeeTableRecords([$s, $e]);
        Livewire::test(ListParties::class)->set('activeTab', 'suppliers')->assertCanSeeTableRecords([$s, $b])->assertCanNotSeeTableRecords([$c, $e]);
        Livewire::test(ListParties::class)->set('activeTab', 'employees')->assertCanSeeTableRecords([$e])->assertCanNotSeeTableRecords([$c, $s, $b]);
    }

    public function test_kind_fills_from_first_entry_but_never_overrides_choice(): void
    {
        $new = Party::create(['name' => 'PK Yeni']);
        PartyLedgerEntry::create(['party_id' => $new->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 100]);
        $this->assertSame(Party::KIND_SUPPLIER, $new->fresh()->kind);
        PartyLedgerEntry::create(['party_id' => $new->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 50]);
        $this->assertSame(Party::KIND_SUPPLIER, $new->fresh()->kind, 'tip dolduysa bir daha değişmez');

        $chosen = Party::create(['name' => 'PK Seçilmiş', 'kind' => Party::KIND_CUSTOMER]);
        PartyLedgerEntry::create(['party_id' => $chosen->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 100]);
        $this->assertSame(Party::KIND_CUSTOMER, $chosen->fresh()->kind);

        $both = Party::create(['name' => 'PK İkisi']);
        PartyLedgerEntry::withoutEvents(function () use ($both) {
            PartyLedgerEntry::create(['party_id' => $both->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'direction' => 'borc', 'amount' => 10]);
            PartyLedgerEntry::create(['party_id' => $both->id, 'entry_date' => now(), 'type' => PartyLedgerEntry::TYPE_COLLECTION, 'direction' => 'alacak', 'amount' => 10]);
        });
        $this->assertSame(Party::KIND_BOTH, Party::kindFromHistory($both->id), 'eski cari doldurma: iki yön → ikisi de');
    }

    // ── Personel ───────────────────────────────────────────────────────────────────────────────────────────────

    private function employee(array $o = []): Party
    {
        return Party::create(['name' => 'PK Ahmet', 'kind' => Party::KIND_EMPLOYEE, 'salary_amount' => 55000, 'salary_day' => 15] + $o);
    }

    private function salaryWindow(Party $party, array $data)
    {
        return $this->page($party)->mountAction('salary')->fillForm($data)->callMountedAction()->assertHasNoFormErrors();
    }

    public function test_salary_window_deducts_advance_and_deduction(): void
    {
        $ahmet = $this->employee();
        $this->salaryWindow($ahmet, ['what' => 'advance', 'amount' => '20.000,00', 'payment_type' => 'cash', 'date' => now()->toDateString()]);
        $this->salaryWindow($ahmet, ['what' => 'deduction', 'amount' => '5.000,00', 'reason' => '2 gün ücretsiz izin', 'date' => now()->toDateString()]);
        $this->assertStringContainsString('maaş 55.000,00 ₺ − avans 20.000,00 − kesinti 5.000,00 = 30.000,00 ₺ ödeyeceksin',
            EmployeeDraft::reminderNote($ahmet->fresh()));

        $this->salaryWindow($ahmet, ['what' => 'salary', 'month' => now()->format('Y-m'), 'amount' => '55.000,00', 'pay' => true,
            'payment_type' => 'eft', 'date' => now()->toDateString()]);
        $this->assertEqualsWithDelta(0, PartyStatement::build($ahmet)['balance'], 0.01, 'maaş − avans − kesinti ödendi, hesap kapalı');
        $this->assertSame(30000.0, (float) PartyLedgerEntry::where('party_id', $ahmet->id)->where('description', 'like', 'Maaş ödemesi%')->value('amount'));

        $labels = collect(PartyStatement::build($ahmet)['rows'])->pluck('label')->all();
        $this->assertSame(['Avans', 'Kesinti', 'Maaş', 'Maaş ödemesi'], $labels, 'ekstrede Alış / Hizmet yok');

        // Ay sonucu: personel = maaş − kesinti; kesinti alış iadesine karışmaz.
        $r = MonthlyResult::forMonth(now());
        $this->assertGreaterThanOrEqual(50000 - 0.01, $r['personnel']);
        $this->assertSame(0.0, (float) PartyLedgerEntry::where('party_id', $ahmet->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE_RETURN)
            ->whereNotNull('purchase_return_id')->sum('amount'));
    }

    public function test_advance_covering_salary_writes_no_payment(): void
    {
        $ahmet = $this->employee();
        $this->salaryWindow($ahmet, ['what' => 'advance', 'amount' => '60.000,00', 'payment_type' => 'cash', 'date' => now()->toDateString()]);
        $page = $this->page($ahmet)->mountAction('salary')->fillForm(['what' => 'salary', 'month' => now()->format('Y-m'), 'amount' => '55.000,00']);
        $page->assertFormFieldHidden('pay');
        $page->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $ahmet->id)->where('description', 'like', 'Maaş ödemesi%')->count());
        $this->assertEqualsWithDelta(5000, PartyStatement::build($ahmet)['balance'], 0.01, '5.000 avans sonraki aya');
    }

    public function test_salary_day_accrues_once_and_payment_does_not_double(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 15, 7, 50, 0, 'Europe/Istanbul'));
        try {
            $ahmet = $this->employee(['salary_auto' => true]);
            $manual = $this->employee(['name' => 'PK Elle', 'salary_auto' => false]);
            PartyLedgerEntry::create(['party_id' => $ahmet->id, 'entry_date' => '2026-10-03', 'type' => PartyLedgerEntry::TYPE_PAYMENT,
                'description' => 'Avans', 'amount' => 20000]);

            $this->artisan('salaries:accrue')->assertSuccessful();
            $this->artisan('salaries:accrue')->assertSuccessful();
            $this->assertSame(1, PartyLedgerEntry::where('party_id', $ahmet->id)->where('description', 'Maaş — Ekim 2026')->count(), 'bir kez');
            $this->assertSame(0, PartyLedgerEntry::where('party_id', $manual->id)->count(), 'kapalı olana dokunulmaz');
            $this->assertStringContainsString('Ekim maaşı işlendi: maaş 55.000,00 ₺ − avans 20.000,00 = 35.000,00 ₺ ödemen gerekiyor',
                EmployeeDraft::reminderNote($ahmet->fresh()));

            // Sonra "maaşı ödendi" (panel ya da WhatsApp): maaş ikinci kez yazılmaz, yalnız borç ödenir.
            $this->salaryWindow($ahmet, ['what' => 'salary', 'month' => '2026-10', 'amount' => '55.000,00', 'pay' => true,
                'payment_type' => 'cash', 'date' => '2026-10-15']);
            $this->assertSame(1, PartyLedgerEntry::where('party_id', $ahmet->id)->where('description', 'Maaş — Ekim 2026')->count());
            $this->assertEqualsWithDelta(0, PartyStatement::build($ahmet)['balance'], 0.01);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fill_kind_command_is_dry_run_by_default(): void
    {
        $p = Party::create(['name' => 'PK Doldur']);
        PartyLedgerEntry::withoutEvents(fn () => PartyLedgerEntry::create(['party_id' => $p->id, 'entry_date' => now(),
            'type' => PartyLedgerEntry::TYPE_SALE, 'direction' => 'borc', 'amount' => 10]));
        $this->artisan('parties:fill-kind')->assertSuccessful();
        $this->assertNull($p->fresh()->kind, 'deneme yazmaz');
        $this->artisan('parties:fill-kind --apply')->assertSuccessful();
        $this->assertSame(Party::KIND_CUSTOMER, $p->fresh()->kind);
    }
}
