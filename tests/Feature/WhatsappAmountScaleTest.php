<?php

namespace Tests\Feature;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\Understanding;
use App\Support\ModuleProfiles;
use App\Support\PartyStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ölçek sorusu ("50 TL mi, 50.000 TL mi?") — 23.10 deneme: "Hakan Öz kalan 80 binin 50 sini gönderdi" mesajında model 10/10 koşuda
 * 50.000 dedi, soruyu kodun ölçek kontrolü çıkardı (parça bütünün birimini almıyordu). AI taklit edilir.
 */
class WhatsappAmountScaleTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770041';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules.assistant_pilot' => true]);
    }

    /** @return array<string, array{0: string, 1: float, 2: bool}> mesaj, modelin tutarı, ölçek sorusu sorulmalı mı */
    public static function cases(): array
    {
        return [
            'kalan 80 binin 50 sini (Hakan)' => ['Hakan Öz kalan 80 binin 50 sini gönderdi', 50000, false],
            "80 binin 50'sini" => ["Hakan Öz 80 binin 50'sini gönderdi", 50000, false],
            '80 binin 50 binini' => ['Hakan Öz 80 binin 50 binini gönderdi', 50000, false],
            // Milyonda birim bütünden çıkmaz ("1 milyonun 300'ü" = 300 bin) → eskisi gibi sorulur.
            "1 milyonun 300'ünü → sorulur (eski davranış)" => ["Hakan Öz 1 milyonun 300'ünü gönderdi", 300000, true],
            "80 binin yüzde 50'si → 40.000" => ["Hakan Öz 80 binin yüzde 50'sini gönderdi", 40000, false],
            '80 bin borcun 50 lirası → 50' => ['Hakan Öz 80 bin borcun 50 lirasını ödedi', 50, false],
            '50 bin değil 5 bin → 5.000' => ['50 bin değil, 5 bin', 5000, false],
            // Karşı örnekler: model yanlış ölçek seçerse kontrol sorar.
            "bağlamsız 50'sini → 50.000 tahmini sorulur" => ["Hakan Öz 50'sini gönderdi", 50000, true],
            '80 bin borcun 50 lirası ama model 50.000 → sorulur' => ['Hakan Öz 80 bin borcun 50 lirasını ödedi', 50000, true],
            "yüzde 50'si ama model 50.000 → sorulur" => ["Hakan Öz 80 binin yüzde 50'sini gönderdi", 50000, true],
            '"80 binin" sonrası ayrı cümle → sorulur' => ['Hakan Öz 80 binin hepsini vermedi. 50 gönderdi', 50000, true],
            'eski vaka: maaşı 65 tl → 65.000 sorulur' => ['Erkan maaşı 65 tl', 65000, true],
            // Birimli sayı para değil (Erkan 26.10: "15 kili peynir" → "15 TL mi, 15.000 TL mi?" soruldu).
            '15 kili peynir (Erkan)' => ['Kelime Yılmaz 15 kili peynir vereceğim 5000 gönderdi kalan duruyor', 15000, false],
            '15 kilo' => ['15 kilo peynir verdim', 15000, false],
            '20 m² fayans' => ['20 m² fayans döşendi', 20000, false],
            '3 çuval' => ['3 çuval un aldım', 3000, false],
            '15 kilo ama tutar da 15 yazılı → sorulur' => ['15 kilo peynir 15 verdi', 15000, true],
        ];
    }

    #[DataProvider('cases')]
    public function test_scale_question(string $text, float $amount, bool $asks): void
    {
        $q = Understanding::amountScaleQuestion(['kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => $amount, 'party_name' => 'Hakan Öz'], $text);

        $asks ? $this->assertNotNull($q, $text) : $this->assertNull($q, $text . ' → ' . $q);
    }

    public function test_hakan_message_records_50000_without_question(): void
    {
        config(['modules' => ModuleProfiles::resolve('alacak_verecek', ['smart_assistant' => true, 'assistant_pilot' => true]), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 41']]);
        Http::fake();
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
        $hakan = Party::create(['name' => 'Zxq Hakan Öz']);
        PartyLedgerEntry::create(['party_id' => $hakan->id, 'type' => PartyLedgerEntry::TYPE_SALE, 'amount' => 80000, 'entry_date' => now()->subMonth()->toDateString(),
            'description' => 'Dükkan kira farkı']);
        $chat = Mockery::mock(ChatAssistant::class);
        $chat->shouldReceive('respond')->andReturn(['type' => 'entry', 'text' => '', 'data' => [
            'kind' => ExpenseExtractor::KIND_COLLECTION, 'amount' => 50000, 'party_id' => $hakan->id, 'party_name' => null, 'items' => [],
            'date' => now()->format('Y-m-d'), 'description' => 'Kalan borçtan', 'project_id' => null, 'project_name' => null, 'paid' => null,
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true, 'work_status_known' => true, 'party_certain' => true]]);
        $this->app->instance(ChatAssistant::class, $chat);
        $send = fn (string $b) => html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $b, 'NumMedia' => 0])
            ->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);

        $card = $send('Hakan Öz kalan 80 binin 50 sini gönderdi');
        $this->assertStringNotContainsString('TL mi', $card);
        $this->assertStringContainsString('Kaydedeyim mi?', $card);
        $send('evet');

        $this->assertEqualsWithDelta(50000, (float) PartyLedgerEntry::where('party_id', $hakan->id)->where('type', PartyLedgerEntry::TYPE_COLLECTION)->sole()->amount, 0.01);
        $this->assertEqualsWithDelta(30000, PartyStatement::build($hakan)['balance'], 0.01);
    }
}
