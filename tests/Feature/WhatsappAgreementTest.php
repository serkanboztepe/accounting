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
        \App\Http\Controllers\WhatsappWebhookController::$pageWait = 0; // sözleşme sayfası beklemesi (gerçekte 10 sn)
    }

    protected function tearDown(): void
    {
        \App\Http\Controllers\WhatsappWebhookController::$pageWait = 10;
        parent::tearDown();
    }

    private function agreement(array $o): array
    {
        // Sahte AI yönü söyler (eski vakalar alış); yön sorusu ayrı vakalarda (31.10).
        $o += ['agreement_direction' => 'purchase'];

        return array_merge(['kind' => ExpenseExtractor::KIND_AGREEMENT, 'items' => [], 'amount' => 0, 'paid' => null,
            'date' => '2026-04-01', 'description' => '', 'party_id' => null, 'party_name' => null, 'project_id' => null,
            'project_name' => null, 'confidence' => 'high', 'question' => null, 'is_new_entry' => true],
            AgreementDraft::sanitize($o), array_diff_key($o, array_flip(['agreement_items', 'agreement_payments', 'unreadable', 'agreement_direction'])));
    }

    private function project(string $name = 'WAA Proje'): int
    {
        return \App\Models\Project::create(['name' => $name, 'status' => 'active'])->id;
    }

    /** Proje menüsünden seç (maliyet kaydında proje her zaman sorulur — 22.10): verilen proje ya da 0 = Genel. */
    private function chooseProject(?int $projectId = null): string
    {
        $n = $projectId ? \App\Services\Whatsapp\ProjectChoice::projects()->search(fn ($p) => (int) $p->id === $projectId) + 1 : 0;

        return $this->send((string) $n);
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
        $this->assertStringContainsString('🤝 *Anlaşma* — WA Anlaşma Hüseyin Yılmaz (yeni kişi)', $card);
        $this->assertStringContainsString('WAA Vitra Bone 60x120 1.244,16 m² × 450,00 = 559.872,00', $card);
        $this->assertStringContainsString('WAA Klozet kapağı 14 adet — bedelsiz', $card);
        $this->assertStringContainsString('WAA Nakliye · götürü 12.000,00 ₺', $card);
        $this->assertStringContainsString('Anlaşılan: *627.872,00 ₺*', $card);
        $this->assertStringContainsString('nakit 542.000,00 · 3 senet 600.000,00 (vade 30.07.2026 · 30.10.2026 · 30.12.2026)', $card);
        $this->assertStringContainsString('Borç yazılmaz', $card);

        $done = $this->chooseProject();
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
            'party_name' => 'WA Anlaşma Alim Tok', 'project_id' => $this->project(),
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
        $this->assertStringContainsString('⚠️ 2 çekin vadesi geçmiş (15.07 → 20.08.2026). Bunlar ödendi mi?', $card);   // eski sözleşme
        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('2'));                 // ödenmedi, bekliyor
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Alim Tok')->sole();
        $a = Agreement::where('party_id', $party->id)->sole()->loadForCard();
        $this->assertTrue($a->items->every(fn ($i) => $i->isList()));
        $this->assertSame(0.0, $a->agreedTotal());
        $checks = Check::where('party_id', $party->id)->orderBy('due_date')->get();
        $this->assertSame(['2026-07-15', '2026-08-20'], $checks->map(fn ($c) => $c->due_date->toDateString())->all());
        $this->assertSame(['issued', 'issued'], $checks->pluck('status')->all());   // verdiğimiz çek → vade hatırlatması
    }

    /**
     * Seramikpark (22.10): AI belgedeki ödemeleri anlattı ama listeye koymadı — kart "Ödeme: yok" ile onaya geliyordu, 1.142.000 kayboldu.
     * Artık: has_payments + boş liste → sorulur; cevapta gelen ödemeler karta ve kayda girer.
     */
    public function test_payments_mentioned_but_missing_are_asked(): void
    {
        $items = [['description' => 'WAA3 Vitra', 'quantity' => 100, 'unit' => 'm²', 'unit_price' => 450]];
        $this->ai(
            $this->agreement(['party_name' => 'WA Anlaşma Seramik', 'project_id' => $this->project(), 'has_payments' => true, 'agreement_items' => $items]),
            $this->agreement(['party_name' => 'WA Anlaşma Seramik', 'is_new_entry' => false, 'agreement_items' => $items,
                'agreement_payments' => [['method' => 'cash', 'amount' => 20000], ['method' => 'promissory_note', 'amount' => 25000, 'due_date' => '2026-12-30']]]),
        );

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('Belgede ödeme var ama tutarlarını çıkaramadım', $ask);
        $this->assertStringContainsString('💸 Ödeme: yok', $ask);
        $this->assertStringContainsString('❓', $this->send('evet'));    // soru varken kaydetmez

        $card = $this->send('20 bin nakit 25 bin senet vadesi 30 aralık');
        $this->assertStringContainsString('💸 Ödeme: nakit 20.000,00 · senet 25.000,00 (vade 30.12.2026)', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $this->send('evet');
        $party = Party::where('name', 'WA Anlaşma Seramik')->sole();
        $this->assertEqualsWithDelta(45000, PartyStatement::build($party)['balance'], 0.01);
    }

    /**
     * Firma 7 22.10: ödeme sorusuna "Şubattan itibaren 11 adet çek" → AI listeyi boş bıraktı, aynı soru tekrarlandı.
     * Artık: check_plan → anlaşılan toplam 11'e bölünür; küsuratlıysa numaralı seçenek, numara → 11 çek (vadeler şubattan ay sonu).
     */
    public function test_text_check_plan_splits_agreement_total(): void
    {
        $this->travelTo('2026-10-22 09:45');
        $items = [['description' => 'WAA8 Core Mat', 'quantity' => 1502, 'unit' => 'm²', 'unit_price' => 565],
            ['description' => 'WAA8 Yapıştırıcı', 'quantity' => 448, 'unit_price' => 500],
            ['description' => 'WAA8 Derz', 'quantity' => 1, 'unit_price' => 115190]];          // toplam 1.187.820
        $this->ai(
            $this->agreement(['party_name' => 'WA Anlaşma Plan', 'project_id' => $this->project(), 'has_payments' => true, 'agreement_items' => $items]),
            $this->agreement(['party_name' => 'WA Anlaşma Plan', 'is_new_entry' => false, 'has_payments' => true, 'agreement_items' => $items,
                'check_plan' => ['count' => 11, 'first_month' => '2027-02']]),
        );

        $this->sendPhoto();
        $ask = $this->send('Şubattan itibaren 11 adet çek');
        $this->assertStringContainsString('11 çeke nasıl böleyim?', $ask);
        $this->assertStringContainsString('*1* 10 × 100.000,00 + son çek 187.820,00', $ask);
        $this->assertStringContainsString('Vadeler: 28.02.2027 → 31.12.2027 (ay sonu)', $ask);

        $card = $this->send('1');
        $this->assertStringContainsString('💸 Ödeme: 11 çek 1.187.820,00', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Plan')->sole();
        $checks = Check::where('party_id', $party->id)->orderBy('due_date')->get();
        $this->assertCount(11, $checks);
        $this->assertEqualsWithDelta(1187820, $checks->sum('amount'), 0.01);
        $this->assertSame('2027-02-28', $checks->first()->due_date->toDateString());
        $this->assertEqualsWithDelta(187820, $checks->last()->amount, 0.01);
    }

    /**
     * Sağlama (kullanıcı 22.10): belgenin altında 1.425.384 (KDV dahil), kalemler KDV hariç (565) okundu → fark tam %20 sorulur;
     * *1* → fiyatlar × 1,20 (565 → 678), teslimat bu fiyattan yazılır.
     */
    public function test_document_total_vat_gap_offers_vat_prices(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma KDV', 'project_id' => $this->project(), 'document_total' => 1425384,
            'agreement_items' => [['description' => 'WAA9 Core Mat', 'quantity' => 1502, 'unit' => 'm²', 'unit_price' => 565],
                ['description' => 'WAA9 Yapıştırıcı', 'quantity' => 448, 'unit_price' => 500],
                ['description' => 'WAA9 Derz', 'quantity' => 1, 'unit_price' => 115190]]]));

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('Kalemleri topladım 1.187.820,00, belgenin altında 1.425.384,00 yazıyor — fark tam %20 KDV.', $ask);
        $this->assertStringContainsString('*1* Fiyatlara KDV ekle (WAA9 Core Mat 565,00 → 678,00 …, toplam 1.425.384,00)', $ask);
        $this->assertStringContainsString('*2* Kalemler doğru, 1.187.820,00 kalsın', $ask);

        $card = $this->send('1');
        $this->assertStringContainsString('WAA9 Core Mat 1.502 m² × 678,00 = 1.018.356,00', $card);
        $this->assertStringContainsString('Anlaşılan: *1.425.384,00 ₺*', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $this->send('evet');
        $a = Agreement::whereHas('party', fn ($q) => $q->where('name', 'WA Anlaşma KDV'))->sole()->loadForCard();
        $this->assertEqualsWithDelta(678, $a->items[0]->unit_price, 0.001);
    }

    /** *2* "kalemler doğru" → fiyatlar değişmez, bir daha sorulmaz. */
    public function test_document_total_vat_gap_keep_items(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma KDV2', 'project_id' => $this->project(), 'document_total' => 120000,
            'agreement_items' => [['description' => 'WAA10 Kapı', 'quantity' => 10, 'unit_price' => 10000]]]));
        $this->sendPhoto();
        $card = $this->send('2');
        $this->assertStringContainsString('WAA10 Kapı 10 × 10.000,00 = 100.000,00', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
    }

    /** KDV oranına uymayan fark → "bir satırı yanlış okumuş olabilirim"; *evet* (kalemler doğru) → kart. Tutan toplam hiç sorulmaz. */
    public function test_document_total_other_gap_and_match(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Sağlama', 'project_id' => $this->project(), 'document_total' => 1187820,
            'agreement_items' => [['description' => 'WAA11 Core Mat', 'quantity' => 1502, 'unit' => 'm²', 'unit_price' => 678],
                ['description' => 'WAA11 Derz', 'quantity' => 1, 'unit_price' => 339190]]]));
        $ask = $this->sendPhoto();
        $this->assertStringContainsString('Kalemleri topladım 1.357.546,00, belgenin altında 1.187.820,00 yazıyor — bir satırı yanlış okumuş olabilirim', $ask);
        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('evet'));

        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Sağlama 2', 'project_id' => $this->project(), 'document_total' => 100000,
            'agreement_items' => [['description' => 'WAA12 Kapı', 'quantity' => 10, 'unit_price' => 10000]]]));
        $this->send('iptal');
        $this->assertStringNotContainsString('Kalemleri topladım', $this->sendPhoto());
    }

    /** Firma 7 22.10: "el yazısı kelime çek mi?" sorusuna "çek" → çek listesi geldi. Soru bekleyen taslakta tek "çek" cevaptır. */
    public function test_cek_answer_to_question_is_not_check_list_command(): void
    {
        config(['modules.checks' => true]);
        $items = [['description' => 'WAA13 Core', 'unit_price' => 565]];
        $this->ai(
            $this->agreement(['party_name' => 'WA Anlaşma Çek', 'project_id' => $this->project(), 'agreement_items' => $items,
                'unreadable' => ['"2. aydan 12. aya" sonundaki el yazısı kelime (çek mi?)']]),
            $this->agreement(['party_name' => 'WA Anlaşma Çek', 'project_id' => $this->project(), 'is_new_entry' => false, 'agreement_items' => $items]),
        );
        $this->sendPhoto();
        $card = $this->send('çek');
        $this->assertStringNotContainsString('vadesi gelen çekin yok', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
    }

    /**
     * Kamuran 22.10: ödeme planı 9 × (750.000 çek + 600.000 nakit), tarihler 2027-28. Vadeli çek verilmiş sayılır; ileri tarihli nakit
     * "ödendi" yazılmaz → tutarsız hatırlatma (gider oluşmaz). Geçmiş tarihli nakit eskisi gibi ödeme.
     */
    public function test_future_cash_becomes_reminders_checks_are_paid(): void
    {
        $this->travelTo('2026-10-22 12:00');
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Kamuran', 'project_id' => $this->project(), 'date' => '2026-09-26',
            'agreement_items' => [['description' => 'WAA14 Alçı boya', 'unit_price' => 310]],
            'agreement_payments' => [
                ['method' => 'check', 'amount' => 750000, 'due_date' => '2027-05-30'],
                ['method' => 'cash', 'amount' => 600000, 'paid_date' => '2027-05-30'],
                ['method' => 'check', 'amount' => 750000, 'due_date' => '2027-06-30'],
                ['method' => 'cash', 'amount' => 600000, 'paid_date' => '2027-06-30'],
                ['method' => 'cash', 'amount' => 50000, 'paid_date' => '2026-09-26'],          // imzada verilen nakit — ödeme
            ]]));

        $card = $this->sendPhoto();
        $this->assertStringContainsString('💸 Ödeme: 2 çek 1.500.000,00 (vade 30.05.2027 · 30.06.2027) · nakit 50.000,00', $card);
        $this->assertStringContainsString('📅 Plan: 2 nakit 1.200.000,00 (30.05.2027 · 30.06.2027) — hatırlatma kurulur', $card);
        $done = $this->send('evet');
        $this->assertStringContainsString('⏰ 2 ödeme hatırlatması kuruldu (ilki 30.05.2027)', $done);

        $party = Party::where('name', 'WA Anlaşma Kamuran')->sole();
        $this->assertEqualsWithDelta(1550000, PartyStatement::build($party)['balance'], 0.01);   // yalnız çekler + imzadaki nakit
        $this->assertSame(2, Check::where('party_id', $party->id)->count());
        $reminders = \App\Models\Reminder::where('party_id', $party->id)->orderBy('event_date')->get();
        $this->assertCount(2, $reminders);
        $this->assertSame('2027-05-30', $reminders[0]->event_date->toDateString());
        $this->assertNull($reminders[0]->amount);                                              // tutarsız: gider yazmaz
        $this->assertStringContainsString("WA Anlaşma Kamuran'e 600.000,00 ₺ nakit ödeme", $reminders[0]->text);
    }

    /** Okunamayan yer → "not": olduğu gibi anlaşma notuna; belgedeki şartlar (agreement_notes) da notta. */
    public function test_note_answer_writes_unreadable_into_agreement_notes(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Not', 'project_id' => $this->project(),
            'agreement_notes' => 'Dış cephe mantolamada 300 m tül söve dahil',
            'agreement_items' => [['description' => 'WAA15 Dış boya', 'unit_price' => 250]],
            'unreadable' => ["Dış boya yanındaki el yazısı '? bahçe duvarı'"]]));

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('olduğu gibi nota yazayım dersen *not* yaz', $ask);
        $this->assertStringContainsString('📝 Dış cephe mantolamada 300 m tül söve dahil', $ask);
        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('not'));
        $this->send('evet');

        $notes = Agreement::whereHas('party', fn ($q) => $q->where('name', 'WA Anlaşma Not'))->sole()->notes;
        $this->assertStringContainsString('300 m tül söve', $notes);
        $this->assertStringContainsString('bahçe duvarı', $notes);
    }

    /** Kartta "🏗 Proje: X (yeni)" → kayıtta proje açılır ve anlaşma ona bağlanır (22.10 İşbilir Elektrik: proje açılmadı, Genel kaldı). */
    public function test_new_project_name_is_created_on_save(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Elektrik', 'project_name' => 'WAA Cumhuriyet 50 ada 6 parsel',
            'agreement_items' => [['description' => 'WAA20 Elektrik işleri', 'amount' => 600000]]]));
        $this->assertStringContainsString('🏗 Proje: WAA Cumhuriyet 50 ada 6 parsel (yeni)', $this->sendPhoto());
        $this->send('evet');
        $a = Agreement::whereHas('party', fn ($q) => $q->where('name', 'WA Anlaşma Elektrik'))->sole();
        $this->assertSame('WAA Cumhuriyet 50 ada 6 parsel', $a->project?->name);
    }

    /** Belgedeki telefon/adres: kayıttan sonra "cari kartına yazayım mı?" — *evet* yazar, *iptal* yazmaz, başka mesaj soruyu kapatır. */
    public function test_party_info_offered_after_save(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Tel', 'project_id' => $this->project(),
            'party_phone' => '0543 960 92 17', 'party_address' => 'Ergenekon Mah. 136 Sok.',
            'agreement_items' => [['description' => 'WAA16 Sıva', 'unit_price' => 250]]]));
        $this->sendPhoto();
        $done = $this->send('evet');
        $this->assertStringContainsString("📇 Belgede şunlar var:\n• *WA Anlaşma Tel* telefonu 0543 960 92 17, adresi Ergenekon Mah. 136 Sok.\nKişi kartına yazayım mı? *evet* / *iptal*", $done);
        $this->assertStringNotContainsString('yarım kalan', $done);                            // soru yarım kayıt sanılmaz (22.10)
        $party = Party::where('name', 'WA Anlaşma Tel')->sole();
        $this->assertNull($party->phone);                                                      // sormadan yazmaz

        $this->assertStringContainsString('kişi kartına yazıldı', $this->send('evet'));
        $party->refresh();
        $this->assertSame('0543 960 92 17', $party->phone);
        $this->assertStringContainsString('Adres: Ergenekon Mah. 136 Sok.', $party->notes);
    }

    /**
     * Belgede sistemde olmayan iki şantiye (Kamuran 22.10: Cumhuriyet Mah. 6 daire, Ulalar Mah. 2 daire — "2 proje var açayım mı
     * diyebilirdi"): kayıttan sonra telefon/adresle aynı soruda; *evet* → projeler açılır, adres yazılır. Var olan proje sorulmaz.
     */
    public function test_new_projects_offered_with_party_info(): void
    {
        $existing = $this->project('WAA Var Olan Şantiye');
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Şantiye', 'project_id' => $existing,
            'party_address' => 'İzzet Paşa Mah. 844 Sk. No:2',
            'new_projects' => ['WAA Cumhuriyet Mah. 6 daire', 'WAA Ulalar Mah. 2 daire', 'WAA Var Olan Şantiye'],
            'agreement_items' => [['description' => 'WAA21 Alçı boya', 'unit_price' => 300]]]));

        $this->sendPhoto();
        $done = $this->send('evet');
        $this->assertStringContainsString("📇 Belgede şunlar var:\n• *WA Anlaşma Şantiye* adresi İzzet Paşa Mah. 844 Sk. No:2\n"
            . "• 2 şantiye: WAA Cumhuriyet Mah. 6 daire, WAA Ulalar Mah. 2 daire\nKişi kartına yazayım, projeleri açayım mı? *evet* / *iptal*", $done);
        $this->assertStringContainsString('projeler açıldı', $this->send('evet'));
        $this->assertSame(2, \App\Models\Project::whereIn('name', ['WAA Cumhuriyet Mah. 6 daire', 'WAA Ulalar Mah. 2 daire'])->count());
        $this->assertStringContainsString('Adres: İzzet Paşa Mah. 844 Sk. No:2', Party::where('name', 'WA Anlaşma Şantiye')->sole()->notes);
    }

    /** Birlikte gönderilen iki sayfa (Kamuran: 1 sn arayla): ilk sayfa anlaşma → sıradaki kardeş sayfa birlikte okunur, TEK kart. */
    public function test_pages_sent_together_make_one_card(): void
    {
        $queueKey = \App\Tenancy\Tenancy::key('wa-photo-queue:' . \App\Support\Phone::normalize(self::PHONE));
        \Illuminate\Support\Facades\Cache::put($queueKey, [['_queued_at' => microtime(true) + 1, 'From' => self::PHONE, 'Body' => '',
            'NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/MEA2', 'MediaContentType0' => 'image/jpeg', 'MessageSid' => 'MMPAGE2']], now()->addHour());
        $page1 = $this->agreement(['party_name' => 'WA Anlaşma İki Sayfa', 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA17 Alçı boya', 'unit_price' => 310]]]);
        $both = $this->agreement(['party_name' => 'WA Anlaşma İki Sayfa', 'project_id' => $this->project(), 'pages_related' => true,
            'agreement_items' => [['description' => 'WAA17 Alçı boya', 'unit_price' => 310]],
            'agreement_payments' => [['method' => 'check', 'amount' => 750000, 'due_date' => '2027-05-30']]]);
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->withArgs(fn ($t, $img) => is_array($img) && array_is_list($img))->once()->andReturn($both);
        $m->shouldReceive('extract')->andReturn($page1);
        $this->app->instance(ExpenseExtractor::class, $m);

        $card = $this->sendPhoto();
        $this->assertStringContainsString('📄 2 sayfa', $card);
        $this->assertStringContainsString('1 çek', str_replace('💸 Ödeme: çek', '1 çek', $card));
        $this->assertSame([], (array) \Illuminate\Support\Facades\Cache::get($queueKey, []));            // kardeş sıradan alındı
    }

    /** Birlikte gönderilen sayfalar ilgisizse (pages_related=false) eskisi gibi: ilk kart, ikinci sırada. */
    public function test_unrelated_pages_sent_together_stay_queued(): void
    {
        $queueKey = \App\Tenancy\Tenancy::key('wa-photo-queue:' . \App\Support\Phone::normalize(self::PHONE));
        \Illuminate\Support\Facades\Cache::put($queueKey, [['_queued_at' => microtime(true) + 1, 'From' => self::PHONE, 'Body' => '',
            'NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/MEA3', 'MediaContentType0' => 'image/jpeg', 'MessageSid' => 'MMPAGE3']], now()->addHour());
        $page1 = $this->agreement(['party_name' => 'WA Anlaşma Tek', 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA18 Boya', 'unit_price' => 310]]]);
        $m = Mockery::mock(ExpenseExtractor::class);
        $m->shouldReceive('extract')->withArgs(fn ($t, $img) => is_array($img) && array_is_list($img))->once()
            ->andReturn(['pages_related' => false] + $page1);
        $m->shouldReceive('extract')->andReturn($page1);
        $this->app->instance(ExpenseExtractor::class, $m);

        $card = $this->sendPhoto();
        $this->assertStringNotContainsString('📄 2 sayfa', $card);
        $this->assertCount(1, (array) \Illuminate\Support\Facades\Cache::get($queueKey, []));
        \Illuminate\Support\Facades\Cache::forget($queueKey);
    }

    /** Açık sözleşme kartına sonradan sayfa: devamıysa eklenir (📄 2 sayfa); emin değilse sorulur, *evet* ekler. */
    public function test_later_page_is_appended_or_asked(): void
    {
        $page1 = $this->agreement(['party_name' => 'WA Anlaşma Devam', 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA19 Alçı boya', 'unit_price' => 310]]]);
        $page2 = $this->agreement(['party_name' => 'WA Anlaşma Devam', 'is_new_entry' => false, 'continues_agreement' => true,
            'agreement_items' => [['description' => 'WAA19 Sıva', 'unit_price' => 250]],
            'agreement_payments' => [['method' => 'check', 'amount' => 750000, 'due_date' => '2027-05-30']],
            'agreement_notes' => '2027 yılı bağlantı']);
        $page3 = ['continues_agreement' => null] + $this->agreement(['party_name' => 'WA Anlaşma Devam', 'is_new_entry' => false,
            'agreement_items' => [['description' => 'WAA19 Şap', 'unit_price' => 275]]]);
        $this->ai($page1, $page2, $page3);

        $this->sendPhoto();
        $card = $this->sendPhoto();
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><Response><Message>🔄 *Kart güncellendi* — 2. sayfa eklendi, önceki kart geçersiz.', $card);
        $this->assertStringContainsString('📄 2 sayfa', $card);
        $this->assertStringContainsString('WAA19 Alçı boya', $card);
        $this->assertStringContainsString('WAA19 Sıva', $card);
        $this->assertStringContainsString('çek 750.000,00', $card);
        $this->assertStringContainsString('📝 2027 yılı bağlantı', $card);

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('📄 Bu sayfa *WA Anlaşma Devam* sözleşmesinin devamı mı?', $ask);
        $card = $this->send('evet');
        $this->assertStringContainsString('🔄 *Kart güncellendi* — 3. sayfa eklendi', $card);
        $this->assertStringContainsString('📄 3 sayfa', $card);
        $this->assertStringContainsString('WAA19 Şap', $card);
        $this->send('evet');
        $this->assertCount(3, Agreement::whereHas('party', fn ($q) => $q->where('name', 'WA Anlaşma Devam'))->sole()->items);
    }

    /**
     * Eski sözleşme (22.10 İşbilir/Kamuran 2): vadesi geçmiş çekler sorulur; *1* ödendi say → Çekler'de ödendi, gelecek vadeli bekliyor.
     */
    public function test_past_due_checks_asked_and_marked_paid(): void
    {
        $this->travelTo('2026-10-22 12:00');
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Eski Çek', 'project_id' => $this->project(), 'date' => '2026-05-01',
            'agreement_items' => [['description' => 'WAA22 Boya', 'unit_price' => 300]],
            'agreement_payments' => [['method' => 'check', 'amount' => 750000, 'due_date' => '2026-06-30'],
                ['method' => 'check', 'amount' => 750000, 'due_date' => '2026-09-30'],
                ['method' => 'check', 'amount' => 750000, 'due_date' => '2026-12-30']]]));

        $ask = $this->sendPhoto();
        $this->assertStringContainsString("⚠️ 2 çekin vadesi geçmiş (30.06 → 30.09.2026). Bunlar ödendi mi?\n*1* Ödendi say · *2* Ödenmedi, bekliyor", $ask);
        $this->assertStringContainsString('❓', $this->send('evet'));      // soru varken kaydetmez
        $card = $this->send('1');
        $this->assertStringContainsString('💸 Ödeme: 2 çek 1.500.000,00 ödendi · çek 750.000,00 (vade 30.12.2026)', $card);
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Eski Çek')->sole();
        $this->assertSame(['paid', 'paid', 'issued'], Check::where('party_id', $party->id)->orderBy('due_date')->pluck('status')->all());
        $this->assertEqualsWithDelta(2250000, PartyStatement::build($party)['balance'], 0.01);   // üç çek de verilmiş ödeme
    }

    /**
     * Aynı cariyle açık anlaşma (22.10 Kamuran 2. sözleşme): KOD sorar "*1* yerine · *2* ayrı"; *1* → eski kapanır; *2* → ikisi açık.
     * Yeni caride sorulmaz.
     */
    public function test_existing_open_agreement_replace_or_separate(): void
    {
        $party = Party::create(['name' => 'WA Anlaşma Kamuran 2']);
        $old = \App\Support\AgreementDesk::create($party, ['direction' => 'purchase', 'agreement_date' => '2026-09-26', 'title' => 'WAA Eski liste',
            'lines' => [['product_id' => $this->product('WAA23 Alçı boya'), 'unit_price' => 310]]]);
        $draft = $this->agreement(['party_id' => $party->id, 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA23 Alçı boya', 'unit_price' => 300]]]);
        $this->ai($draft, $draft);

        $ask = $this->sendPhoto();
        $this->assertStringContainsString("WA Anlaşma Kamuran 2 ile açık bir anlaşma var: *WAA Eski liste* (26.09.2026).\n*1* Eskisinin yerine", $ask);
        $this->assertStringNotContainsString('*geç*', $ask);
        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('1'));
        $done = $this->send('evet');
        $this->assertStringContainsString('🔒 Eski anlaşma kapandı: WAA Eski liste.', $done);
        $this->assertSame(Agreement::STATUS_CLOSED, $old->fresh()->status);

        $this->sendPhoto();                                               // yine aynı cari: artık açık olan yeni anlaşma
        $this->send('2');
        $this->send('evet');
        $this->assertSame(2, Agreement::where('party_id', $party->id)->where('status', Agreement::STATUS_OPEN)->count());
    }

    /**
     * 25.10 firma 7: Kamuran sözleşmesi tekrar gönderildi — açık anlaşmayla kalem, miktar, fiyat birebir aynı → "zaten kayıtlı"
     * sorusu; *1* (ya da "tamam kaydetme") → hiçbir şey yazılmaz. Fiyat farklıysa eski "yerine / ayrı" sorusu.
     */
    public function test_same_agreement_again_is_not_saved_twice(): void
    {
        $party = Party::create(['name' => 'WA Anlaşma Kamuran 3']);
        \App\Support\AgreementDesk::create($party, ['direction' => 'purchase', 'agreement_date' => '2026-09-26', 'title' => 'WAA AG6',
            'lines' => [['product_id' => $this->product('WAA25 Bodrum kapısı', 'adet'), 'quantity' => 3, 'unit_price' => 6500]]]);
        $same = $this->agreement(['party_id' => $party->id, 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA25 Bodrum Kapısı', 'quantity' => 3, 'unit' => 'adet', 'unit_price' => 6500]]]);
        $this->ai($same);

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('📋 Bu anlaşma zaten kayıtlı: *WAA AG6* (26.09.2026), kalemler ve fiyatlar aynı.', $ask);
        $this->assertStringContainsString('👍 Tamam, kaydetmedim', $this->send('tamam kaydetme'));
        $this->assertSame(1, Agreement::where('party_id', $party->id)->count());

        $other = $this->agreement(['party_id' => $party->id, 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA25 Bodrum Kapısı', 'quantity' => 3, 'unit' => 'adet', 'unit_price' => 7000]]]);
        $this->ai($other);
        $this->assertStringContainsString('*1* Eskisinin yerine', $this->sendPhoto(), 'fiyat farklı → eski soru');
    }

    /** 25.10: "ayrı anlaşma" yazısı anlaşılmıyordu (yalnız rakam) — yazıyla cevap. */
    public function test_replace_question_understands_words(): void
    {
        $party = Party::create(['name' => 'WA Anlaşma Yazıyla']);
        \App\Support\AgreementDesk::create($party, ['direction' => 'purchase', 'agreement_date' => '2026-09-26', 'title' => 'WAA Eski 26',
            'lines' => [['product_id' => $this->product('WAA26 Alçı'), 'unit_price' => 310]]]);
        $draft = $this->agreement(['party_id' => $party->id, 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA26 Alçı', 'unit_price' => 300]]]);
        $this->ai($draft, $draft);

        $this->sendPhoto();
        $this->assertStringContainsString('Kaydedeyim mi?', $this->send('ayrı anlaşma'));
        $this->send('evet');
        $this->assertSame(2, Agreement::where('party_id', $party->id)->where('status', Agreement::STATUS_OPEN)->count());
    }

    /**
     * Eski sözleşmeden kalan (22.10 Gül Mobilya): tam sözleşme kartına "3 bodrum kapısı 5 iç kapı alacaklıyız" → TEK hamle: yalnız kalan
     * kalemler, fiyat sözleşme listesinden (kapı ↔ PVC kapı 6.500), eski nakit/çek yok, belge toplamı/vadesi geçmiş çek sorulmaz,
     * "Önceden ödendi: 52.000"; kayıtta bakiye 52.000, çek yok.
     */
    public function test_old_contract_remaining_items_in_one_step(): void
    {
        $this->travelTo('2026-10-22 15:38');
        $list = [['description' => 'WAA24 Bute beyaz PVC kapı', 'unit' => 'adet', 'unit_price' => 6500],
            ['description' => 'WAA24 Beyaz akrilik mutfak dolabı', 'unit' => 'mt', 'unit_price' => 6500]];
        $this->ai(
            $this->agreement(['party_name' => 'WA Anlaşma Gül', 'project_id' => $this->project(), 'date' => '2026-01-23', 'document_total' => 1100000,
                'agreement_items' => $list, 'agreement_payments' => [['method' => 'cash', 'amount' => 50000],
                    ['method' => 'check', 'amount' => 350000, 'due_date' => '2026-08-20'], ['method' => 'check', 'amount' => 350000, 'due_date' => '2026-09-20'],
                    ['method' => 'check', 'amount' => 350000, 'due_date' => '2026-10-20']],
                'unreadable' => ['Alt kısımdaki el yazısı not ("3 adet ...", "5 adet ...")']]),
            $this->agreement(['party_name' => 'WA Anlaşma Gül', 'is_new_entry' => false, 'remaining_only' => true,
                'agreement_items' => [['description' => 'Bodrum kapısı', 'quantity' => 3, 'unit' => 'adet'],
                    ['description' => 'İç kapı', 'quantity' => 5, 'unit' => 'adet']]]),
        );

        $this->sendPhoto();
        $card = $this->send('3 Bodrum kapısı 5 iç kapı alacaklıyız');
        $this->assertStringContainsString('🤝 *Anlaşma* — WA Anlaşma Gül (yeni kişi) · *alış* · *eski sözleşmeden kalan*', $card);
        $this->assertStringContainsString('• Bodrum kapısı 3 adet × 6.500,00 = 19.500,00', $card);
        $this->assertStringContainsString('• İç kapı 5 adet × 6.500,00 = 32.500,00', $card);
        $this->assertStringContainsString('💸 Önceden ödendi: 52.000,00', $card);
        $this->assertStringNotContainsString('PVC kapı', $card);
        $this->assertStringNotContainsString('çek', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);                     // fiyat / sağlama / vade sorusu yok

        $done = $this->send('evet');
        $this->assertStringContainsString('52.000,00 ₺ önceden ödenmiş olarak WA Anlaşma Gül hesabına yazıldı', $done);
        $party = Party::where('name', 'WA Anlaşma Gül')->sole();
        $this->assertEqualsWithDelta(52000, PartyStatement::build($party)['balance'], 0.01);
        $this->assertSame(0, Check::where('party_id', $party->id)->count());
        $this->assertCount(2, Agreement::where('party_id', $party->id)->sole()->items);
    }

    /** 22.10 Gül Mobilya: "320 dolap teslim aldım" — mesajda proje yok ama AI önceki teslimatın projesini yazdı → yok sayılır, sorulur. */
    public function test_delivery_project_not_in_message_is_asked(): void
    {
        $this->project('WAA Proje Fatih');
        $fatih = $this->project('WAA Proje Atatürk Fatih Değil');
        $party = Party::create(['name' => 'WAT Gül 2']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Dolap', 'mt'), 'unit_price' => 6500]]);
        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 320]], 'project_id' => $fatih]));
        $card = $this->send('320 dolap teslim aldım');
        $this->assertStringContainsString('🏗 Hangi proje?', $card);

        $this->send('iptal');
        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 10]], 'project_id' => $fatih]));
        $card = $this->send('atatürk şantiyesine 10 dolap geldi');                     // adı geçiyor → sorulmaz
        $this->assertStringNotContainsString('🏗 Hangi proje?', $card);
        $this->assertStringContainsString('WAA Proje Atatürk Fatih Değil', $card);
    }

    /** Yarım kalan hatırlatmasında anlaşma "Kayıt — tutar eksik" değil: "Anlaşma" + kendi sorusu (22.10 Gül Mobilya). */
    public function test_open_agreement_draft_follow_up_label(): void
    {
        $d = $this->agreement(['party_name' => 'WA Anlaşma Hatır', 'agreement_items' => [['description' => 'WAA25 Kapı', 'unit_price' => 6500]],
            'document_total' => 1100000, 'agreement_payments' => [['method' => 'cash', 'amount' => 50000]]]);
        $this->assertStringStartsWith('Anlaşma — WA Anlaşma Hatır', \App\Services\Whatsapp\DraftGaps::label($d));
        $this->assertNotSame(\App\Services\Whatsapp\DraftGaps::AMOUNT, \App\Services\Whatsapp\DraftGaps::reason($d)['code']);
    }

    /** Aynı cariye yeni sözleşme kartı → eski açık kart kapanır (Gül Mobilya üç kez atıldı, eskiler "yarım kalan" oldu). */
    public function test_new_agreement_card_supersedes_old_one_for_same_party(): void
    {
        $d = $this->agreement(['party_name' => 'WA Anlaşma Tekrar', 'project_id' => $this->project(),
            'agreement_items' => [['description' => 'WAA26 Kapı', 'unit_price' => 6500]]]);
        $this->ai($d, $d);
        $this->sendPhoto();
        \Illuminate\Support\Facades\Cache::forget(\App\Tenancy\Tenancy::key('wa-photo-busy:' . \App\Support\Phone::normalize(self::PHONE)));
        WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->update(['extracted->from_document' => false]);
        $this->send('', ['NumMedia' => 1, 'MediaUrl0' => 'https://api.twilio.com/media/MEA9', 'MediaContentType0' => 'image/jpeg', 'MessageSid' => 'MMTEKRAR2']);
        $this->assertSame(1, WhatsappPendingExpense::where('phone', self::PHONE)->where('status', 'awaiting_confirmation')->count());
    }

    /** "geç": ödeme yok sayılır; kart "Ödeme: yok" ile onaya gelir. */
    public function test_missing_payments_can_be_skipped(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Seramik 2', 'project_id' => $this->project(), 'has_payments' => true,
            'agreement_items' => [['description' => 'WAA4 Vitra', 'unit_price' => 450]]]));
        $this->sendPhoto();
        $card = $this->send('geç');
        $this->assertStringContainsString('💸 Ödeme: yok', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
    }

    /** Alim Tok (22.10): çek yanındaki tek tarih "paid_date" geldi → vade sayılır (vade bugüne düşmez); tarihsiz çek sorulur. */
    public function test_check_single_date_is_due_date_and_undated_is_asked(): void
    {
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Tok 3', 'project_id' => $this->project(), 'date' => '2026-06-01',
            'agreement_items' => [['description' => 'WAA5 Tuğla', 'unit_price' => 8.5]],
            'agreement_payments' => [['method' => 'check', 'amount' => 200000, 'paid_date' => '2026-07-15'], ['method' => 'check', 'amount' => 400000]]]));

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('2 çek 600.000,00 (vade 15.07.2026)', $ask);
        $this->assertStringContainsString("400.000,00 ₺ çekin vadesi ne?", $ask);
        $card = $this->send('geç');                                      // bilinmiyor → anlaşma tarihi
        $this->assertStringContainsString('(vade 01.06.2026 · 15.07.2026)', $card);
        $this->send('2');                                                // vadesi geçmiş — bekliyor
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Tok 3')->sole();
        $this->assertSame(['2026-06-01', '2026-07-15'], Check::where('party_id', $party->id)->orderBy('due_date')->get()
            ->map(fn ($c) => $c->due_date->toDateString())->all());
    }

    /** Seramikpark (22.10): belgede "Hüseyin Yılmaz", AI emin değil ama Seramikpark'a bağladı, sormadı. Artık sorar; *evet* → kart. */
    public function test_uncertain_party_match_is_asked(): void
    {
        $party = Party::create(['name' => 'WA Seramikpark']);
        $this->ai($this->agreement(['party_id' => $party->id, 'party_said' => 'Hüseyin Yılmaz', 'party_certain' => false,
            'project_id' => $this->project(), 'agreement_items' => [['description' => 'WAA6 Vitra', 'unit_price' => 450]]]));

        $ask = $this->sendPhoto();
        $this->assertStringContainsString('Belgede *Hüseyin Yılmaz* yazıyor — listendeki *WA Seramikpark* mi?', $ask);
        $card = $this->send('evet');
        $this->assertStringNotContainsString('Hüseyin Yılmaz', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $this->send('evet');
        $this->assertSame(1, Agreement::where('party_id', $party->id)->count());
    }

    /** Proje (22.10: üç anlaşma da projesiz kaldı): birden çok projede menü, numara → o projeye kaydedilir. */
    public function test_agreement_asks_project_and_number_saves(): void
    {
        $this->project('WAA Proje A');
        $this->project('WAA Proje B');
        $this->ai($this->agreement(['party_name' => 'WA Anlaşma Mermer', 'agreement_items' => [['description' => 'WAA7 Denizlik', 'unit_price' => 1050]]]));

        $card = $this->sendPhoto();
        $this->assertStringContainsString('🏗 Hangi proje?', $card);
        $this->assertStringNotContainsString('Kaydedeyim mi?', $card);
        $n = \App\Services\Whatsapp\ProjectChoice::projects()->search(fn ($p) => $p->name === 'WAA Proje B') + 1;
        $this->assertStringContainsString('✅ Anlaşma kaydedildi', $this->send((string) $n));

        $a = Agreement::whereHas('party', fn ($q) => $q->where('name', 'WA Anlaşma Mermer'))->sole();
        $this->assertSame('WAA Proje B', $a->project?->name);
    }

    /** Projesiz anlaşmadan gelen mal: proje sorulur; seçilen projeye alış yazılır. Projeli anlaşmada sorulmaz (test_delivery_single_match). */
    public function test_delivery_from_projectless_agreement_asks_project(): void
    {
        $this->project('WAA Proje C');
        $target = $this->project('WAA Proje D');
        $party = Party::create(['name' => 'WAT Tok 5']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Tuğla5', 'adet'), 'unit_price' => 8.5]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 200]]]));
        $card = $this->send('toktan 200 tuğla geldi');
        $this->assertStringContainsString('🏗 Hangi proje?', $card);
        $n = \App\Services\Whatsapp\ProjectChoice::projects()->search(fn ($p) => $p->id === $target) + 1;
        $this->assertStringContainsString('✅ Kaydedildi: 1.700,00 ₺ alış', $this->send((string) $n));
        $this->assertSame($target, (int) \App\Models\Purchase::where('party_id', $party->id)->sole()->project_id);
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
        $this->chooseProject();

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
        $this->assertStringContainsString('WA Anlaşma Ahmet (yeni kişi) · *satış*', $card);
        $this->assertStringContainsString('💸 Alınan: EFT 500.000,00', $card);
        $this->send('evet');

        $party = Party::where('name', 'WA Anlaşma Ahmet')->sole();
        $this->assertSame(Agreement::DIRECTION_SALE, Agreement::where('party_id', $party->id)->sole()->direction);
        $this->assertSame(PartyLedgerEntry::TYPE_COLLECTION, PartyLedgerEntry::where('party_id', $party->id)->sole()->type);
    }

    /**
     * Anlaşma yönü (31.10, kullanıcının cümlesi "10.000 tuğla 1 milyondan Ali Boztepe'ye anlaştık"): alış + satış yapan firmada yön
     * belli değilse sorulur, "evet" kaydetmez; "sattım" → satış kartı → kayıt. Düzeltme turunda yön yeniden sorulmaz.
     */
    public function test_both_directions_unclear_is_asked_then_sattim_saves_sale(): void
    {
        config(['app.agreement_direction' => 'both']);
        $tugla = fn (array $o = []) => $this->agreement($o + ['agreement_direction' => null, 'party_name' => 'WAD Ali Boztepe',
            'agreement_items' => [['description' => 'WAD Tuğla', 'quantity' => 10000, 'unit' => 'adet', 'unit_price' => 100]]]);
        $this->ai($tugla());

        $card = $this->send("10.000 tuğla 1 milyondan Ali Boztepe'ye anlaştık");
        $this->assertStringContainsString('Satış anlaşması mı yaptın, alış mı?', $card);
        $this->assertStringContainsString('Anlaşılan: *1.000.000,00 ₺*', $card);
        $this->assertStringNotContainsString('*alış*', $card, 'yön bilinmeden kartta alış yazmaz');
        $this->assertStringNotContainsString('Kaydedeyim mi?', $card);

        $this->assertStringContainsString('Satış anlaşması mı yaptın, alış mı?', $this->send('evet'), 'evet kaydetmez, soru tekrar');
        $this->assertSame(0, Agreement::whereHas('party', fn ($q) => $q->where('name', 'WAD Ali Boztepe'))->count());

        $card = $this->send('sattım');
        $this->assertStringContainsString('WAD Ali Boztepe (yeni kişi) · *satış*', $card);
        $this->assertStringContainsString('teslim ettikçe anlaşmadan düşülür', $card);

        // Düzeltme turu: AI yönü tekrarlamaz (null) → yeniden sorulmaz, satış kalır.
        $this->ai($tugla(['is_new_entry' => false, 'agreement_items' => [['description' => 'WAD Tuğla', 'quantity' => 10000, 'unit' => 'adet', 'unit_price' => 105]]]));
        $card = $this->send('adedi 105 olsun');
        $this->assertStringNotContainsString('Satış anlaşması mı', $card);
        $this->assertStringContainsString('· *satış*', $card);

        $this->send('evet');
        $a = Agreement::whereHas('party', fn ($q) => $q->where('name', 'WAD Ali Boztepe'))->sole();
        $this->assertSame(Agreement::DIRECTION_SALE, $a->direction);
    }

    public function test_both_directions_aldim_answer_and_clear_sentence_not_asked(): void
    {
        config(['app.agreement_direction' => 'both']);
        $this->ai($this->agreement(['agreement_direction' => null, 'party_name' => 'WAD Kuşak',
            'agreement_items' => [['description' => 'WAD Kum', 'quantity' => 100, 'unit' => 'm3', 'unit_price' => 500]]]));
        $this->send('Kuşakla 100 m3 kum 500den anlaştık');
        $this->assertStringContainsString('WAD Kuşak (yeni kişi) · *alış*', $this->send('aldım'));

        // Cümle yönü söylüyor (AI sale dedi) → soru yok.
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $this->ai($this->agreement(['agreement_direction' => 'sale', 'party_name' => 'WAD Mehmet',
            'agreement_items' => [['description' => 'WAD Tuğla', 'quantity' => 2000, 'unit' => 'adet', 'unit_price' => 105]]]));
        $card = $this->send("Mehmet'e 2000 tuğla sattım adedi 105, anlaştık");
        $this->assertStringNotContainsString('Satış anlaşması mı', $card);
        $this->assertStringContainsString('· *satış*', $card);
    }

    /** Yalnız alış yapan firma (müteahhit): AI "sale" dese de alış; soru yok. Satış firması: yön söylenmese satış, "aldım" derse alış. */
    public function test_single_direction_firm_never_asks_and_fixes_direction(): void
    {
        config(['app.agreement_direction' => 'purchase']);
        $this->ai($this->agreement(['agreement_direction' => 'sale', 'party_name' => 'WAD Usta',
            'agreement_items' => [['description' => 'WAD Sıva', 'amount' => 400000]]]));
        $card = $this->send("Ali Usta'ya sıvayı 400 bine anlaştık");
        $this->assertStringNotContainsString('Satış anlaşması mı', $card);
        $this->assertStringContainsString('WAD Usta (yeni kişi) · *alış*', $card);
        $this->chooseProject();
        $this->assertSame(Agreement::DIRECTION_PURCHASE, Agreement::whereHas('party', fn ($q) => $q->where('name', 'WAD Usta'))->sole()->direction);

        config(['app.agreement_direction' => 'sale']);
        $this->ai($this->agreement(['agreement_direction' => null, 'party_name' => 'WAD Ali Boztepe',
            'agreement_items' => [['description' => 'WAD Tuğla', 'quantity' => 10000, 'unit' => 'adet', 'unit_price' => 100]]]));
        $card = $this->send("10.000 tuğla 1 milyondan Ali Boztepe'ye anlaştık");
        $this->assertStringNotContainsString('Satış anlaşması mı', $card);
        $this->assertStringContainsString('· *satış*', $card);
        $this->send('evet');
        $this->assertSame(Agreement::DIRECTION_SALE, Agreement::whereHas('party', fn ($q) => $q->where('name', 'WAD Ali Boztepe'))->sole()->direction);

        // "Satış (alım derse alım)" (31.10 Alim Tok): açıkça alım söylenirse alış — soru yine yok.
        $this->ai($this->agreement(['agreement_direction' => 'purchase', 'party_name' => 'WAD Akdağ Fabrika',
            'agreement_items' => [['description' => 'WAD Tuğla', 'quantity' => 50000, 'unit' => 'adet', 'unit_price' => 6]]]));
        $card = $this->send('Akdağ fabrikadan 50 bin tuğla aldım tanesi 6, anlaştık');
        $this->assertStringNotContainsString('Satış anlaşması mı', $card);
        $this->assertStringContainsString('WAD Akdağ Fabrika (yeni kişi) · *alış*', $card);
        $this->chooseProject();
        $this->assertSame(Agreement::DIRECTION_PURCHASE, Agreement::whereHas('party', fn ($q) => $q->where('name', 'WAD Akdağ Fabrika'))->sole()->direction);
    }

    /** AI şeması ve yönergesi firma ayarına uyar: tek yönlüde yön alanı yok, iki yönlüde "TAHMİN ETME" kuralı. */
    public function test_extractor_direction_schema_follows_setting(): void
    {
        $props = fn () => collect(json_decode(json_encode(app(ExpenseExtractor::class)->entrySchema()), true))->dot()->keys()
            ->contains(fn ($k) => str_contains($k, 'agreement_direction'));

        config(['app.agreement_direction' => 'purchase']);
        $this->assertFalse($props());
        $this->assertStringContainsString('YALNIZ ALIŞ anlaşması', ExpenseExtractor::kindGuide());

        config(['app.agreement_direction' => 'sale']);
        $this->assertTrue($props(), 'satışta alım da yazılabilir → yön alanı var');
        $this->assertStringContainsString('GENELDE SATIŞ anlaşması', ExpenseExtractor::kindGuide());

        config(['app.agreement_direction' => 'both']);
        $this->assertTrue($props());
        $this->assertStringContainsString("TEK BAŞINA yön söylemez", ExpenseExtractor::kindGuide());
    }

    /** Hub "Özellikler": anlaşma yönü saklanır, varsayılan Alış + satış; panel formu tek yönlüde o yönle açılır. */
    public function test_hub_setting_round_trip_and_panel_default(): void
    {
        $s = \App\Models\HubFirm::settingsFromFeaturesForm(['feat_agreements' => true, 'agreement_direction' => 'sale']);
        $this->assertSame('sale', $s['agreement_direction']);
        $firm = new \App\Models\HubFirm(['settings' => $s]);
        $this->assertSame('sale', $firm->settingsWithDefaults()['agreement_direction']);
        $this->assertSame('sale', $firm->featuresFormData()['agreement_direction']);
        $this->assertSame('both', (new \App\Models\HubFirm(['settings' => []]))->settingsWithDefaults()['agreement_direction']);
        $this->assertSame('both', \App\Models\HubFirm::settingsFromFeaturesForm(['agreement_direction' => 'saçma'])['agreement_direction']);

        config(['app.agreement_direction' => 'sale']);
        $this->assertSame(Agreement::DIRECTION_SALE, \App\Support\Forms\AgreementForm::lastDirection(null));
        config(['app.agreement_direction' => null]);
        $this->assertSame('both', \App\Support\Features::agreementDirection());
    }

    /**
     * Toplam söylenen anlaşma (31.10 Yeni Yapı: "Ali boztepeye 3000 tuğla 1 milyondan anlaştım" → 3000 × 333,33 = 999.990 yazıldı):
     * anlaşılan tam 1.000.000; 400 teslim = 133.333,33; kalan 2600'ün teslimi kuruşu kapatır → satışlar toplamı tam 1.000.000.
     */
    public function test_total_said_agreement_keeps_total_and_deliveries_add_up(): void
    {
        config(['app.agreement_direction' => 'sale']);
        $this->ai($this->agreement(['agreement_direction' => null, 'party_name' => 'WAK Ali Boztepe',
            'agreement_items' => [['description' => 'WAK Tuğla', 'quantity' => 3000, 'unit' => 'adet', 'unit_price' => 333.33, 'amount' => 1000000]]]));
        $card = $this->send('Ali boztepeye 3000 tuğla 1 milyondan anlaştım');
        $this->assertStringContainsString('WAK Tuğla 3.000 adet = 1.000.000,00 (adet fiyatı ≈333,33)', $card);
        $this->assertStringContainsString('Anlaşılan: *1.000.000,00 ₺*', $card);
        $this->assertStringNotContainsString('999.990', $card);
        $this->send('evet');

        $party = Party::where('name', 'WAK Ali Boztepe')->sole();
        $a = Agreement::where('party_id', $party->id)->sole()->loadForCard();
        $item = $a->items[0];
        $this->assertEqualsWithDelta(1000000, $a->agreedTotal(), 0.001);
        $this->assertEqualsWithDelta(333.33, (float) $item->unit_price, 0.001);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $item->id, 'quantity' => 400]]]));
        $card = $this->send('Ali boztepeye 400 tuğla gönderdim');
        $this->assertStringContainsString('133.333,33', $card);
        $this->send('evet');
        $this->assertEqualsWithDelta(866666.67, $a->fresh()->loadForCard()->remainingTotal(), 0.001);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $item->id, 'quantity' => 2600]]]));
        $this->assertStringContainsString('866.666,67', $this->send('kalan 2600 tuğlayı da gönderdim'));
        $this->send('evet');

        $this->assertEqualsWithDelta(1000000, (float) \App\Models\Sale::where('party_id', $party->id)->sum('total_amount'), 0.001);
        $this->assertEqualsWithDelta(1000000, PartyStatement::build($party)['balance'], 0.001);
        $fresh = $a->fresh()->loadForCard();
        $this->assertEqualsWithDelta(0, $fresh->remainingTotal(), 0.001);
        $this->assertEqualsWithDelta(1000000, $fresh->deliveredTotal(), 0.001);
    }

    /** Fiyatla söylenen anlaşma eskisi gibi (miktar × fiyat); panel yolu: miktar + yalnız tutar → tutar korunur, Miktar düğmesi orantılı. */
    public function test_price_said_unchanged_and_panel_total_and_quantity_change(): void
    {
        $party = Party::create(['name' => 'WAK Kuşak']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAK Beton', 'm³'), 'quantity' => 500, 'unit_price' => 3800]]);
        $this->assertEqualsWithDelta(1900000, $a->agreedTotal(), 0.001);
        // Fiyat yazılmış ama tutar başka (yuvarlama değil) → fiyat esas, eski kural.
        $lines = \App\Support\AgreementDesk::normalizeLines([['product_id' => $this->product('WAK Kum', 'm³'), 'quantity' => 10, 'unit_price' => 500, 'amount' => 9000]]);
        $this->assertEqualsWithDelta(5000, $lines[0]['amount'], 0.001);

        $b = $this->deliveryAgreement($party, [['product_id' => $this->product('WAK Tuğla', 'adet'), 'quantity' => 3000, 'amount' => 1000000]]);
        $item = $b->items[0];
        $this->assertEqualsWithDelta(1000000, $b->agreedTotal(), 0.001);
        $this->assertEqualsWithDelta(333.33, (float) $item->unit_price, 0.001);

        \App\Support\AgreementDesk::changeQuantity($item, 4000);
        $this->assertEqualsWithDelta(1333333.33, $item->fresh()->agreedAmount(), 0.001);
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

        $done = $this->chooseProject($project->id);
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
        $this->assertStringContainsString('*WAT Kuşak 2* — hangi iş / mal?', $ask);
        $this->assertStringContainsString('*2* WAT Beton2 · ' . $a2->title, $ask);   // aynı ad iki kez → yanında anlaşma (projesiz)
        $this->assertStringNotContainsString('kalan', $ask);
        $this->assertStringContainsString('*0* Anlaşma dışı', $ask);

        $card = $this->send('2');
        $this->assertStringContainsString('WAT Beton2 20 m³ × 4.100,00 = 82.000,00', $card);
        $this->chooseProject();
        $this->assertEqualsWithDelta(30, $a2->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
        $this->assertEqualsWithDelta(100, $a1->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }

    /** 28.10 firma 7 Kamuran: "500 binlik iş yaptı" → adet sorulmaz; miktar = tutar ÷ fiyat (küsurat kalır), tutar aynen. */
    public function test_delivery_amount_only_computes_quantity(): void
    {
        $party = Party::create(['name' => 'WAT Kamuran']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Alçı boya', 'm²'), 'quantity' => 5000, 'unit_price' => 300]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'amount' => 500000, 'description' => 'alçı boya']]]));
        $card = $this->send('kamuran 500 binlik iş yaptı');
        $this->assertStringNotContainsString('Kaç m²', $card);
        $this->assertStringContainsString('WAT Alçı boya 1.666,67 m² × 300,00 = 500.000,00', $card);

        $this->chooseProject();
        $this->assertEqualsWithDelta(500000, (float) \App\Models\Purchase::where('party_id', $party->id)->sole()->total_amount, 0.001);
        $this->assertEqualsWithDelta(5000 - 1666.67, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }

    public function test_delivery_quantity_question_answered_with_money(): void
    {
        $party = Party::create(['name' => 'WAT Kamuran 2']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Alçı boya2', 'm²'), 'quantity' => 5000, 'unit_price' => 300]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id]]]));
        $this->assertStringContainsString('Kaç m² WAT Alçı boya2', $this->send('kamuranın alçı boyası yapıldı'));
        $this->assertStringContainsString('1.666,67 m² × 300,00 = 500.000,00', $this->send('500.000 tl'));
    }

    public function test_delivery_menu_lists_item_names_only(): void
    {
        $party = Party::create(['name' => 'WAT Kamuran 3']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Alçı', 'm²'), 'quantity' => 100, 'unit_price' => 200],
            ['product_id' => $this->product('WAT Boya', 'm²'), 'quantity' => 100, 'unit_price' => 150]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => null, 'quantity' => 20, 'description' => 'iş']],
            'delivery_candidates' => [$a->items[0]->id, $a->items[1]->id]]));
        $ask = $this->send('kamuran 20 metre iş yaptı');
        $this->assertStringContainsString("*WAT Kamuran 3* — hangi iş / mal?\n*1* WAT Alçı\n*2* WAT Boya\n*0* Anlaşma dışı", $ask);
    }

    /** "Alçı boya yapıldı" (kişi adı yok) — AI'a son 30 dk'da konuşulan kişi + proje verilir. */
    public function test_context_has_recently_discussed_party_and_project(): void
    {
        $party = Party::create(['name' => 'WAT Kamuran 4']);
        $project = $this->project('WAT Yeşilyurt 4');
        WhatsappPendingExpense::create(['phone' => self::PHONE, 'status' => 'confirmed', 'summary' => 'x',
            'extracted' => ['kind' => 'payment', 'party_id' => $party->id, 'project_id' => $project]]);

        $ctx = \App\Services\Whatsapp\ExpenseContext::build(self::PHONE);
        $this->assertStringContainsString('SON KONUŞULAN (son 30 dk): WAT Kamuran 4 (cari ' . $party->id . ') · WAT Yeşilyurt 4', $ctx);

        WhatsappPendingExpense::where('phone', self::PHONE)->update(['updated_at' => now()->subMinutes(45)]);
        $this->assertStringNotContainsString('SON KONUŞULAN', \App\Services\Whatsapp\ExpenseContext::build(self::PHONE));
    }

    public function test_delivery_price_difference_offers_three_choices(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak 3']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Beton3', 'm³'), 'quantity' => 100, 'unit_price' => 3800]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 30, 'unit_price' => 4000]]]));
        $ask = $this->send('kuşaktan 30 metreküp beton geldi 4000 den');
        $this->assertStringContainsString("Anlaşmadaki fiyat 3.800,00 ₺, sen 4.000,00 yazdın.\n*1* Yalnız bu teslimat 4.000,00\n*2* Bundan sonra hepsi 4.000,00 (zam)", $ask);

        $this->assertStringContainsString('= 120.000,00', $this->send('2'));
        $this->chooseProject();
        $items = $a->fresh()->loadForCard()->items;
        $this->assertCount(2, $items);                                  // zam: eski kalem kapandı, kalan yeni fiyattan
        $this->assertEqualsWithDelta(4000, $items[1]->unit_price, 0.01);
        $this->assertEqualsWithDelta(70, $items[1]->quantity, 0.001);
    }

    public function test_delivery_missing_quantity_then_number(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak 4']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Beton4', 'm³'), 'quantity' => 100, 'unit_price' => 3800]], $this->project());

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id]]]));
        $this->assertStringContainsString('Kaç m³ WAT Beton4 geldi?', $this->send('kuşaktan beton geldi'));
        $card = $this->send('44');
        $this->assertStringContainsString('WAT Beton4 44 m³ × 3.800,00 = 167.200,00', $card);
        $this->assertStringContainsString('🏗 Hangi proje?', $card);   // maliyet: proje her zaman sorulur (anlaşmanın projesi olsa da)
        $this->assertStringContainsString('WAA Proje (anlaşmadaki)', $card);
        $this->assertStringContainsString('Hangi proje? Numarasını yaz (0 = Genel)', $this->send('evet'));   // evet kaydetmez
    }

    public function test_delivery_lump_sum_half_with_advance(): void
    {
        $party = Party::create(['name' => 'WAT Ali Usta']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT Elektrik tesisatı'), 'amount' => 1000000]]);
        \App\Support\AgreementDesk::recordPayments($a, [['method' => 'cash', 'amount' => 200000]]);

        $this->ai($this->delivery(['delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'amount' => 500000]]]));
        $card = $this->send('ali usta tesisatın yarısını bitirdi');
        $this->assertStringContainsString('WAT Elektrik tesisatı · 500.000,00 ₺', $card);   // "%50" yazılmaz (25.10: yanıltıcı)
        $this->assertStringNotContainsString('%50', $card);
        $this->assertStringContainsString('Sonra: borcun 300.000,00 ₺', $card);   // peşinat düşüldü
        $this->chooseProject();
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
        $this->assertStringContainsString('Gelen 500.000 · Ödenen 200.000 → 300.000 borcun', $reply, '27.10: anlaşmaya ödenen');
    }

    /** "Kamuran'dan A Blok için neler aldık" (27.10): party_id + project_id → o projeye gelen kalemler + anlaşma dışı alış. */
    public function test_query_by_party_and_project(): void
    {
        $party = Party::create(['name' => 'WAQ Kamuran']);
        $aBlok = \App\Models\Project::create(['name' => 'WAQ A Blok', 'status' => 'active']);
        $bBlok = \App\Models\Project::create(['name' => 'WAQ B Blok', 'status' => 'active']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAQ Mantolama', 'm²'), 'quantity' => 3000, 'unit_price' => 450],
            ['product_id' => $this->product('WAQ Sıva', 'm²'), 'quantity' => 2000, 'unit_price' => 400]]);
        $deliver = fn (int $item, float $qty, int $project) => \App\Support\AgreementDesk::deliver($party, Agreement::DIRECTION_PURCHASE,
            [['agreement_item_id' => $a->items[$item]->id, 'quantity' => $qty]], now()->toDateString(), $project);
        $deliver(0, 1200, $aBlok->id);
        $deliver(1, 800, $aBlok->id);
        $deliver(0, 500, $bBlok->id);

        $ask = fn (?int $projectId) => $this->ai(['kind' => ExpenseExtractor::KIND_AGREEMENT_QUERY, 'query_item_ids' => [], 'party_id' => $party->id,
            'project_id' => $projectId, 'amount' => 0, 'description' => '', 'paid' => null, 'confidence' => 'high', 'items' => [], 'is_new_entry' => true]);
        $ask($aBlok->id);
        $reply = $this->send("Kamuran'dan A Blok için neler aldık");
        foreach (['📍 WAQ Kamuran · WAQ A Blok', 'WAQ Mantolama: 1200 m² · 540.000,00 ₺', 'WAQ Sıva: 800 m² · 320.000,00 ₺', 'Toplam 860.000,00 ₺'] as $text) {
            $this->assertStringContainsString($text, $reply);
        }
        $this->assertStringNotContainsString('225.000', $reply, 'B Blok karışmaz');

        $empty = \App\Models\Project::create(['name' => 'WAQ C Blok', 'status' => 'active']);
        $ask($empty->id);
        $this->assertStringContainsString('WAQ Kamuran → WAQ C Blok: anlaşmalardan mal gelmemiş.', $this->send("Kamuran C Bloğa ne getirdi"));
    }

    public function test_paid_delivery_writes_purchase_and_payment(): void
    {
        $party = Party::create(['name' => 'WAT Kuşak Peşin']);
        $a = $this->deliveryAgreement($party, [['product_id' => $this->product('WAT BetonP', 'm³'), 'quantity' => 100, 'unit_price' => 4000]]);

        $this->ai($this->delivery(['paid' => true, 'delivery_lines' => [['agreement_item_id' => $a->items[0]->id, 'quantity' => 10, 'unit_price' => 4000]]]));
        $card = $this->send('kuşaktan 10 metreküp beton aldım 40 bin peşin ödedim');
        $this->assertStringContainsString('💸 Peşin ödendi — bakiye değişmez', $card);
        $this->chooseProject();

        $this->assertTrue(\App\Models\Purchase::where('party_id', $party->id)->sole()->paid);
        $this->assertSame(0.0, (float) PartyStatement::build($party)['balance']);
        $this->assertEqualsWithDelta(90, $a->fresh()->loadForCard()->items[0]->remainingQuantity(), 0.001);
    }
}
