<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\Check;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\User;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\AgreementDraft;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * WhatsApp'tan anlaşma (21.10, kullanıcının 4 sözleşme fotoğrafı): kalem kalem anlaşma + ödeme planı, borç yazmaz.
 * Okunamayan yer sorulur ("geç" ile atlanır); devir nakit sayılır; düzeltmede kalemler kaybolmaz. AI mock.
 */
class WhatsappAgreementTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880021';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('muteahhit', ['agreements' => true]), 'app.profile' => 'muteahhit',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 21'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake(['*' => Http::response('jpeg', 200, ['Content-Type' => 'image/jpeg'])]);
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $this->actingAs(User::factory()->create());
    }

    private function agreement(array $o): array
    {
        return array_merge(['kind' => ExpenseExtractor::KIND_AGREEMENT, 'items' => [], 'amount' => 0, 'paid' => null,
            'date' => '2026-04-01', 'description' => '', 'party_id' => null, 'party_name' => null, 'project_id' => null,
            'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true],
            AgreementDraft::sanitize($o), array_diff_key($o, array_flip(['agreement_items', 'agreement_payments', 'unreadable', 'agreement_direction'])));
    }

    private function ai(array ...$r): void
    {
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->andReturn(...$r);
        $this->app->instance(ExpenseExtractor::class, $m);
    }

    private function send(string $body, array $extra = []): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', $extra + ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    private function sendPhoto(): string
    {
        return $this->send('', ['NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/MEA1', 'MediaContentType0' => 'image/jpeg']);
    }

    /** Hüseyin Yılmaz seramik belgesi (kısaltılmış): miktarlı + bedelsiz + götürü nakliye; devir/nakit/3 senet. */
    public function test_document_agreement_with_payment_plan(): void
    {
        $this->ai($this->agreement([
            'party_name' => 'WA Anlaşma Hüseyin Yılmaz', 'description' => 'Seramik ve vitrifiye',
            'agreement_items' => [
                ['description' => 'WAA Vitra Bone 60x120', 'quantity' => 1244.16, 'unit' => 'm²', 'unit_price' => 450],
                ['description' => 'WAA Asma klozet', 'quantity' => 14, 'unit' => 'adet', 'unit_price' => 4000],
                ['description' => 'WAA Klozet kapağı', 'quantity' => 14, 'unit' => 'adet', 'free' => true],
                ['description' => 'WAA Nakliye', 'amount' => 12000],
            ],
            'agreement_payments' => [
                ['method' => 'cash', 'amount' => 382000],                                      // Abdullah Üçer'den devir → nakit
                ['method' => 'cash', 'amount' => 160000, 'paid_date' => '2026-04-15'],
                ['method' => 'promissory_note', 'amount' => 200000, 'due_date' => '2026-07-30'],
                ['method' => 'promissory_note', 'amount' => 200000, 'due_date' => '2026-10-30'],
                ['method' => 'promissory_note', 'amount' => 200000, 'due_date' => '2026-12-30'],
            ],
        ]));

        $card = $this->sendPhoto();
        $this->assertStringContainsString('🤝 *Anlaşma* — WA Anlaşma Hüseyin Yılmaz (yeni cari)', $card);
        $this->assertStringContainsString('WAA Vitra Bone 60x120 1.244,16 m² × 450,00 = 559.872,00', $card);
        $this->assertStringContainsString('WAA Klozet kapağı 14 adet — bedelsiz', $card);
        $this->assertStringContainsString('WAA Nakliye · götürü 12.000,00 ₺', $card);
        $this->assertStringContainsString('Anlaşılan: *627.872,00 ₺*', $card);
        $this->assertStringContainsString('nakit 542.000,00 · 3 senet 600.000,00 (vade 30.07.2026 · 30.10.2026 · 30.12.2026)', $card);
        $this->assertStringContainsString('Borç yazılmaz', $card);

        $done = $this->send('evet');
        $this->assertStringContainsString('✅ Anlaşma kaydedildi: *Seramik ve vitrifiye*', $done);

        $party = Party::where('name', 'WA Anlaşma Hüseyin Yılmaz')->sole();
        $a = Agreement::where('party_id', $party->id)->sole()->loadForCard();
        $this->assertSame(Agreement::DIRECTION_PURCHASE, $a->direction);
        $this->assertSame('2026-04-01', $a->agreement_date->toDateString());
        $this->assertCount(4, $a->items);
        $this->assertEqualsWithDelta(627872, $a->agreedTotal(), 0.01);
        $this->assertEqualsWithDelta(14, $a->items[2]->quantity, 0.001);   // bedelsiz kalem de gelecek
        $this->assertTrue($a->items[3]->is_lump);
        // Ödemeler cariye: 1.142.000 ödendi, anlaşma borç yazmadı → biz alacaklıyız
        $this->assertEqualsWithDelta(1142000, PartyLedgerEntry::where('party_id', $party->id)->where('type', PartyLedgerEntry::TYPE_PAYMENT)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(1142000, PartyStatement::build($party)['balance'], 0.01);
        $this->assertSame(3, PartyLedgerEntry::where('party_id', $party->id)->where('payment_type', 'promissory_note')->count());
        $this->assertStringContainsString('Senet, vade 30.07.2026', PartyLedgerEntry::where('party_id', $party->id)->where('payment_type', 'promissory_note')->orderBy('id')->value('description'));
        $this->assertSame('2026-04-15', PartyLedgerEntry::where('party_id', $party->id)->where('amount', 160000)->value('entry_date')->toDateString());
    }

    /** Alim Tok el yazısı fiyat listesi: okunamayan yer sorulur, "geç" ile kart onaya gelir; çekler Çekler'e vadesiyle. */
    public function test_unreadable_is_asked_and_skip_brings_card(): void
    {
        $this->ai($this->agreement([
            'party_name' => 'WA Anlaşma Alim Tok',
            'agreement_items' => [
                ['description' => 'WAA 13\'lük tuğla', 'unit' => 'adet', 'unit_price' => 8.5],
                ['description' => 'WAA Çimento', 'unit_price' => 125],
                ['description' => 'WAA Kiremit', 'unit' => 'adet', 'unit_price' => 27],
            ],
            'agreement_payments' => [
                ['method' => 'check', 'amount' => 200000, 'due_date' => '2026-07-15'],
                ['method' => 'check', 'amount' => 400000, 'due_date' => '2026-08-20'],
            ],
            'unreadable' => ['Çek tarihleri verildiği gün mü vade mi', 'Çimentonun birimi'],
        ]));

        $ask = $this->sendPhoto();
        $this->assertStringContainsString("❓ Şurayı tam okuyamadım:\n• Çek tarihleri verildiği gün mü vade mi\n• Çimentonun birimi", $ask);
        $this->assertStringContainsString('WAA Çimento · 125,00 ₺ (fiyat listesi)', $ask);
        $this->assertStringContainsString('*geç*', $ask);
        $this->assertStringContainsString('❓', $this->send('evet'));             // soru varken kaydetmez
        $this->assertSame(0, Party::where('name', 'WA Anlaşma Alim Tok')->count()); // cari de onaydan sonra açılır

        $card = $this->send('geç');
        $this->assertStringNotContainsString('okuyamadım', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Alim Tok')->sole();
        $a = Agreement::where('party_id', $party->id)->sole()->loadForCard();
        $this->assertTrue($a->items->every(fn ($i) => $i->isList()));
        $this->assertSame(0.0, $a->agreedTotal());
        $checks = Check::where('party_id', $party->id)->orderBy('due_date')->get();
        $this->assertSame(['2026-07-15', '2026-08-20'], $checks->map(fn ($c) => $c->due_date->toDateString())->all());
        $this->assertSame(['issued', 'issued'], $checks->pluck('status')->all());   // verdiğimiz çek → vade hatırlatması
    }

    /** Okunamayan yere cevap: AI yalnız değişen kalemi döndürür — diğer kalemler kaybolmaz, fiyat korunur. */
    public function test_answer_updates_one_item_and_keeps_others(): void
    {
        $this->ai(
            $this->agreement(['party_name' => 'WA Anlaşma Alim Tok 2', 'agreement_items' => [
                ['description' => 'WAA2 Tuğla', 'unit' => 'adet', 'unit_price' => 8.5],
                ['description' => 'WAA2 Çimento', 'unit_price' => 125],
                ['description' => 'WAA2 Kiremit', 'unit' => 'adet', 'unit_price' => 27],
            ], 'unreadable' => ['Çimentonun birimi']]),
            $this->agreement(['party_name' => 'WA Anlaşma Alim Tok 2', 'is_new_entry' => false,
                'agreement_items' => [['description' => 'WAA2 çimento', 'unit' => 'torba']]]),
        );

        $this->sendPhoto();
        $card = $this->send('çimento torba');

        $this->assertStringContainsString('WAA2 Tuğla · 8,50 ₺/adet', $card);
        $this->assertStringContainsString('WAA2 Çimento · 125,00 ₺/torba', $card);
        $this->assertStringContainsString('WAA2 Kiremit · 27,00 ₺/adet', $card);
        $this->assertStringNotContainsString('okuyamadım', $card);
    }

    /** Yazıyla: "Ali Usta'yla tesisatı 1 milyona anlaştık, 200 bin peşin verdim" → götürü + nakit peşinat. */
    public function test_text_lump_sum_with_advance(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Ali Usta', 'description' => 'Elektrik tesisatı', 'date' => now()->toDateString(),
            'agreement_items' => [['description' => 'WAA Elektrik tesisatı', 'amount' => 1000000]],
            'agreement_payments' => [['method' => 'cash', 'amount' => 200000]]]));

        $card = $this->send("Ali Usta'yla elektrik tesisatını 1 milyona anlaştık, 200 bin peşin verdim");
        $this->assertStringContainsString('WAA Elektrik tesisatı · götürü 1.000.000,00 ₺', $card);
        $this->assertStringContainsString('💸 Ödeme: nakit 200.000,00', $card);
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Ali Usta')->sole();
        $this->assertEqualsWithDelta(1000000, Agreement::where('party_id', $party->id)->sole()->loadForCard()->agreedTotal(), 0.01);
        $this->assertEqualsWithDelta(200000, PartyStatement::build($party)['balance'], 0.01); // yalnız peşinat; iş yapılmadı → borç yok
    }

    public function test_sale_agreement_and_missing_party(): void
    {
        $this->ai($this->agreement(['agreement_direction' => 'sale', 'agreement_items' => [['description' => 'WAA Daire', 'quantity' => 1, 'unit_price' => 4500000]]]));
        $this->assertStringContainsString('Kiminle anlaştın?', $this->send('A blok 3 nolu daireyi 4,5 milyona sattık, anlaştık'));

        $this->ai($this->agreement(['agreement_direction' => 'sale', 'party_name' => 'WA Anlaşma Ahmet', 'is_new_entry' => false,
            'agreement_items' => [['description' => 'WAA Daire', 'quantity' => 1, 'unit_price' => 4500000]],
            'agreement_payments' => [['method' => 'eft', 'amount' => 500000]]]));
        $card = $this->send('Ahmet Yılmaz');
        $this->assertStringContainsString('WA Anlaşma Ahmet (yeni cari) (satış)', $card);
        $this->assertStringContainsString('💸 Alınan: EFT 500.000,00', $card);
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Ahmet')->sole();
        $this->assertSame(Agreement::DIRECTION_SALE, Agreement::where('party_id', $party->id)->sole()->direction);
        $this->assertSame(PartyLedgerEntry::TYPE_COLLECTION, PartyLedgerEntry::where('party_id', $party->id)->sole()->type);
    }

    /** Belge okundu ama türü anlaşılamadı (21.10 Alim Tok: kind=null → yardım menüsü gitti) → menü değil soru. */
    public function test_unclear_document_asks_instead_of_help_menu(): void
    {
        $this->ai(['kind' => ExpenseExtractor::KIND_HELP, 'reply' => null, 'amount' => 0, 'description' => '', 'paid' => null,
            'confidence' => 'low', 'items' => [], 'is_new_entry' => true]);

        $reply = $this->sendPhoto();
        $this->assertStringContainsString('Belgeyi aldım ama ne olduğunu tam anlayamadım', $reply);
        $this->assertStringNotContainsString('Bana normal konuşur gibi yazman yeterli', $reply);
    }

    public function test_agreement_kind_only_when_module_on(): void
    {
        $this->assertContains(ExpenseExtractor::KIND_AGREEMENT, ExpenseExtractor::allowedKinds());
        $this->assertArrayHasKey('agreement_items', app(ExpenseExtractor::class)->entrySchema()['properties']);

        config(['modules.agreements' => false]);
        $this->assertNotContains(ExpenseExtractor::KIND_AGREEMENT, ExpenseExtractor::allowedKinds());
        $this->assertArrayNotHasKey('agreement_items', app(ExpenseExtractor::class)->entrySchema()['properties']);
    }

    // ── Anlaşmadan teslim ───────────────────────────────────────────────────────────────────────────────────

    private function deliveryAgreement(Party $party, array $lines, ?int $projectId = null, string $direction = Agreement::DIRECTION_PURCHASE): Agreement
    {
        return \App\Support\AgreementDesk::create($party, ['direction' => $direction, 'agreement_date' => now()->subDays(3)->toDateString(),
            'project_id' => $projectId, 'lines' => $lines])->loadForCard();
    }

    private function product(string $name, ?string $unit = null): int
    {
        return \App\Support\Forms\AgreementForm::quickProduct($name, ['unit_name' => $unit], true);
    }

    private function delivery(array $o): array
    {
        return array_merge(['kind' => ExpenseExtractor::KIND_AGREEMENT_DELIVERY, 'items' => [], 'amount' => 0, 'paid' => null,
            'date' => now()->toDateString(), 'description' => '', 'party_id' => null, 'party_name' => null, 'project_id' => null,
            'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true],
            \App\Services\Whatsapp\AgreementDeliveryDraft::sanitize($o), array_diff_key($o, array_flip(['delivery_lines', 'delivery_candidates'])));
    }

    public function test_delivery_single_match_writes_purchase_with_agreement_project(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak Beton']);
        $project = \App\Models\Project::create(['name' => 'WAT Yeşilyurt', 'status' => 'active']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Beton', 'm³'), 'quantity' => 500, 'unit_price' => 3800]], $project->id);
        $this->assertStringContainsString($a->items[0]->id . ': WAT Kuşak Beton', implode("\n", \App\Services\Whatsapp\AgreementDeliveryDraft::contextLines()));

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 44.5, 'description' => 'beton']]]));
        $card = $this->send('kuşaktan 44 buçuk metreküp beton geldi');
        $this->assertStringContainsString('📥 *WAT Kuşak Beton* — anlaşmadan', $card);
        $this->assertStringContainsString('WAT Beton 44,50 m³ × 3.800,00 = 169.100,00 · WAT Yeşilyurt — kalan 455,50 m³', $card);
        $this->assertStringContainsString('Sonra: borcun 169.100,00 ₺', $card);

        $done = $this->send('evet');
        $this->assertStringContainsString('✅ Kaydedildi: 169.100,00 ₺ alış — anlaşmadan düşüldü.', $done);
        $this->assertStringContainsString('WAT Beton kalan 455,50 m³', $done);
        $purchase = \App\Models\Purchase::where('party_id', $party->id)->sole();
        $this->assertSame($project->id, $purchase->project_id);   // maliyet şantiyeye
        $this->assertEqualsWithDelta(455.5, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }

    public function test_delivery_ambiguous_asks_which_agreement(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak 2']);
        $beton = $this->product('WAT Beton2', 'm³');
        $a1 = $this->deliveryAgreement($party, [['product_id' => $beton, 'quantity' => 100, 'unit_price' => 3800]]);
        $a2 = $this->deliveryAgreement($party, [['product_id' => $beton, 'quantity' => 50, 'unit_price' => 4100]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => null, 'quantity' => 20, 'description' => 'beton']],
            'delivery_candidates' => [$a1->items[0]->id, $a2->items[0]->id]]));
        $ask = $this->send('kuşaktan 20 metreküp beton geldi');
        $this->assertStringContainsString('2 açık anlaşmada "beton" var', $ask);
        $this->assertStringContainsString('*2* ' . $a2->title . ' · kalan 50 m³ × 4.100,00', $ask);
        $this->assertStringContainsString('*0* Anlaşma dışı', $ask);

        $card = $this->send('2');
        $this->assertStringContainsString('WAT Beton2 20 m³ × 4.100,00 = 82.000,00', $card);
        $this->send('evet');
        $this->assertEqualsWithDelta(30, $a2->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
        $this->assertEqualsWithDelta(100, $a1->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }

    public function test_delivery_price_difference_offers_three_choices(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak 3']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Beton3', 'm³'), 'quantity' => 100, 'unit_price' => 3800]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 30, 'unit_price' => 4000]]]));
        $ask = $this->send('kuşaktan 30 metreküp beton geldi 4000 den');
        $this->assertStringContainsString("Anlaşmadaki fiyat 3.800,00 ₺, sen 4.000,00 yazdın.\n*1* Yalnız bu teslimat 4.000,00\n*2* Bundan sonra hepsi 4.000,00 (zam)", $ask);

        $this->assertStringContainsString('= 120.000,00', $this->send('2'));
        $this->send('evet');
        $items = $a->fresh()->loadForCard()->items;
        $this->assertCount(2, $items);                                  // zam: eski kalem kapandı, kalan yeni fiyattan
        $this->assertEqualsWithDelta(4000, $items[1]->unit_price, 0.01);
        $this->assertEqualsWithDelta(70, $items[1]->quantity, 0.001);
    }

    public function test_delivery_missing_quantity_then_number(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak 4']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Beton4', 'm³'), 'quantity' => 100, 'unit_price' => 3800]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id]]]));
        $this->assertStringContainsString('Kaç m³ WAT Beton4 geldi?', $this->send('kuşaktan beton geldi'));
        $card = $this->send('44');
        $this->assertStringContainsString('WAT Beton4 44 m³ × 3.800,00 = 167.200,00', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
    }

    public function test_delivery_lump_sum_half_with_advance(): void
    {
        $party = Party::create(['name' => 'WAT Ali Usta']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Elektrik tesisatı'), 'amount' => 1000000]]);
        \App\Support\AgreementDesk::recordPayments($a, [['method' => 'cash', 'amount' => 200000]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'amount' => 500000]]]));
        $card = $this->send('ali usta tesisatın yarısını bitirdi');
        $this->assertStringContainsString('WAT Elektrik tesisatı %50 · 500.000,00 ₺', $card);
        $this->assertStringContainsString('Sonra: borcun 300.000,00 ₺', $card);   // peşinat düşüldü
        $this->send('evet');
        $this->assertSame(50, $a->fresh()->loadForCard()->progress());
    }

    public function test_sale_delivery_and_unknown_item(): void
    {
        $party = Party::create(['name' => 'WAT Ahmet Alıcı']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Daire'), 'quantity' => 1, 'unit_price' => 4500000]],
            null, Agreement::DIRECTION_SALE);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 1]]]));
        $card = $this->send('Ahmet beye daireyi teslim ettim');
        $this->assertStringContainsString('📤 *WAT Ahmet Alıcı* — anlaşmadan', $card);
        $this->assertStringContainsString('Sonra: sana borcu 4.500.000,00 ₺', $card);
        $this->assertStringContainsString('satış — anlaşmadan düşüldü', $this->send('evet'));
        $this->assertSame(1, \App\Models\Sale::where('party_id', $party->id)->count());

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => 999999999, 'quantity' => 5]]]));
        $this->assertStringContainsString('hangi anlaşmaya yazacağımı bulamadım', $this->send('5 kamyon kum geldi'));
    }

    // ── Anlaşma sorusu ────────────────────────────────────────────────────────────────────────────────────────

    public function test_query_by_item_and_by_party(): void
    {
        $party = Party::create(['name' => 'WAQ Seramikçi']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAQ Fayans', 'm²'), 'quantity' => 1000, 'unit_price' => 400],
            ['product_id' => $this->product('WAQ Klozet', 'adet'), 'quantity' => 20, 'unit_price' => 3200]]);
        \App\Support\AgreementDesk::deliver($party, Agreement::DIRECTION_PURCHASE, [['agreement_item_id' => $a->items[0]->id, 'quantity' => 250]], now()->toDateString());
        $usta = Party::create(['name' => 'WAQ Ali Usta']);
        $b = $this->deliveryAgreement($usta, [['product_id' => $this->product('WAQ Tesisat'), 'amount' => 1000000]]);
        \App\Support\AgreementDesk::recordPayments($b, [['method' => 'cash', 'amount' => 200000]]);
        \App\Support\AgreementDesk::deliver($usta, Agreement::DIRECTION_PURCHASE, [['agreement_item_id' => $b->items[0]->id, 'amount' => 500000]], now()->toDateString());

        $this->ai(['kind' => ExpenseExtractor::KIND_AGREEMENT_QUERY, 'query_item_ids' => [$a->items[0]->id], 'amount' => 0, 'description' => '',
            'paid' => null, 'confidence' => 'high', 'items' => [], 'is_new_entry' => true, 'party_id' => null]);
        $reply = $this->send('fayansta ne kaldı');
        $this->assertStringContainsString('🤝 ' . $a->title . ' (WAQ Seramikçi)', $reply);
        $this->assertStringContainsString("WAQ Fayans: 1000 m²'den 250 m² geldi, kalan 750 m²", $reply);
        $this->assertStringNotContainsString('Kaydedeyim', $reply);
        $this->assertSame(0, WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->count());

        $this->ai(['kind' => ExpenseExtractor::KIND_AGREEMENT_QUERY, 'query_item_ids' => [], 'party_id' => $usta->id, 'amount' => 0,
            'description' => '', 'paid' => null, 'confidence' => 'high', 'items' => [], 'is_new_entry' => true]);
        $reply = $this->send('ali ustayla ne durumdayız');
        $this->assertStringContainsString('WAQ Tesisat: %50 yapıldı (500.000,00), kalan 500.000,00 ₺', $reply);
        $this->assertStringContainsString('Bakiye: borcun 300.000,00 ₺', $reply);
    }

    public function test_paid_delivery_writes_purchase_and_payment(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak Peşin']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT BetonP', 'm³'), 'quantity' => 100, 'unit_price' => 4000]]);

        $this->ai($this->delivery(['paid' => true, 'delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 10, 'unit_price' => 4000]]]));
        $card = $this->send('kuşaktan 10 metreküp beton aldım 40 bin peşin ödedim');
        $this->assertStringContainsString('💸 Peşin ödendi — bakiye değişmez', $card);
        $this->send('evet');

        $this->assertTrue(\App\Models\Purchase::where('party_id', $party->id)->sole()->paid);
        $this->assertSame(0.0, (float) PartyStatement::build($party)['balance']);
        $this->assertEqualsWithDelta(90, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }
}
