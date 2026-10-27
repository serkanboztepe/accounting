<?php

namespace Tests\Feature;

use App\Filament\Resources\Parties\Pages\EditParty;
use App\Models\Agreement;
use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\Unit;
use App\Models\User;
use App\Support\AgreementBalances;
use App\Support\AgreementDesk;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Anlaşma ↔ ödeme bağı (kullanıcı 26.10): ödeme "Hangisi için?" ile anlaşmaya bağlanır; anlaşma bakiyesi = gelen − ödenen,
 * "Diğer" = cari bakiyesi − anlaşmalar (parçaların toplamı HER ZAMAN cari bakiyesi). Biten + borçsuz anlaşma arşive iner.
 */
class AgreementPaymentsTest extends TestCase
{
    use DatabaseTransactions;

    private Party $party;

    private Project $aBlok;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['agreements' => true]), 'app.env' => 'local']);
        $this->actingAs(User::factory()->create());
        $this->party = Party::create(['name' => 'AP Test Demirci Ali']);
        $this->aBlok = Project::create(['name' => 'AP Test A Blok', 'status' => 'active']);
    }

    private function product(string $name): Product
    {
        return Product::create(['name' => $name, 'type' => Product::TYPE_SERVICE, 'is_active' => true,
            'unit_id' => Unit::firstOrCreate(['name' => 'ton'], ['code' => 'ap_ton'])->id]);
    }

    private function agreement(string $title, float $qty, float $price, string $direction = Agreement::DIRECTION_PURCHASE, array $extra = []): Agreement
    {
        return AgreementDesk::create($this->party, [
            'direction' => $direction, 'agreement_date' => now()->subDays(5)->toDateString(), 'title' => $title,
            'project_id' => $this->aBlok->id, 'lines' => [['product_id' => $this->product('AP ' . $title)->id, 'quantity' => $qty, 'unit_price' => $price]],
            ...$extra,
        ])->loadForCard();
    }

    private function deliver(Agreement $a, float $qty): void
    {
        AgreementDesk::deliver($this->party, $a->direction, [['agreement_item_id' => $a->items[0]->id, 'quantity' => $qty]], now()->toDateString());
    }

    private function pay(float $amount, ?Agreement $a = null, string $type = PartyLedgerEntry::TYPE_PAYMENT): PartyLedgerEntry
    {
        return PartyLedgerEntry::create(['party_id' => $this->party->id, 'entry_date' => now()->toDateString(), 'type' => $type,
            'amount' => $amount, 'agreement_id' => $a?->id]);
    }

    private function balanceOf(Agreement $a): float
    {
        return AgreementBalances::balance($a->fresh()->loadForCard());
    }

    /** Pencere içeriği test HTML'ine basılmıyor — "Hangisi için?" seçenekleri alanın kendisinden okunur. */
    private function assertOptions($page, array $expected): void
    {
        $page->assertFormFieldExists('agreement_choice', function ($field) use ($expected) {
            $this->assertSame($expected, array_values($field->getOptions()));

            return true;
        });
    }

    private function page()
    {
        return Livewire::test(EditParty::class, ['record' => $this->party->getRouteKey()]);
    }

    // ── Cümleler ───────────────────────────────────────────────────────────────────────────────────────────────

    public function test_sentences_use_different_numbers_and_turkish_suffix(): void
    {
        $p = Agreement::DIRECTION_PURCHASE;
        $this->assertSame("80.000 borcun vardı, 55.000'e düşecek", AgreementBalances::sentence(80000, 25000, $p));
        $this->assertSame('25.000 borcun vardı, borcun kalmayacak ✅', AgreementBalances::sentence(25000, 25000, $p));
        $this->assertSame('10.000 borcun vardı, 15.000 alacaklı olacaksın', AgreementBalances::sentence(10000, 25000, $p));
        $this->assertSame('borcun yok, 25.000 alacaklı olacaksın', AgreementBalances::sentence(0, 25000, $p));
        $this->assertSame('5.000 alacaklıydın, 30.000 alacaklı olacaksın', AgreementBalances::sentence(-5000, 25000, $p));
        $this->assertSame("1.000 borcun vardı, 940'a düşecek", AgreementBalances::sentence(1000, 60, $p));
        $this->assertSame("100 borcun vardı, 96'ya düşecek", AgreementBalances::sentence(100, 4, $p));
        $this->assertSame("2.000 borcun vardı, 1.250,50'ye düşecek", AgreementBalances::sentence(2000, 749.50, $p));
        $this->assertSame('80.000 borcun var', AgreementBalances::sentence(80000, 0, $p), 'tutar yazılmadan önce');

        $s = Agreement::DIRECTION_SALE;
        $this->assertSame("100.000 alacağın vardı, 60.000'e düşecek", AgreementBalances::sentence(100000, 40000, $s));
        $this->assertSame('40.000 alacağın vardı, alacağın kalmayacak ✅', AgreementBalances::sentence(40000, 40000, $s));
        $this->assertSame('alacağın yok, 40.000 borçlu olacaksın', AgreementBalances::sentence(0, 40000, $s));
        $this->assertStringNotContainsString('fazla ödemiş', AgreementBalances::sentence(10000, 25000, $p));
    }

    // ── Hesap ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** Parçaların toplamı cari bakiyesi: iki alış anlaşması, satış anlaşması, anlaşma dışı alış, bağsız ödeme, çek, iade. */
    public function test_parts_always_add_up_to_party_balance(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);                 // 80.000
        $cimento = $this->agreement('Çimento', 5, 5000);              // 25.000
        $daire = $this->agreement('Daire', 1, 300000, Agreement::DIRECTION_SALE);
        $this->deliver($demir, 10);
        $this->deliver($cimento, 5);
        $this->deliver($daire, 1);
        $this->pay(25000, $demir);
        $this->pay(25000, $cimento);
        $this->pay(100000, $daire, PartyLedgerEntry::TYPE_COLLECTION);
        $this->pay(12000);                                             // anlaşma dışı ödeme
        TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $this->party->id, 'purchase_date' => now()->toDateString(),
            'manual_amount' => '30.000,00']);                         // anlaşma dışı alış
        $check = $this->pay(7000, $demir);
        Check::create(['party_id' => $this->party->id, 'party_ledger_entry_id' => $check->id, 'amount' => 7000,
            'due_date' => now()->addMonth()->toDateString(), 'status' => 'issued']);
        $demir->refresh()->loadForCard();
        \App\Models\Purchase::whereHas('lines', fn ($q) => $q->where('agreement_item_id', $demir->items[0]->id))->first()
            ->processReturn([['product_id' => $demir->items[0]->product_id, 'return_qty' => 1, 'unit_price' => 8000]], now()->toDateString());

        $data = AgreementBalances::forParty($this->party->fresh());
        $byTitle = collect($data['rows'])->mapWithKeys(fn ($r) => [$r['agreement']->title => $r['balance']]);
        $this->assertEqualsWithDelta(80000 - 8000 - 25000 - 7000, $byTitle['Demir'], 0.01);
        $this->assertEqualsWithDelta(0, $byTitle['Çimento'], 0.01);
        $this->assertEqualsWithDelta(200000, $byTitle['Daire'], 0.01);
        $this->assertEqualsWithDelta(30000 - 12000, $data['other'][Agreement::DIRECTION_PURCHASE], 0.01);

        $sum = $byTitle['Daire'] - $byTitle['Demir'] - $byTitle['Çimento'] + $data['other'][Agreement::DIRECTION_SALE];
        $this->assertEqualsWithDelta(PartyStatement::build($this->party->fresh())['balance'], $sum, 0.01, 'parçalar = cari bakiyesi');
        $this->assertEqualsWithDelta(7000, AgreementBalances::pendingChecks($demir)->sum('amount'), 0.01);
    }

    public function test_done_or_closed_without_balance_is_archived_and_comes_back(): void
    {
        $cimento = $this->agreement('Çimento', 5, 5000);
        $this->deliver($cimento, 5);                                   // hepsi geldi → bitti, borç 25.000
        $this->assertSame(Agreement::STATUS_DONE, $cimento->fresh()->status);
        $this->assertFalse(AgreementBalances::archived($cimento->fresh()->loadForCard()), 'borcu var → listede');

        $payment = $this->pay(25000, $cimento);
        $this->assertTrue(AgreementBalances::archived($cimento->fresh()->loadForCard()), 'bitti + borç 0 → arşiv');
        $this->assertSame([], AgreementBalances::choices($this->party->fresh(), PartyLedgerEntry::TYPE_PAYMENT), 'arşiv soruda yok');

        $payment->update(['amount' => 20000]);                         // düzeltme → bakiye oluştu
        $this->assertFalse(AgreementBalances::archived($cimento->fresh()->loadForCard()), 'geri gelir');

        $open = $this->agreement('Demir', 10, 8000);
        $this->assertFalse(AgreementBalances::archived($open), 'açık anlaşma borçsuz da olsa listede');
    }

    public function test_choices_list_agreements_then_other_and_editing_adds_own_amount_back(): void
    {
        $this->assertSame([], AgreementBalances::choices($this->party, PartyLedgerEntry::TYPE_PAYMENT), 'anlaşma yok → soru yok');
        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 10);
        $this->agreement('Daire', 1, 300000, Agreement::DIRECTION_SALE);

        $choices = AgreementBalances::choices($this->party->fresh(), PartyLedgerEntry::TYPE_PAYMENT);
        $this->assertSame(['Demir (AP Test A Blok)', 'Diğer alışlar'], array_column($choices, 'label'), 'yalnız alış anlaşmaları + Diğer');
        $this->assertEqualsWithDelta(80000, $choices[0]['balance'], 0.01);
        $this->assertSame(['Daire (AP Test A Blok)', 'Diğer satışlar'],
            array_column(AgreementBalances::choices($this->party->fresh(), PartyLedgerEntry::TYPE_COLLECTION), 'label'));

        $linked = $this->pay(30000, $demir);
        $editing = AgreementBalances::choices($this->party->fresh(), PartyLedgerEntry::TYPE_PAYMENT, null, $linked);
        $this->assertEqualsWithDelta(80000, $editing[0]['balance'], 0.01, 'kendi tutarı geri eklenir');
        $loose = $this->pay(5000);
        $editing = AgreementBalances::choices($this->party->fresh(), PartyLedgerEntry::TYPE_PAYMENT, null, $loose);
        $this->assertEqualsWithDelta(50000, $editing[0]['balance'], 0.01);
        $this->assertEqualsWithDelta(0, $editing[1]['balance'], 0.01, 'bağsız ödeme Diğer\'e geri eklenir');
    }

    // ── Kendiliğinden bağlananlar ──────────────────────────────────────────────────────────────────────────────

    public function test_advance_and_paid_delivery_link_themselves(): void
    {
        $a = $this->agreement('Demir', 10, 8000, Agreement::DIRECTION_PURCHASE, ['advance' => true, 'advance_amount' => '15.000,00']);
        $this->assertEqualsWithDelta(15000, AgreementBalances::paid($a->fresh()), 0.01, 'peşinat anlaşmanın ödemesi');

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE, [['agreement_item_id' => $a->items[0]->id, 'quantity' => 2]],
            now()->toDateString(), 'agreement', false, null, true, 'cash');
        $this->assertEqualsWithDelta(15000 + 16000, AgreementBalances::paid($a->fresh()), 0.01, 'peşin teslimatın parası da');
        $this->assertEqualsWithDelta(16000 - 31000, $this->balanceOf($a), 0.01);
    }

    public function test_two_agreements_in_one_delivery_make_separate_documents(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $cimento = $this->agreement('Çimento', 5, 5000);
        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE, [
            ['agreement_item_id' => $demir->items[0]->id, 'quantity' => 1],
            ['agreement_item_id' => $cimento->items[0]->id, 'quantity' => 1],
        ], now()->toDateString(), 'agreement', false, null, true, 'cash');

        $this->assertEqualsWithDelta(0, $this->balanceOf($demir), 0.01, 'peşin: gelen = ödenen');
        $this->assertEqualsWithDelta(0, $this->balanceOf($cimento), 0.01);
    }

    // ── Panel ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_payment_window_requires_choice_and_saves_agreement(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 10);

        $page = $this->page()->mountAction('newLedgerEntry', ['type' => PartyLedgerEntry::TYPE_PAYMENT])
            ->set('mountedActions.0.data.amount', '25.000,00');
        $this->assertOptions($page, ["Demir (AP Test A Blok): 80.000 borcun vardı, 55.000'e düşecek",
            'Diğer alışlar: borcun yok, 25.000 alacaklı olacaksın']);
        $page->callMountedAction()->assertHasActionErrors(['agreement_choice']);
        $page->set('mountedActions.0.data.agreement_choice', (string) $demir->id)->callMountedAction()->assertHasNoActionErrors();

        $entry = PartyLedgerEntry::where('party_id', $this->party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->sole();
        $this->assertSame($demir->id, (int) $entry->agreement_id);
        $this->assertEqualsWithDelta(55000, $this->balanceOf($demir), 0.01);
    }

    public function test_other_choice_and_party_without_agreements(): void
    {
        $this->agreement('Demir', 10, 8000);
        $this->page()->callAction('newLedgerEntry', ['amount' => '1.000,00', 'agreement_choice' => 'other'],
            ['type' => PartyLedgerEntry::TYPE_PAYMENT])->assertHasNoActionErrors();
        $this->assertNull(PartyLedgerEntry::where('party_id', $this->party->id)->sole()->agreement_id);

        $plain = Party::create(['name' => 'AP Test Anlaşmasız']);
        Livewire::test(EditParty::class, ['record' => $plain->getRouteKey()])
            ->mountAction('newLedgerEntry', ['type' => PartyLedgerEntry::TYPE_PAYMENT])
            ->assertFormFieldHidden('agreement_choice')
            ->set('mountedActions.0.data.amount', '2.000,00')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $plain->id)->count());
    }

    public function test_collection_window_offers_sale_agreements(): void
    {
        $daire = $this->agreement('Daire', 1, 100000, Agreement::DIRECTION_SALE);
        $this->deliver($daire, 1);
        $page = $this->page()->mountAction('newCollection')->set('mountedActions.0.data.amount', '40.000,00');
        $this->assertOptions($page, ["Daire (AP Test A Blok): 100.000 alacağın vardı, 60.000'e düşecek",
            'Diğer satışlar: alacağın yok, 40.000 borçlu olacaksın']);
        $page->set('mountedActions.0.data.agreement_choice', (string) $daire->id)
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($daire->id, (int) PartyLedgerEntry::where('party_id', $this->party->id)
            ->where('type', PartyLedgerEntry::TYPE_COLLECTION)->sole()->agreement_id);
    }

    public function test_statement_shows_tag_and_link_button_links_old_payment(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 10);
        $old = $this->pay(30000);
        $page = $this->page()
            ->assertSeeHtml("mountAction('linkAgreement', { entry: " . $old->id . ' })');

        $page->mountAction('linkAgreement', ['entry' => $old->id]);
        $this->assertOptions($page, ["Demir (AP Test A Blok): 80.000 borcun vardı, 50.000'e düşecek",
            'Diğer alışlar: borcun yok, 30.000 alacaklı olacaksın']);
        $page->set('mountedActions.0.data.agreement_choice', (string) $demir->id)
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame($demir->id, (int) $old->fresh()->agreement_id);
        $this->page()->assertDontSeeHtml("mountAction('linkAgreement', { entry: " . $old->id . ' })');
        $this->assertEqualsWithDelta(50000, $this->balanceOf($demir), 0.01);
    }

    public function test_agreement_table_columns_other_row_and_archive(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 4);
        $this->pay(12000, $demir);
        $bitti = $this->agreement('Kum', 1, 9000);
        $this->deliver($bitti, 1);
        $this->pay(9000, $bitti);

        $this->page()
            ->assertSeeInOrder(['Anlaşılan', 'Gelen', 'Ödenen', 'Borç', 'Gelecek mal'])
            ->assertSeeInOrder(['Demir', '80.000,00', '32.000,00', '12.000,00', '20.000', '48.000,00'])
            ->assertSee('Diğer alışlar')
            ->assertSee('Arşiv (1)')
            ->assertDontSee('Biten anlaşmalar');
    }

    public function test_summary_opens_and_pay_button_preselects_agreement(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 3);
        $entry = $this->pay(10000, $demir);
        Check::create(['party_id' => $this->party->id, 'party_ledger_entry_id' => $entry->id, 'amount' => 10000,
            'due_date' => now()->addDays(20)->toDateString(), 'status' => 'issued']);

        $html = view('filament.resources.parties.agreement-summary', \App\Support\AgreementSummary::build($demir->fresh()))->render();
        foreach (['24.000 (1 teslimat)', '10.000 (1 ödeme)', 'Bekleyen çek', '10.000 (1 çek, ilk vade', '14.000', '56.000', 'AP Demir'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $page = $this->page()->mountAction('agreementSummary', ['agreement' => $demir->id])->assertHasNoErrors()
            ->mountAction('newLedgerEntry', ['type' => PartyLedgerEntry::TYPE_PAYMENT, 'agreement' => $demir->id]);
        $this->assertSame((string) $demir->id, (string) ($page->instance()->mountedActions[1]['data']['agreement_choice'] ?? null),
            'Ödeme yap → anlaşma seçili açılır');
        $page->set('mountedActions.1.data.amount', '4.000,00')->callMountedAction()->assertHasNoErrors()
            ->assertSet('mountedActions.0.name', 'agreementSummary');   // kaydedince anlaşma penceresine dönülür
        $this->assertSame(2, $demir->ledgerEntries()->count());

        $this->page()->mountAction('agreementSummary', ['agreement' => $demir->id])
            ->mountAction('changeAgreementPrice', ['item' => $demir->items()->first()->id])->assertHasNoErrors()
            ->assertSet('mountedActions.1.name', 'changeAgreementPrice');
    }

    /** Ekstre anlaşma süzgeci (27.10): süzülmüş bakiye anlaşma tablosundakiyle aynı; anlaşmalar + anlaşma dışı = cari bakiyesi. */
    public function test_statement_agreement_filter_matches_agreement_balances(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $cimento = $this->agreement('Çimento', 5, 5000);
        $this->deliver($demir, 3);                       // 24.000
        $this->deliver($cimento, 2);                     // 10.000
        $this->pay(10000, $demir);
        $this->pay(4000, $cimento);
        $this->pay(7000);                                // anlaşma dışı
        PartyLedgerEntry::create(['party_id' => $this->party->id, 'entry_date' => now()->subYear()->toDateString(),
            'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 1000, 'agreement_id' => $demir->id]);   // geçen yıl → devir

        $full = PartyStatement::build($this->party)['balance'];
        $demirRows = PartyStatement::build($this->party, ['agreement' => (string) $demir->id]);
        $this->assertCount(3, $demirRows['rows'], 'Demir: alış belgesi + 2 ödeme');
        $this->assertEqualsWithDelta(-$this->balanceOf($demir), $demirRows['balance'], 0.01);
        $other = PartyStatement::build($this->party, ['agreement' => 'other']);
        $this->assertEqualsWithDelta(AgreementBalances::forParty($this->party)['other'][Agreement::DIRECTION_SALE], $other['balance'], 0.01);
        $this->assertEqualsWithDelta($full, $demirRows['balance'] + PartyStatement::build($this->party, ['agreement' => (string) $cimento->id])['balance']
            + $other['balance'], 0.01, 'parçalar = cari bakiyesi');

        $dated = PartyStatement::build($this->party, ['agreement' => (string) $demir->id, 'date_from' => now()->startOfYear()->toDateString()]);
        $this->assertEqualsWithDelta(1000, $dated['opening'], 0.01);
        $this->assertEqualsWithDelta($demirRows['balance'], $dated['balance'], 0.01);

        $page = $this->page()->set('statementAgreement', (string) $demir->id)
            ->assertSee('AP Test A Blok')->assertSee('Borç: 13.000 (cari bakiye değildir)')->assertSeeHtml('<option value="other">Diğer alışlar</option>');
        $this->assertStringContainsString('agreement=' . $demir->id, $page->instance()->statementPrintUrl());

        $this->page()->mountAction('agreementSummary', ['agreement' => $cimento->id])
            ->call('showAgreementInStatement', $cimento->id)
            ->assertSet('statementAgreement', (string) $cimento->id)->assertSet('statementDateFrom', null)
            ->assertSet('mountedActions', []);

        $this->get(route('party.statement.print', ['party' => $this->party, 'agreement' => $demir->id]))
            ->assertOk()->assertSee('Anlaşma: Demir (AP Test A Blok)');
    }

    public function test_move_deliveries_toggle_only_when_project_changes_and_goods_came(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $other = Project::create(['name' => 'AP Test B Blok', 'status' => 'active']);
        $this->page()->mountAction('editAgreement', ['agreement' => $demir->id])
            ->set('mountedActions.0.data.project_id', $other->id)->assertFormFieldHidden('move_deliveries'); // mal gelmedi
        $this->deliver($demir, 1);
        $page = $this->page()->mountAction('editAgreement', ['agreement' => $demir->id])->assertFormFieldHidden('move_deliveries'); // proje aynı
        $page->set('mountedActions.0.data.project_id', $other->id)->assertFormFieldVisible('move_deliveries')
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame($other->id, (int) \App\Models\Purchase::where('party_id', $this->party->id)->sole()->project_id, 'taşındı');
    }

    public function test_checks_screen_links_check_to_agreement(): void
    {
        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 10);
        Livewire::test(\App\Filament\Resources\Checks\Pages\CreateCheck::class)
            ->fillForm(['direction' => 'given', 'status' => 'issued', 'party_id' => $this->party->id, 'amount' => '30.000,00',
                'due_date' => now()->addMonth()->toDateString(), 'issue_date' => now()->toDateString()])
            ->assertSee("Demir (AP Test A Blok): 80.000 borcun vardı, 50.000'e düşecek")
            ->fillForm(['agreement_choice' => (string) $demir->id])
            ->call('create')->assertHasNoFormErrors();

        $check = Check::where('party_id', $this->party->id)->sole();
        $this->assertSame($demir->id, (int) $check->ledgerEntry->agreement_id);
        $this->assertEqualsWithDelta(50000, $this->balanceOf($demir), 0.01);
    }
}
