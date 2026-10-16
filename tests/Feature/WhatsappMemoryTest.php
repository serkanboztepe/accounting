<?php

namespace Tests\Feature;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ExpenseContext;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** İşletme hafızası (Faz 2): onaylı öğrenme, firmada ortak (tercih kişiye), eskisi geçmişte, unutma, bağlama girer. AI mock. */
class WhatsappMemoryTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905558880011';

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('alacak_verecek'), 'app.profile' => 'alacak_verecek',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 888 00 11'],
            'services.anthropic.chat_assistant' => false]);
        Http::fake();
        AssistantMemory::query()->update(['status' => 'deleted']); // yerel eski kayıtlar karışmasın (geri alınır)
        WhatsappPendingExpense::where('phone', self::PHONE)->update(['status' => 'superseded']);
    }

    private function d(array $o): array
    {
        return array_merge(['items' => [], 'amount' => 0, 'paid' => null, 'date' => now()->format('Y-m-d'), 'description' => '',
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

    private function remember(string $kind, string $subject, string $fact, ?int $party = null): array
    {
        return $this->d(['kind' => ExpenseExtractor::KIND_REMEMBER, 'memory_kind' => $kind, 'memory_subject' => $subject,
            'memory_fact' => $fact, 'party_id' => $party]);
    }

    public function test_remember_needs_confirmation_then_is_in_context_for_everyone(): void
    {
        $mehmet = Party::create(['name' => 'Hafıza Testi Mehmet']);
        $this->ai($this->remember('alias', 'kalfa', 'Kalfa = Hafıza Testi Mehmet', $mehmet->id));

        $this->assertStringContainsString('Hatırlayayım mı?', $this->send('bundan sonra kalfa deyince Mehmeti anla'));
        $this->assertSame(0, AssistantMemory::where('status', 'active')->count(), 'onaysız kaydedilmez');
        $this->assertStringContainsString('hatırlayacağım', $this->send('evet'));

        $this->assertStringContainsString('Kalfa = Hafıza Testi Mehmet', ExpenseContext::build('905559999999'), 'firmanın ortak hafızası');
    }

    public function test_preference_is_only_for_that_phone(): void
    {
        $this->ai($this->remember('preference', 'cevap uzunluğu', 'Kısa yaz'));
        $this->send('bana kısa yaz');
        $this->send('evet');

        $this->assertStringContainsString('Kısa yaz', ExpenseContext::build(self::PHONE));
        $this->assertStringNotContainsString('Kısa yaz', ExpenseContext::build('905559999999'));
    }

    public function test_preference_reaches_receipt_reading(): void
    {
        // Ferhat: "yakıt fişlerinde açıklamaya plakayı yaz" — fiş fotoğrafı ExpenseExtractor'dan geçer, tercih orada da görünmeli.
        config(['services.anthropic.api_key' => 'sk-test']);
        AssistantMemory::create(['kind' => 'preference', 'subject' => 'yakıt fişi', 'fact' => 'Yakıt fişinde açıklamaya plakayı yaz',
            'phone' => \App\Support\Phone::normalize(self::PHONE), 'status' => 'active']);

        try {
            (new ExpenseExtractor)->extract(null, ['media_type' => 'image/jpeg', 'data' => 'eA=='], null, null, self::PHONE);
        } catch (\RuntimeException) {
            // Boş sahte cevap → "çıktı döndürmedi"; burada yalnız gönderilen prompt önemli.
        }

        Http::assertSent(fn ($r) => str_contains(json_encode($r['system'], JSON_UNESCAPED_UNICODE), 'açıklamaya plakayı yaz'));
    }

    public function test_new_fact_replaces_old_keeping_history(): void
    {
        $this->ai($this->remember('agreement', 'Recep peynir fiyatı', 'Recep ile peynir kilosu 260'),
            $this->remember('agreement', 'Recep peynir fiyatı', 'Recep ile peynir kilosu 280'));
        $this->send('Recep le 260 dan anlaştık');
        $this->send('evet');

        $this->assertStringContainsString('eskisi: Recep ile peynir kilosu 260', $this->send('Recep le 280 den anlaştık'));
        $this->send('evet');

        $this->assertSame(['Recep ile peynir kilosu 280'], AssistantMemory::where('status', 'active')->pluck('fact')->all());
        $this->assertSame('replaced', AssistantMemory::where('fact', 'Recep ile peynir kilosu 260')->value('status'));
    }

    public function test_forget_and_list(): void
    {
        AssistantMemory::create(['kind' => 'alias', 'subject' => 'kalfa', 'fact' => 'Kalfa = Mehmet']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_MEMORY_LIST]),
            $this->d(['kind' => ExpenseExtractor::KIND_FORGET, 'memory_subject' => 'kalfa']),
            $this->d(['kind' => ExpenseExtractor::KIND_FORGET, 'memory_subject' => 'olmayan şey']));

        $this->assertStringContainsString('Kalfa = Mehmet', $this->send('neleri hatırlıyorsun'));
        $this->assertStringContainsString('unutayım mı', $this->send('kalfayı unut'));
        $this->send('evet');
        $this->assertSame('deleted', AssistantMemory::where('fact', 'Kalfa = Mehmet')->value('status'));
        $this->assertStringContainsString('hatırlamıyorum', $this->send('olmayan şeyi unut'));
    }

    public function test_suggestion_after_commit(): void
    {
        $recep = Party::create(['name' => 'Hafıza Testi Recep']);
        $this->ai($this->d(['kind' => ExpenseExtractor::KIND_SALE, 'party_id' => $recep->id, 'amount' => 2800, 'paid' => false,
            'description' => '10 kg peynir × 280', 'memory_suggestion' => ['kind' => 'agreement', 'subject' => 'Recep peynir fiyatı',
                'fact' => 'Recep ile peynir kilosu 280']]));

        $this->send('Recepe 10 kg peynir verdim 280 den anlaştık');
        $reply = $this->send('evet');

        $this->assertSame(1, PartyLedgerEntry::where('party_id', $recep->id)->count());
        $this->assertStringContainsString('Bir de şunu hatırlayayım mı?', $reply);
        $this->send('evet');
        $this->assertSame(1, AssistantMemory::where('status', 'active')->where('fact', 'Recep ile peynir kilosu 280')->count());
    }

    public function test_panel_page_lists_and_forgets(): void
    {
        config(['modules.smart_assistant' => true]);
        $this->actingAs(\App\Models\User::factory()->create());
        $m = AssistantMemory::create(['kind' => 'alias', 'subject' => 'kalfa', 'fact' => 'Kalfa = Panel Mehmet']);

        \Livewire\Livewire::test(\App\Filament\Resources\AssistantMemories\Pages\ManageAssistantMemories::class)
            ->assertOk()->assertSee('Kalfa = Panel Mehmet')
            ->callTableAction('forget', $m);

        $this->assertSame('deleted', $m->fresh()->status);
    }
}
