<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Parties\Pages\EditParty;
use App\Models\ActivityLog;
use App\Models\Check;
use App\Models\LandBlock;
use App\Models\LandShareholder;
use App\Models\LandShareStudy;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Reminder;
use App\Models\Sale;
use App\Models\User;
use App\Support\ActivityBackfill;
use App\Support\TradeDesk;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * İşlem geçmişi (kullanıcı 21.10): kullanıcının yaptığı her iş bir satır, kanalıyla; silinen kaydın tüm ayrıntısı kalır;
 * belgelerin arka plan parçaları ayrı satır değil; cari silinince ekstresiyle birlikte her şey yazılır.
 */
class ActivityLogTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        ActivityLog::clearVia();
    }

    protected function tearDown(): void
    {
        ActivityLog::clearVia();
        parent::tearDown();
    }

    private function logsFor(string $type, int $id)
    {
        return ActivityLog::where('subject_type', $type)->where('subject_id', $id)->orderBy('id')->get();
    }

    public function test_collection_from_panel_is_one_row_with_channel_and_user(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Emre Test']));
        $party = Party::create(['name' => 'Log Test Ali']);

        $entry = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-21', 'type' => PartyLedgerEntry::TYPE_COLLECTION,
            'amount' => 5000, 'payment_type' => 'cash', 'description' => 'Peşinat']);

        $log = $this->logsFor('party_ledger_entry', $entry->id)->sole();
        $this->assertSame('created', $log->event);
        $this->assertSame('Para aldı', $log->action);
        $this->assertSame('panel', $log->channel);
        $this->assertSame('Emre Test', $log->actor);
        $this->assertSame($party->id, $log->party_id);
        $this->assertSame('Log Test Ali', $log->party_name);
        $this->assertSame('5.000,00 ₺', $log->details['Tutar']);
        $this->assertSame('Nakit', $log->details['Ödeme yöntemi']);
    }

    public function test_edit_keeps_old_and_new_amount(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Log Test Düzen']);
        $entry = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-20', 'type' => PartyLedgerEntry::TYPE_PURCHASE,
            'amount' => 25000, 'description' => 'Börek unu']);

        $entry->update(['amount' => 27500]);

        $log = $this->logsFor('party_ledger_entry', $entry->id)->last();
        $this->assertSame('updated', $log->event);
        $this->assertEqualsWithDelta(25000, (float) $log->old_amount, 0.001);
        $this->assertEqualsWithDelta(27500, (float) $log->amount, 0.001);
        $this->assertSame(['25.000,00 ₺', '27.500,00 ₺'], $log->changes['Tutar']);
        $this->assertCount(1, $log->changes, 'yalnız değişen alan');
    }

    public function test_deleted_payment_keeps_full_details_and_its_checks(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Log Test Seramik']);
        $entry = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-21', 'type' => PartyLedgerEntry::TYPE_PAYMENT,
            'amount' => 200000, 'payment_type' => 'check', 'description' => 'Bağlantı']);
        $check = Check::create(['party_id' => $party->id, 'party_ledger_entry_id' => $entry->id, 'check_number' => '2023471',
            'due_date' => '2027-02-28', 'amount' => 200000, 'status' => 'issued']);

        $entry->delete();

        $log = $this->logsFor('party_ledger_entry', $entry->id)->last();
        $this->assertSame('deleted', $log->event);
        $this->assertSame('Ödeme yaptı', $log->action);
        $this->assertSame('Bağlantı', $log->details['Açıklama']);
        $this->assertStringContainsString('2023471', $log->details['Çekler'], 'silinen ödemenin çekleri ayrıntıda');
        $checkLog = $this->logsFor('check', $check->id)->last();
        $this->assertSame('deleted', $checkLog->event);
        $this->assertSame('Bağlı ödeme/tahsilat silindiği için', $checkLog->note);
    }

    public function test_deleting_party_writes_statement_and_every_cascaded_record(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Silen Kişi']));
        config(['modules.stock' => true, 'modules.purchases' => true]);
        $party = Party::create(['name' => 'Log Test Uncu', 'phone' => '05321112233']);
        $collection = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-01', 'type' => PartyLedgerEntry::TYPE_COLLECTION,
            'amount' => 1000, 'description' => 'Avans geri']);
        $purchase = TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id, 'manual_amount' => '25.000,00', 'notes' => 'Log Unu']);

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->callAction(DeleteAction::class, data: ['confirm_phrase' => 'eminim sil']);
        $this->assertNull($party->fresh());

        $partyLog = $this->logsFor('party', $party->id)->last();
        $this->assertSame('deleted', $partyLog->event);
        $this->assertSame('Silen Kişi', $partyLog->actor);
        $this->assertSame('05321112233', $partyLog->details['Telefon']);
        $this->assertStringContainsString('Avans geri', $partyLog->details['Ekstre']);
        $this->assertStringContainsString('25.000,00 ₺', $partyLog->details['Ekstre']);
        $this->assertArrayHasKey('Bakiye', $partyLog->details);

        $this->assertSame('Kişi silindiği için', $this->logsFor('party_ledger_entry', $collection->id)->last()->note);
        $purchaseLog = $this->logsFor('purchase', $purchase->id)->last();
        $this->assertSame('deleted', $purchaseLog->event);
        $this->assertSame('Log Unu', $purchaseLog->details['Not']);
        $this->assertSame('25.000,00 ₺', $purchaseLog->details['Toplam']);
        // Cari silinse de geçmişi cari adıyla durur.
        $this->assertSame('Log Test Uncu', ActivityLog::where('party_id', $party->id)->where('event', 'deleted')->first()->party_name);
    }

    public function test_stock_purchase_is_one_row_with_lines_and_real_total(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.stock' => true, 'modules.purchases' => true]);
        $party = Party::create(['name' => 'Log Test Tedarik']);
        $flour = Product::create(['name' => 'Log Börek Unu', 'type' => Product::TYPE_PRODUCT]);

        $purchase = TradeDesk::savePurchase(['mode' => 'cash', 'party_id' => $party->id, 'payment_type' => 'cash',
            'lines' => [['product_id' => $flour->id, 'quantity' => 20, 'amount' => '25.000,00']]]);

        $log = $this->logsFor('purchase', $purchase->id)->sole();
        $this->assertSame('created', $log->event);
        $this->assertSame('Peşin alım', $log->action);
        $this->assertEqualsWithDelta(25000, (float) $log->amount, 0.001, 'toplam 0 değil, son hal');
        $this->assertStringContainsString('20 Log Börek Unu', $log->details['Ürünler']);
        $this->assertSame(0, ActivityLog::where('subject_type', 'party_ledger_entry')->where('party_id', $party->id)->count(),
            'belgenin cari satırları ayrı satır değil');
        $this->assertSame(0, ActivityLog::where('subject_type', 'stock_movement')->where('summary', 'like', '%Log Börek Unu%')->count(),
            'belgenin stok satırları ayrı satır değil');

        // Düzenleme: eski → yeni toplam, bir satır.
        $purchase->update(['notes' => 'Muhyiddin']);
        $purchase->rebuildFromLines([['product_id' => $flour->id, 'quantity' => 22, 'amount' => '27.500,00']]);
        $edit = $this->logsFor('purchase', $purchase->id)->last();
        $this->assertSame('updated', $edit->event);
        $this->assertEqualsWithDelta(25000, (float) $edit->old_amount, 0.001);
        $this->assertEqualsWithDelta(27500, (float) $edit->amount, 0.001);
        $this->assertArrayHasKey('Ürünler', $edit->changes);
        $this->assertArrayHasKey('Not', $edit->changes);
        $this->assertCount(2, $this->logsFor('purchase', $purchase->id));
    }

    public function test_cash_sale_row_has_final_cash_card_split(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.stock' => true, 'modules.sales_desk' => true, 'modules.direct_sales' => true]);
        $bread = Product::create(['name' => 'Log Ekmek', 'type' => Product::TYPE_PRODUCT]);

        $sale = TradeDesk::saveSale(['mode' => 'cash', 'payment' => 'card',
            'lines' => [['product_id' => $bread->id, 'quantity' => 10, 'amount' => '100,00']]]);

        $log = $this->logsFor('sale', $sale->id)->sole();
        $this->assertSame('Peşin satış', $log->action);
        $this->assertSame('100,00 ₺', $log->details['Kart / banka']);
        $this->assertArrayNotHasKey('Nakit', $log->details);
    }

    public function test_sale_return_and_its_deletion_with_sale(): void
    {
        $this->actingAs(User::factory()->create());
        config(['modules.stock' => true, 'modules.direct_sales' => true]);
        $party = Party::create(['name' => 'Log Test Müşteri']);
        $p = Product::create(['name' => 'Log Fayans', 'type' => Product::TYPE_PRODUCT]);
        $sale = Sale::create(['party_id' => $party->id, 'sale_date' => '2026-10-20']);
        $sale->rebuildFromLines([['product_id' => $p->id, 'quantity' => 10, 'unit_price' => '50,00']]);

        $return = $sale->processReturn([['product_id' => $p->id, 'return_qty' => 2, 'unit_price' => '50,00']], '2026-10-21');
        $this->assertSame('İade aldı', $this->logsFor('sale_return', $return->id)->sole()->action);

        $sale->delete();
        $this->assertSame('deleted', $this->logsFor('sale', $sale->id)->last()->event);
        $returnDeleted = $this->logsFor('sale_return', $return->id)->last();
        $this->assertSame('deleted', $returnDeleted->event, 'satışla veritabanında giden iade de yazıldı');
        $this->assertStringContainsString('silindiği için', $returnDeleted->note);
    }

    public function test_whatsapp_and_system_channels(): void
    {
        $party = Party::create(['name' => 'Log Test Kanal']);

        ActivityLog::via('whatsapp', 'Emre (0532 059 11 24)');
        $wa = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-21', 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 100]);
        ActivityLog::clearVia();
        $sys = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-21', 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 200]);

        $this->assertSame(['whatsapp', 'Emre (0532 059 11 24)'], [$this->logsFor('party_ledger_entry', $wa->id)->sole()->channel, $this->logsFor('party_ledger_entry', $wa->id)->sole()->actor]);
        $this->assertSame('system', $this->logsFor('party_ledger_entry', $sys->id)->sole()->channel, 'konsol (tinker değil) = sistem');
    }

    public function test_rolled_back_work_leaves_no_row(): void
    {
        $party = Party::create(['name' => 'Log Test Geri']);
        $before = ActivityLog::count();

        try {
            DB::transaction(function () use ($party) {
                PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-21', 'type' => PartyLedgerEntry::TYPE_PAYMENT, 'amount' => 100]);
                throw new \RuntimeException('yarıda kaldı');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, ActivityLog::count());
    }

    public function test_technical_field_changes_write_nothing(): void
    {
        $reminder = Reminder::create(['phone' => '905551112233', 'text' => 'Log kira', 'event_date' => '2031-05-12', 'status' => 'active']);
        $product = Product::create(['name' => 'Log Teknik', 'type' => Product::TYPE_PRODUCT]);
        $count = ActivityLog::count();

        $reminder->update(['last_sent_at' => now(), 'attempts' => 1, 'next_fire_at' => now()->addDay(), 'status' => 'sending']);
        $product->update(['low_stock_alerted_at' => now(), 'usage_booked_through' => now()]);
        $this->assertSame($count, ActivityLog::count(), 'gönderim/uyarı işaretleri iş değil');

        $reminder->update(['text' => 'Log kira 25.000']);
        $this->assertSame($count + 1, ActivityLog::count(), 'pozitif kontrol: gerçek değişiklik yazılır');
    }

    public function test_calendar_style_bulk_cancel_is_written(): void
    {
        $reminder = Reminder::create(['phone' => '905551112233', 'text' => 'Log iptal', 'event_date' => '2031-05-12', 'status' => 'active']);

        ActivityLog::updateQuery(Reminder::whereKey($reminder->id), ['status' => 'cancelled', 'next_fire_at' => null]);

        $log = $this->logsFor('reminder', $reminder->id)->last();
        $this->assertSame('updated', $log->event);
        $this->assertSame(['Aktif', 'İptal'], $log->changes['Durum']);
        $this->assertSame('cancelled', $reminder->fresh()->status);
    }

    public function test_password_is_never_written(): void
    {
        $user = User::factory()->create(['name' => 'Log Kullanıcı', 'password' => 'gizli-sifre-123']);
        $user->update(['password' => 'yeni-sifre-456']);

        $logs = $this->logsFor('user', $user->id);
        $this->assertCount(2, $logs);
        $this->assertArrayHasKey('Şifre', $logs->last()->changes, 'şifrenin değiştiği görünür');
        foreach ($logs as $log) {
            $raw = json_encode([$log->details, $log->changes]);
            $this->assertStringNotContainsString('gizli-sifre', $raw);
            $this->assertStringNotContainsString('yeni-sifre', $raw);
            $this->assertStringNotContainsString(substr($user->password, 0, 20), $raw, 'hash de yazılmaz');
        }
    }

    public function test_child_rows_touch_parent_once_per_request(): void
    {
        $project = Project::create(['name' => 'Log Proje', 'status' => 'active']);
        $study = LandShareStudy::create(['project_id' => $project->id, 'name' => 'Log Hisse']);
        $before = $this->logsFor('land_share_study', $study->id)->count();

        $block = LandBlock::create(['study_id' => $study->id, 'name' => 'A']);
        LandShareholder::create(['study_id' => $study->id, 'name' => 'Ahmet']);
        LandShareholder::create(['study_id' => $study->id, 'name' => 'Mehmet']);
        $block->update(['name' => 'A Blok']);

        $this->assertSame($before, $this->logsFor('land_share_study', $study->id)->count(), 'aynı istekte eklenen kayıt zaten yazıldı');
    }

    public function test_party_page_and_history_page_show_deleted_record(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Log Test Ekran']);
        $entry = PartyLedgerEntry::create(['party_id' => $party->id, 'entry_date' => '2026-10-21', 'type' => PartyLedgerEntry::TYPE_COLLECTION,
            'amount' => 7777, 'description' => 'Yanlışlıkla silinen tahsilat']);
        $entry->delete();

        Livewire::test(EditParty::class, ['record' => $party->getRouteKey()])
            ->assertSee('İşlem Geçmişi')
            ->assertSee('Silindi')
            ->assertSee('Yanlışlıkla silinen tahsilat');

        $deleted = ActivityLog::where('subject_type', 'party_ledger_entry')->where('subject_id', $entry->id)->where('event', 'deleted')->sole();
        Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$deleted]);
        // Satıra tıklayınca açılan ayrıntı (aynı şablon): silinmeden önceki hal.
        $html = view('filament.activity.row', ['log' => $deleted, 'showParty' => true, 'open' => true])->render();
        $this->assertStringContainsString('Silinmeden önceki hali', $html);
        $this->assertStringContainsString('7.777,00 ₺', $html);
        $this->assertStringContainsString('Log Test Ekran', $html);
    }

    public function test_history_page_hides_backfilled_rows_by_default(): void
    {
        $this->actingAs(User::factory()->create());
        $party = Party::create(['name' => 'Log Test Önceden']);
        $existing = ActivityLog::create(['happened_at' => now()->subMinute(), 'event' => 'existing', 'action' => 'Cari', 'subject_type' => 'party',
            'subject_id' => $party->id, 'party_id' => $party->id, 'party_name' => $party->name]);
        $created = $this->logsFor('party', $party->id)->firstWhere('event', 'created');

        Livewire::test(ListActivityLogs::class)
            ->assertCanSeeTableRecords([$created])
            ->assertCanNotSeeTableRecords([$existing])
            ->filterTable('existing', true)
            ->assertCanSeeTableRecords([$existing]);
    }

    public function test_backfill_writes_existing_records_once_without_document_parts(): void
    {
        config(['modules.stock' => true, 'modules.purchases' => true]);
        $party = Party::create(['name' => 'Log Test Eski']);
        $purchase = TradeDesk::savePurchase(['mode' => 'party', 'party_id' => $party->id, 'manual_amount' => '1.000,00']);
        ActivityLog::query()->delete();

        ActivityBackfill::run();

        $this->assertSame('existing', $this->logsFor('party', $party->id)->sole()->event);
        $this->assertSame('existing', $this->logsFor('purchase', $purchase->id)->sole()->event);
        $this->assertSame(0, ActivityLog::where('subject_type', 'party_ledger_entry')->where('party_id', $party->id)->count());
    }
}
