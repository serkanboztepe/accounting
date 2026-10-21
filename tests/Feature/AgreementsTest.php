<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Support\AgreementDesk;
use App\Support\ModuleProfiles;
use App\Support\PartyDeletion;
use App\Support\TradeDesk;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Anlaşmalar (Faz 6, 21.10): anlaşma borç yazmaz; teslimat normal alış/satış belgesi, kalemi anlaşma kalemine bağlı.
 * Tür kalemden anlaşılır (miktarlı / götürü / fiyat listesi); proje başına belge; zamda eski kalem kapanır.
 */
class AgreementsTest extends TestCase
{
    use DatabaseTransactions;

    private Party $party;

    private Project $yesilyurt;

    private Project $cumhuriyet;

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['agreements' => true])]);
        $this->actingAs(User::factory()->create());
        $this->party = Party::create(['name' => 'Anlaşma Test Kuşak']);
        $this->yesilyurt = Project::create(['name' => 'Anlaşma Test Yeşilyurt', 'status' => 'active']);
        $this->cumhuriyet = Project::create(['name' => 'Anlaşma Test Cumhuriyet', 'status' => 'active']);
    }

    private function product(string $name, ?string $unit = null): Product
    {
        return Product::create(['name' => $name, 'type' => Product::TYPE_SERVICE, 'is_active' => true,
            'unit_id' => $unit ? Unit::firstOrCreate(['name' => $unit], ['code' => 'at_' . substr(md5($unit), 0, 6)])->id : null]);
    }

    private function agreement(array $lines, array $extra = []): Agreement
    {
        return AgreementDesk::create($this->party, [
            'direction' => Agreement::DIRECTION_PURCHASE, 'agreement_date' => now()->subDay()->toDateString(),
            'project_id' => $this->yesilyurt->id, 'lines' => $lines, ...$extra,
        ])->loadForCard();
    }

    private function balance(): float
    {
        return (float) \App\Support\PartyStatement::build($this->party->fresh())['balance'];
    }

    public function test_kinds_are_inferred_and_agreement_writes_no_debt(): void
    {
        $beton = $this->product('AT Beton', 'm³');
        $pompa = $this->product('AT Pompa');
        $kalip = $this->product('AT Kalıp işçiliği', 'm²');

        $a = $this->agreement([
            ['product_id' => $beton->id, 'quantity' => '500', 'unit_price' => '3.800,00'],
            ['product_id' => $pompa->id, 'amount' => '45.000,00'],
            ['product_id' => $kalip->id, 'unit_price' => '120,00'],
        ]);

        [$b, $p, $k] = $a->items->all();
        $this->assertFalse($b->is_lump);
        $this->assertEqualsWithDelta(500, $b->quantity, 0.001);
        $this->assertTrue($p->is_lump);
        $this->assertEqualsWithDelta(45000, $p->unit_price, 0.01);
        $this->assertTrue($k->isList());
        $this->assertEqualsWithDelta(1945000, $a->agreedTotal(), 0.01);   // liste girmez
        $this->assertSame('AT Beton +2 · Anlaşma Test Yeşilyurt', $a->title);
        $this->assertSame(0.0, $this->balance());                          // anlaşma borç yazmaz
        $this->assertSame(0, PartyLedgerEntry::where('party_id', $this->party->id)->count());
    }

    public function test_advance_is_a_plain_payment_on_the_party(): void
    {
        $this->agreement([['product_id' => $this->product('AT Fayans', 'm²')->id, 'quantity' => 400, 'unit_price' => 450]],
            ['advance' => true, 'advance_amount' => '50.000,00', 'advance_payment_type' => 'eft']);

        $entry = PartyLedgerEntry::where('party_id', $this->party->id)->sole();
        $this->assertSame(PartyLedgerEntry::TYPE_PAYMENT, $entry->type);
        $this->assertEqualsWithDelta(50000, $entry->amount, 0.01);
        $this->assertStringStartsWith('Peşinat — AT Fayans', $entry->description);
    }

    public function test_delivery_across_two_agreements_writes_one_purchase_per_project(): void
    {
        $beton = $this->product('AT Beton2', 'm³');
        $a1 = $this->agreement([['product_id' => $beton->id, 'quantity' => 100, 'unit_price' => 3800]]);
        $a2 = $this->agreement([['product_id' => $beton->id, 'quantity' => 50, 'unit_price' => 4100]],
            ['project_id' => $this->cumhuriyet->id]);

        $result = AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE, [
            ['agreement_item_id' => $a1->items[0]->id, 'quantity' => '40', 'unit_price' => '3.800,00'],
            ['agreement_item_id' => $a2->items[0]->id, 'quantity' => '10'],
        ], now()->toDateString());

        $this->assertCount(2, $result['documents']);
        $this->assertSame([], $result['over']);
        $byProject = Purchase::where('party_id', $this->party->id)->get()->keyBy('project_id');
        $this->assertEqualsWithDelta(152000, $byProject[$this->yesilyurt->id]->total_amount, 0.01);
        $this->assertEqualsWithDelta(41000, $byProject[$this->cumhuriyet->id]->total_amount, 0.01);
        // Projeli cari alışı → proje maliyetine girer (ledger project_id)
        $this->assertEqualsWithDelta(152000, PartyLedgerEntry::where('party_id', $this->party->id)
            ->where('project_id', $this->yesilyurt->id)->where('type', PartyLedgerEntry::TYPE_PURCHASE)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(-193000, $this->balance(), 0.01);

        $a1 = $a1->fresh()->loadForCard();
        $this->assertEqualsWithDelta(60, $a1->items[0]->remainingQuantity(), 0.001);
        $this->assertEqualsWithDelta(228000, $a1->remainingTotal(), 0.01);
        $this->assertSame(40, $a1->progress());
    }

    public function test_project_can_be_changed_or_left_general_at_delivery(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Demir', 'ton')->id, 'quantity' => 10, 'unit_price' => 30000]]);

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 2]], now()->toDateString(), $this->cumhuriyet->id);
        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 1]], now()->toDateString(), '');

        $this->assertEqualsCanonicalizing([$this->cumhuriyet->id, null],
            Purchase::where('party_id', $this->party->id)->pluck('project_id')->all());
        $this->assertEqualsWithDelta(7, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }

    public function test_full_delivery_finishes_and_over_delivery_warns(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Klozet', 'adet')->id, 'quantity' => 20, 'unit_price' => 3200]]);

        $result = AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 22]], now()->toDateString());

        $this->assertSame(Agreement::STATUS_DONE, $a->fresh()->status);
        $this->assertCount(1, $result['over']);
        $this->assertStringContainsString('2 adet fazla', $result['over'][0]);
    }

    public function test_lump_sum_progress_by_amount(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Elektrik tesisatı')->id, 'amount' => '350.000,00']]);

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'amount' => '175.000,00']], now()->toDateString());

        $a = $a->fresh()->loadForCard();
        $this->assertEqualsWithDelta(0.5, $a->items[0]->deliveredQuantity(), 0.0001);
        $this->assertEqualsWithDelta(175000, $a->remainingTotal(), 0.01);
        $this->assertSame(50, $a->progress());
        $this->assertSame(Agreement::STATUS_OPEN, $a->status);
    }

    public function test_price_change_keeps_history_and_reprices_rest(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Klozet2', 'adet')->id, 'quantity' => 20, 'unit_price' => 3200]]);
        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 8]], now()->toDateString());

        AgreementDesk::changePrice($a->items[0]->fresh(), 3500);

        $items = $a->fresh()->loadForCard()->items;
        $this->assertCount(2, $items);
        $this->assertNotNull($items[0]->closed_at);
        $this->assertEqualsWithDelta(8, $items[0]->quantity, 0.001);
        $this->assertEqualsWithDelta(12, $items[1]->quantity, 0.001);
        $this->assertEqualsWithDelta(3500, $items[1]->unit_price, 0.01);
        $this->assertEqualsWithDelta(8 * 3200 + 12 * 3500, $a->fresh()->loadForCard()->agreedTotal(), 0.01);
        // geçmiş teslimat eski fiyatta
        $this->assertEqualsWithDelta(25600, Purchase::where('party_id', $this->party->id)->sole()->total_amount, 0.01);
    }

    public function test_price_differs_at_delivery_and_applies_to_rest_when_asked(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Seramik', 'm²')->id, 'quantity' => 100, 'unit_price' => 400]]);

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 30, 'unit_price' => '450,00']], now()->toDateString(), 'agreement', true);

        $items = $a->fresh()->loadForCard()->items;
        $this->assertCount(2, $items);
        $this->assertEqualsWithDelta(70, $items[1]->quantity, 0.001);
        $this->assertEqualsWithDelta(450, $items[1]->unit_price, 0.01);
    }

    public function test_return_reduces_delivered(): void
    {
        $product = $this->product('AT Tuğla', 'adet');
        $a = $this->agreement([['product_id' => $product->id, 'quantity' => 1000, 'unit_price' => 5]]);
        $purchase = AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 600]], now()->toDateString())['documents'][0];

        $purchase->processReturn([['product_id' => $product->id, 'return_qty' => 100, 'unit_price' => 5]], now()->toDateString());

        $this->assertEqualsWithDelta(500, $a->fresh()->loadForCard()->items[0]->deliveredQuantity(), 0.001);
    }

    public function test_editing_the_purchase_keeps_the_agreement_link(): void
    {
        $product = $this->product('AT Kum', 'ton');
        $a = $this->agreement([['product_id' => $product->id, 'quantity' => 50, 'unit_price' => 900]]);
        $purchase = AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 10]], now()->toDateString())['documents'][0];

        $purchase->rebuildFromLines([['product_id' => $product->id, 'quantity' => 12, 'unit_price' => 900]]); // Alışlar ekranından düzeltme

        $this->assertEqualsWithDelta(12, $a->fresh()->loadForCard()->items[0]->deliveredQuantity(), 0.001);
        $this->assertSame($this->yesilyurt->id, $purchase->fresh()->project_id);
    }

    public function test_sale_agreement_with_deposit_and_delivery(): void
    {
        $daire = $this->product('AT A Blok 3 No');
        $a = AgreementDesk::create($this->party, ['direction' => Agreement::DIRECTION_SALE, 'agreement_date' => now()->toDateString(),
            'project_id' => $this->yesilyurt->id, 'lines' => [['product_id' => $daire->id, 'quantity' => 1, 'unit_price' => '4.500.000,00']],
            'advance' => true, 'advance_amount' => '500.000,00', 'advance_payment_type' => 'cash']);

        $this->assertSame(PartyLedgerEntry::TYPE_COLLECTION, PartyLedgerEntry::where('party_id', $this->party->id)->sole()->type);

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_SALE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 1]], now()->toDateString());

        $sale = Sale::where('party_id', $this->party->id)->sole();
        $this->assertSame($this->yesilyurt->id, $sale->project_id);
        $this->assertEqualsWithDelta(4500000, $sale->total_amount, 0.01);
        $this->assertEqualsWithDelta(4000000, $this->balance(), 0.01);   // cari bize borçlu
        $this->assertSame(Agreement::STATUS_DONE, $a->fresh()->status);
        // satış anlaşmasının kalemi alış teslimatında görünmez
        $this->assertCount(0, AgreementDesk::openItems($this->party, Agreement::DIRECTION_PURCHASE));
    }

    public function test_open_items_oldest_first_and_closed_hidden(): void
    {
        $klozet = $this->product('AT Klozet3', 'adet');
        $old = $this->agreement([['product_id' => $klozet->id, 'quantity' => 12, 'unit_price' => 3500]], ['agreement_date' => now()->subDays(10)->toDateString()]);
        $new = $this->agreement([['product_id' => $klozet->id, 'quantity' => 30, 'unit_price' => 3600]]);

        $this->assertSame([$old->items[0]->id, $new->items[0]->id], AgreementDesk::openItems($this->party, Agreement::DIRECTION_PURCHASE)->pluck('id')->all());

        AgreementDesk::close($old);
        $this->assertSame([$new->items[0]->id], AgreementDesk::openItems($this->party, Agreement::DIRECTION_PURCHASE)->pluck('id')->all());
        $this->assertSame(0.0, $old->fresh()->loadForCard()->remainingTotal());
    }

    public function test_agreement_with_delivery_cannot_be_deleted_and_blocks_party_deletion(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Alçı', 'torba')->id, 'quantity' => 100, 'unit_price' => 150]]);
        $this->assertContains('1 anlaşma', PartyDeletion::blockers($this->party));

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 10]], now()->toDateString());

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        AgreementDesk::delete($a->fresh());
    }

    public function test_plain_purchase_with_lines_still_has_no_project(): void
    {
        $purchase = TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $this->party->id, 'project_id' => $this->yesilyurt->id,
            'lines' => [['product_id' => $this->product('AT Çimento', 'torba')->id, 'quantity' => 10, 'unit_price' => 200]]]);

        $this->assertNull($purchase->project_id); // Alım Yaptım davranışı değişmedi (Serkan 16.10)
    }

    public function test_party_page_shows_cards_and_button_only_when_module_on(): void
    {
        $this->agreement([['product_id' => $this->product('AT Beton3', 'm³')->id, 'quantity' => 500, 'unit_price' => 3800]]);

        $this->get("/admin/parties/{$this->party->id}/edit")->assertOk()
            ->assertSee('Anlaşma yap')->assertSee('Anlaşmalar')->assertSee('AT Beton3 · Anlaşma Test Yeşilyurt')
            ->assertSee('1.900.000,00')->assertDontSee('Teslim aldım'); // teslim Alım Yaptım penceresinden
        $this->get('/admin/agreements')->assertOk()->assertSee('AT Beton3 · Anlaşma Test Yeşilyurt');

        config(['modules.agreements' => false]);
        $this->get("/admin/parties/{$this->party->id}/edit")->assertOk()->assertDontSee('Anlaşma yap')->assertDontSee('Kalem ekle'); // işlem geçmişinde adı kalır
        $this->get('/admin/agreements')->assertForbidden();
    }

    public function test_windows_open_with_pending_items(): void
    {
        $beton = $this->product('AT Beton4', 'm³');
        $a = $this->agreement([['product_id' => $beton->id, 'quantity' => 300, 'unit_price' => 3800],
            ['product_id' => $this->product('AT Pompa4')->id, 'amount' => '45.000,00']]);

        $page = \Livewire\Livewire::test(\App\Filament\Resources\Parties\Pages\EditParty::class, ['record' => $this->party->getRouteKey()]);
        $page->mountAction('newAgreement')->assertActionMounted('newAgreement')
            ->assertFormFieldExists('direction')->assertFormFieldExists('lines')->assertFormFieldExists('advance')
            ->assertFormFieldHidden('advance_amount')->unmountAction();
        // Alım Yaptım (basit, müteahhit): üstte "Anlaşmadan gelen"; Satış Yaptım'da satış anlaşması yok → bölüm gizli.
        $page->mountAction('newLedgerEntry', ['type' => 'alis'])->assertFormFieldVisible('agreement_lines')
            ->assertFormFieldVisible('entry_date')->assertFormFieldExists('amount')   // iki sekme: Anlaşmadan | Anlaşma dışı
            ->assertActionDataSet(function (array $data) {
                $this->assertCount(2, $data['agreement_lines']);
                $this->assertFalse($data['agreement_lines'][array_key_first($data['agreement_lines'])]['take']); // işaretsiz gelir

                return [];
            })
            ->assertMountedActionModalSee('Anlaşma dışı')->unmountAction();
        $page->mountAction('newLedgerEntry', ['type' => 'satis'])->assertFormFieldDoesNotExist('agreement_lines')
            ->assertFormFieldVisible('amount')->unmountAction();
        $page->mountAction('newLedgerEntry', ['type' => 'odeme'])->assertFormFieldDoesNotExist('agreement_lines')->unmountAction();
        $page->mountAction('addAgreementItems', ['agreement' => $a->id])->assertFormFieldExists('lines')->unmountAction();
        $page->mountAction('changeAgreementPrice', ['item' => $a->items[0]->id])->assertFormFieldExists('unit_price')->unmountAction();

        $this->assertSame('AT Beton4 — kalan 300 m³ × 3.800,00 ₺', \App\Support\Forms\AgreementForm::itemLabel($a->items[0]->id, false));
        $this->assertSame('AT Pompa4 · ' . $a->title . ' — götürü 45.000,00 ₺ · yapılan %0',
            \App\Support\Forms\AgreementForm::itemLabel($a->items[1]->id, true));

        \Livewire\Livewire::test(\App\Filament\Resources\Agreements\Pages\ListAgreements::class)
            ->assertSee('AT Beton4')->assertSee('1.185.000,00')
            ->mountAction('newAgreement')->assertFormFieldExists('party_id');
    }

    public function test_quick_create_from_search_and_unit_is_added_when_missing(): void
    {
        $this->product('AT Hazır Beton', 'm³');

        $results = \App\Support\Forms\AgreementForm::productSearch('AT Elektrik Tesisatı');
        $this->assertContains('➕ Yeni: AT Elektrik Tesisatı', $results);
        $this->assertNotContains('➕ Yeni: AT Hazır Beton', \App\Support\Forms\AgreementForm::productSearch('at hazır beton')); // aynı ad var

        $id = \App\Support\Forms\AgreementForm::quickProduct('AT Elektrik Tesisatı', [], true);
        $product = Product::find($id);
        $this->assertSame(Product::TYPE_SERVICE, $product->type);       // müteahhit: stoksuz
        $this->assertSame($id, \App\Support\Forms\AgreementForm::quickProduct('at elektrik tesisatı', [], true)); // ikinci kart yok

        $withUnit = Product::with('unit')->find(\App\Support\Forms\AgreementForm::quickProduct('AT Çimento2', ['unit_name' => 'AT Torba'], true));
        $this->assertSame('AT Torba', $withUnit->unit->name);
        $again = Product::find(\App\Support\Forms\AgreementForm::quickProduct('AT Alçı2', ['unit_name' => 'at torba'], true));
        $this->assertSame($withUnit->unit_id, $again->unit_id);          // birim tekrar açılmaz
    }

    public function test_quick_create_in_stock_firm_makes_stock_product(): void
    {
        config(['modules.stock' => true]);
        $product = Product::find(\App\Support\Forms\AgreementForm::quickProduct('AT Un 50kg', [], true));

        $this->assertSame(Product::TYPE_PRODUCT, $product->type);
    }

    public function test_party_without_agreement_gets_the_old_purchase_window(): void
    {
        $other = Party::create(['name' => 'Anlaşma Test Anlaşmasız']);

        \Livewire\Livewire::test(\App\Filament\Resources\Parties\Pages\EditParty::class, ['record' => $other->getRouteKey()])
            ->mountAction('newLedgerEntry', ['type' => 'alis'])->assertFormFieldDoesNotExist('agreement_lines')
            ->assertMountedActionModalDontSee('Anlaşma dışı')->assertFormFieldVisible('amount')->assertFormFieldVisible('description');
    }

    public function test_extract_splits_window_data(): void
    {
        [$lines, $project, $rest, $data] = \App\Support\Forms\AgreementForm::extract([
            'agreement_lines' => [['agreement_item_id' => 1, 'take' => true, 'quantity' => 5], ['agreement_item_id' => 2, 'take' => false]],
            'agreement_project' => 'agreement', 'agreement_price_to_rest' => true, 'amount' => '2.500,00', 'entry_date' => '2026-10-21',
        ]);

        $this->assertSame([['agreement_item_id' => 1, 'take' => true, 'quantity' => 5]], $lines);
        $this->assertSame('agreement', $project);
        $this->assertTrue($rest);
        $this->assertSame(['amount' => '2.500,00', 'entry_date' => '2026-10-21'], $data);
    }

    public function test_paid_delivery_writes_purchase_and_payment(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Kireç', 'torba')->id, 'quantity' => 100, 'unit_price' => 120]]);

        AgreementDesk::deliver($this->party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[0]->id, 'quantity' => 10]], now()->toDateString(), 'agreement', false, null, true, 'eft');

        $this->assertSame(0.0, $this->balance());   // alış + ödeme birlikte
        $this->assertTrue(Purchase::where('party_id', $this->party->id)->sole()->paid);
        $this->assertEqualsWithDelta(90, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }

    public function test_purchase_window_saves_agreement_only_and_mixed(): void
    {
        $a = $this->agreement([['product_id' => $this->product('AT Beton5', 'm³')->id, 'quantity' => 300, 'unit_price' => 3800]]);
        $page = \Livewire\Livewire::test(\App\Filament\Resources\Parties\Pages\EditParty::class, ['record' => $this->party->getRouteKey()]);

        // Yalnız anlaşmadan gelen (tutar boş) → tek alış belgesi, başka cari satırı yok
        $page->mountAction('newLedgerEntry', ['type' => 'alis']);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['agreement_lines']);
        $page->set("mountedActions.0.data.agreement_lines.{$key}.take", true)
            ->set("mountedActions.0.data.agreement_lines.{$key}.quantity", '44.5')
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertEqualsWithDelta(169100, Purchase::where('party_id', $this->party->id)->sole()->total_amount, 0.01);
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $this->party->id)->count());

        // Kullanıcı 21.10: "anlaşmadan seçtim, sonra anlaşma dışı seçtim, kaydet dedim, anlaşmadan seçtiklerim kaydolmadı"
        // → sekme değiştirmek bir şey silmez: işaretli kalem (alış belgesi) + anlaşma dışı nakliye (elle satır) ikisi de yazılır.
        $page->mountAction('newLedgerEntry', ['type' => 'alis']);
        $key = array_key_first($page->instance()->mountedActions[0]['data']['agreement_lines']);
        $page->set("mountedActions.0.data.agreement_lines.{$key}.take", true)
            ->set("mountedActions.0.data.agreement_lines.{$key}.quantity", '10')
            ->set('mountedActions.0.data.amount', '2.500,00')
            ->set('mountedActions.0.data.description', 'Nakliye')
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(2, Purchase::where('party_id', $this->party->id)->count());
        $this->assertSame(1, PartyLedgerEntry::where('party_id', $this->party->id)->where('description', 'Nakliye')->count());
        $this->assertEqualsWithDelta(-(169100 + 38000 + 2500), $this->balance(), 0.01);
        $this->assertEqualsWithDelta(245.5, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);

        // Hiçbir şey işaretlenmedi + tutar boş → eski kural: tutar zorunlu, kayıt yok
        $page->mountAction('newLedgerEntry', ['type' => 'alis'])->callMountedAction()->assertHasActionErrors(['amount']);
        $this->assertSame(3, PartyLedgerEntry::where('party_id', $this->party->id)->count());
    }

    public function test_stock_firm_windows_move_stock_and_agreement(): void
    {
        config(['modules' => ModuleProfiles::resolve('trade', ['agreements' => true])]);
        $un = Product::create(['name' => 'AT Un', 'type' => Product::TYPE_PRODUCT, 'is_active' => true]);
        $maya = Product::create(['name' => 'AT Maya', 'type' => Product::TYPE_PRODUCT, 'is_active' => true]);
        $buy = AgreementDesk::create($this->party, ['direction' => Agreement::DIRECTION_PURCHASE,
            'lines' => [['product_id' => $un->id, 'quantity' => 100, 'unit_price' => 850]]])->loadForCard();
        $sell = AgreementDesk::create($this->party, ['direction' => Agreement::DIRECTION_SALE,
            'lines' => [['product_id' => $un->id, 'quantity' => 30, 'unit_price' => 1000]]])->loadForCard();
        $page = \Livewire\Livewire::test(\App\Filament\Resources\Parties\Pages\EditParty::class, ['record' => $this->party->getRouteKey()]);

        // Alım Yaptım (stoklu): Anlaşmadan 40 çuval un + anlaşma dışı 5 maya (ürün satırı)
        $page->mountAction('newPurchase');
        $key = array_key_first($page->instance()->mountedActions[0]['data']['agreement_lines']);
        $page->set("mountedActions.0.data.agreement_lines.{$key}.take", true)
            ->set("mountedActions.0.data.agreement_lines.{$key}.quantity", '40')
            ->set('mountedActions.0.data.lines', ['x' => ['product_id' => $maya->id, 'quantity' => 5, 'unit_price' => '20,00', 'amount' => '100,00']])
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertEqualsWithDelta(40, $un->fresh()->currentStock(), 0.001);
        $this->assertEqualsWithDelta(5, $maya->fresh()->currentStock(), 0.001);
        $this->assertEqualsWithDelta(60, $buy->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
        $this->assertSame(2, Purchase::where('party_id', $this->party->id)->count());

        // Satış Yaptım (kalemli): yalnız Anlaşmadan 10 çuval → stok düşer, satış anlaşmasından düşer
        $page->mountAction('newSale');
        $key = array_key_first($page->instance()->mountedActions[0]['data']['agreement_lines']);
        $page->set("mountedActions.0.data.agreement_lines.{$key}.take", true)
            ->set("mountedActions.0.data.agreement_lines.{$key}.quantity", '10')
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertEqualsWithDelta(30, $un->fresh()->currentStock(), 0.001);
        $this->assertEqualsWithDelta(20, $sell->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
        $this->assertSame(1, Sale::where('party_id', $this->party->id)->count());
        $this->assertEqualsWithDelta(-(34000 + 100) + 10000, $this->balance(), 0.01);
    }
}
