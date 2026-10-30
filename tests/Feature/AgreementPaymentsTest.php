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
            ->assertDontSeeHtml('>anlaşma dışı</span>')   // 27.10: Diğer satırı kalktı
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
        foreach (['24.000 ₺ (1 teslimat)', '10.000 ₺ (1 ödeme)', 'Bekleyen çek', '10.000 ₺ (1 çek, ilk vade', '14.000 ₺', '56.000 ₺', 'AP Demir',
            'Toplam <span class="font-normal text-gray-500 dark:text-gray-400">(on bin lira)</span>'] as $text) {
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
            ->assertSee('AP Test A Blok')->assertSee('Borç: 13.000 (toplam bakiye değildir)')->assertSeeHtml('<option value="other">Diğer alışlar</option>');
        $this->assertStringContainsString('agreement=' . $demir->id, $page->instance()->statementPrintUrl());

        $this->page()->mountAction('agreementSummary', ['agreement' => $cimento->id])
            ->call('showAgreementInStatement', $cimento->id)
            ->assertSet('statementAgreement', (string) $cimento->id)->assertSet('statementDateFrom', null)
            ->assertSet('mountedActions', []);

        $this->get(route('party.statement.print', ['party' => $this->party, 'agreement' => $demir->id]))
            ->assertOk()->assertSee('Anlaşma: Demir (AP Test A Blok)');
    }

    /** Anlaşma penceresinde proje seçimi (27.10): kalem başına o projeye gelen miktar; tek projede seçim yok. */
    public function test_summary_project_picker_shows_quantity_per_project(): void
    {
        $beton = $this->agreement('Beton', 500, 2600);
        $this->deliver($beton, 200);
        $render = fn () => view('filament.resources.parties.agreement-summary', \App\Support\AgreementSummary::build($beton->fresh()))->render();
        $this->assertStringNotContainsString('Hepsi', $render(), 'tek proje → seçim yok');

        $bBlok = Project::create(['name' => 'AP Test B Blok', 'status' => 'active']);
        AgreementDesk::deliver($this->party, $beton->direction, [['agreement_item_id' => $beton->items[0]->id, 'quantity' => 120]],
            now()->toDateString(), $bBlok->id);
        $html = $render();
        foreach (['Hepsi', 'AP Test A Blok', 'AP Test B Blok', 'gelen 200 ton · 520.000', 'gelen 120 ton · 312.000', 'gelen 320 ton'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // Gelen mallar: başlık + toplam (hepsi ve proje başına); kalemler ve ödemeler kapalı başlar, kalemler ödemelerin üstünde (27.10).
        $this->assertMatchesRegularExpression('/<th[^>]*>Tarih<\/th>.*<th[^>]*>Tutar<\/th>/s', $html);
        foreach (['832.000' => 'sekiz yüz otuz iki bin', '520.000' => 'beş yüz yirmi bin', '312.000' => 'üç yüz on iki bin'] as $total => $words) {
            $this->assertMatchesRegularExpression('/Toplam <span[^>]*>\(' . $words . ' lira\)<\/span>\s*<\/td>\s*<td[^>]*>[^<]*<\/td>\s*<td[^>]*>' . preg_quote($total) . ' ₺</u', $html);
        }
        $this->assertSame(2, substr_count($html, 'x-data="{ open: false }"'));
        $this->assertLessThan(strpos($html, 'Kalemler ('), strpos($html, 'Gelen mallar ('));
        $this->assertLessThan(strpos($html, 'Ödemeler ('), strpos($html, 'Kalemler ('));
    }

    /** Anlaşma penceresinde malzeme seçimi (30.10, Seramik Park: "fayans + Cumhuriyet"): proje × malzeme toplamı, miktar ve kalan. */
    public function test_summary_item_picker_combines_with_project(): void
    {
        $banyo = AgreementDesk::create($this->party, [
            'direction' => Agreement::DIRECTION_PURCHASE, 'agreement_date' => now()->subDays(5)->toDateString(), 'title' => 'Banyo',
            'project_id' => null, 'lines' => [
                ['product_id' => $this->product('AP Fayans')->id, 'quantity' => 100, 'unit_price' => 450],
                ['product_id' => $this->product('AP Klozet')->id, 'quantity' => 14, 'unit_price' => 4000],
            ],
        ])->loadForCard();
        $bBlok = Project::create(['name' => 'AP Test B Blok', 'status' => 'active']);
        [$fayans, $klozet] = [$banyo->items[0], $banyo->items[1]];
        $deliver = fn ($item, $qty, $project) => AgreementDesk::deliver($this->party, $banyo->direction,
            [['agreement_item_id' => $item->id, 'quantity' => $qty]], now()->toDateString(), $project);
        $deliver($fayans, 40, $this->aBlok->id);
        $deliver($fayans, 25, $bBlok->id);
        $deliver($klozet, 7, $this->aBlok->id);

        $data = \App\Support\AgreementSummary::build($banyo->fresh());
        $a = 'p' . $this->aBlok->id;
        $f = 'i' . $fayans->id;
        $this->assertSame(['i' . $fayans->id => 'AP Fayans', 'i' . $klozet->id => 'AP Klozet'], $data['items']);
        $this->assertEqualsWithDelta(18000, $data['deliveryTotals'][$a . '|' . $f]['amount'], 0.01, 'A Blok fayans');
        $this->assertStringContainsString('40', $data['deliveryTotals'][$a . '|' . $f]['qty']);
        $this->assertStringContainsString('kalan 35', $data['deliveryTotals'][$a . '|' . $f]['agreed']);
        $this->assertEqualsWithDelta(29250, $data['deliveryTotals']['|' . $f]['amount'], 0.01, 'tüm projelere fayans');
        $this->assertEqualsWithDelta(46000, $data['deliveryTotals'][$a . '|']['amount'], 0.01, 'A Blok hepsi');
        $this->assertEqualsWithDelta(57250, $data['deliveryTotals']['|']['amount'], 0.01, 'hepsi = Gelen');
        $this->assertArrayNotHasKey('p' . $bBlok->id . '|i' . $klozet->id, $data['deliveryTotals'], 'B Blok klozet yok');

        $html = view('filament.resources.parties.agreement-summary', $data)->render();
        foreach (['Malzeme:', 'AP Fayans', 'AP Klozet', 'Bu seçimde gelen mal yok.', "k === '" . $f . "'"] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $single = view('filament.resources.parties.agreement-summary', \App\Support\AgreementSummary::build($this->agreement('Beton', 10, 100)->fresh()))->render();
        $this->assertStringNotContainsString('Malzeme:', $single, 'tek kalem → malzeme seçimi yok');
    }

    /** Kalemli ekstre (27.10): belgeli alışın altında kalemler; basit alış, ödeme ve kalemsiz cari olduğu gibi. */
    public function test_detailed_statement_lists_document_lines_only(): void
    {
        $plain = Party::create(['name' => 'AP Basit Esnaf']);
        PartyLedgerEntry::create(['party_id' => $plain->id, 'entry_date' => now()->toDateString(), 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 5000]);
        Livewire::test(EditParty::class, ['record' => $plain->getRouteKey()])->assertDontSee('Kalemleri göster');
        $this->assertArrayNotHasKey('lines', PartyStatement::build($plain, ['detailed' => 1])['rows'][0], 'basit alışta kalem yok');

        $demir = $this->agreement('Demir', 10, 8000);
        $this->deliver($demir, 3);
        $this->pay(10000, $demir);
        PartyLedgerEntry::create(['party_id' => $this->party->id, 'entry_date' => now()->toDateString(), 'type' => PartyLedgerEntry::TYPE_PURCHASE, 'amount' => 700]);

        $rows = collect(PartyStatement::build($this->party, ['detailed' => 1])['rows']);
        $this->assertSame([['AP Demir · 3 ton × 8.000,00 ₺ = 24.000,00 ₺']], $rows->pluck('lines')->filter()->values()->all(), 'yalnız belgeli alış');
        $this->assertFalse(collect(PartyStatement::build($this->party)['rows'])->contains(fn ($r) => isset($r['lines'])), 'kısa ekstre aynı');
        $this->assertFalse(collect(PartyStatement::build($this->party, ['detailed' => 1, 'agreement' => 'other'])['rows'])
            ->contains(fn ($r) => ! empty($r['lines'])), 'anlaşma dışı süzgecinde Demir kalemi yok');

        // Stoklu satış (kalem + ürünsüz tutar) ve yalnız ürünsüz tutarlı alış (kalem yok).
        $tile = $this->product('AP Fayans');
        $sale = TradeDesk::saveSale(['mode' => 'party', 'party_id' => $this->party->id, 'sale_date' => now()->toDateString(),
            'lines' => [['product_id' => $tile->id, 'quantity' => 2, 'unit_price' => '1.500,00']], 'manual_amount' => '250,00']);
        $bare = TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $this->party->id, 'purchase_date' => now()->toDateString(),
            'manual_amount' => '30.000,00']);
        $rows = collect(PartyStatement::build($this->party, ['detailed' => 1])['rows']);
        $this->assertSame(['AP Fayans · 2 ton × 1.500,00 ₺ = 3.000,00 ₺', 'Ürünsüz tutar = 250,00 ₺'],
            $rows->firstWhere('sale_id', $sale->id)['lines'] ?? null);
        $this->assertArrayNotHasKey('lines', $rows->firstWhere('purchase_id', $bare->id), 'ürünsüz alışta kalem satırı yok');

        $page = $this->page()->assertSee('Kalemleri göster')->assertDontSee('AP Demir · 3 ton')
            ->set('statementDetailed', true)->assertSee('AP Demir · 3 ton × 8.000,00 ₺ = 24.000,00 ₺')->assertSee('Kalemleri gizle');
        $this->assertStringContainsString('detailed=1', $page->instance()->statementPrintUrl());
        $this->get($page->instance()->statementPrintUrl())->assertOk()->assertSee('AP Demir · 3 ton × 8.000,00 ₺ = 24.000,00 ₺');
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
