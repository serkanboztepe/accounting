<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\RecordReview;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ChatAssistant;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Kayıt kontrolü cevabı o kayda bağlanır (kullanıcı 19.10: "hayır o öyle değil böyle" dediğinde o mesaj hakkında konuşmalı):
 * asistan soruyu ve şüpheli kaydı görür; "doğru" → Doğruymuş, düzeltme kartı onaylanınca → Düzeltildi (notla). Kayda müşteri onayı
 * olmadan dokunulmaz.
 */
class RecordReviewAnswerTest extends TestCase
{
    use DatabaseTransactions;

    private const PHONE = 'whatsapp:+905557770023';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'modules' => ModuleProfiles::resolve('trade', ['smart_assistant' => true, 'expenses' => true]), 'app.profile' => 'trade',
            'services.twilio.verify_signature' => false, 'services.twilio.allowed_phones' => ['0555 777 00 23'],
        ]);
        Http::fake();
        WhatsappMessage::create(['phone' => '905557770023', 'direction' => 'in', 'body' => 'önceki']);
    }

    private function asked(): RecordReview
    {
        return RecordReview::create(['firm_name' => 'Test', 'phone' => '905557770023', 'problem' => 'Testtoptan 18.261 gider yazıldı',
            'question' => 'Testtoptan 18.261 doğru mu?', 'status' => 'asked', 'asked_at' => now()->subHour()]);
    }

    private function ai(array $result): void
    {
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->andReturn($result);
        $this->app->instance(ChatAssistant::class, $mock);
    }

    private function send(string $body): string
    {
        return html_entity_decode($this->post('/whatsapp/webhook', ['From' => self::PHONE, 'Body' => $body, 'NumMedia' => 0])->assertOk()->getContent(), ENT_QUOTES | ENT_XML1);
    }

    public function test_correct_answer_closes_as_correct_without_touching_data(): void
    {
        $r = $this->asked();
        $this->ai(['type' => 'review_ok', 'text' => '']);

        $this->assertStringContainsString('kayıt doğruymuş', $this->send('evet doğru yazılmış'));
        $r->refresh();
        $this->assertSame('correct', $r->status);
        $this->assertStringContainsString('evet doğru yazılmış', $r->decision_note);
        $this->assertSame('evet doğru yazılmış', $r->customer_answer);
    }

    public function test_correction_card_confirmed_closes_as_resolved(): void
    {
        $r = $this->asked();
        $e = Expense::create(['expense_date' => now()->subDay()->toDateString(), 'amount' => 18261, 'description' => 'Testtoptan', 'payment_status' => 'paid']);
        $this->ai(['type' => 'undo', 'replacement' => ['kind' => 'expense', 'amount' => 16261, 'description' => 'Testtoptan', 'paid' => true, 'confidence' => 'high'],
            'find' => ['amount' => 18261.0]]);

        $card = $this->send('hayır 18.261 değil 16.261 olacaktı');
        $this->assertStringContainsString('Yerine yazacağım', $card);
        $this->assertSame('asked', $r->fresh()->status, 'onaysız kapanmaz');

        $this->send('evet');
        $this->assertNull(Expense::find($e->id));
        $this->assertSame(1, Expense::where('description', 'Testtoptan')->where('amount', 16261)->count());
        $r->refresh();
        $this->assertSame('resolved', $r->status);
        $this->assertStringContainsString('Müşteri düzeltti', $r->decision_note);
    }

    public function test_short_yes_goes_to_assistant_not_thumbs_up(): void
    {
        $this->asked();
        $mock = Mockery::mock(ChatAssistant::class);
        $mock->shouldReceive('respond')->once()->andReturn(['type' => 'reply', 'text' => 'Kartı hazırlıyorum']);
        $this->app->instance(ChatAssistant::class, $mock);

        $this->assertStringContainsString('Kartı hazırlıyorum', $this->send('evet'));
    }

    public function test_declined_closes_as_not_needed(): void
    {
        $r = $this->asked();
        $this->ai(['type' => 'review_ok', 'verdict' => 'declined', 'text' => '']);

        $this->assertStringContainsString('dokunmadım', $this->send('hayır'));
        $this->assertSame('dismissed', $r->fresh()->status);
        $this->assertStringContainsString('Müşteri istemedi', $r->fresh()->decision_note);
    }

    public function test_no_active_review_means_review_ok_is_harmless(): void
    {
        $this->ai(['type' => 'review_ok', 'text' => '']);

        $this->assertStringNotContainsString('doğruymuş', $this->send('doğru'));
    }

    public function test_prompt_carries_question_and_record(): void
    {
        $block = $this->asked()->promptBlock();

        $this->assertStringContainsString('KAYIT KONTROLÜ', $block);
        $this->assertStringContainsString('Testtoptan 18.261 doğru mu?', $block);
        $this->assertStringContainsString('review_answer', $block);
        $this->assertNotNull(RecordReview::activeFor(self::PHONE));
    }

    /** Bilgi sorusu (28.10, Akkuş Ekim maaşı): cevap ne olursa olsun yalnız not alınır — "kayıt doğruymuş" denmez, kart önerilmez. */
    public function test_info_question_only_takes_note(): void
    {
        $r = RecordReview::create(['firm_name' => 'Test', 'phone' => '905557770023', 'problem' => RecordReview::INFO_PREFIX . ' — Ekim maaşı ödendi mi?',
            'question' => 'Ekim maaşlarını ödemiş miydiniz?', 'status' => 'asked', 'asked_at' => now()->subHour()]);
        $this->assertStringContainsString('BİLGİ SORUSU', $r->promptBlock());
        $this->assertStringContainsString('KART ÖNERME', $r->promptBlock());
        $this->ai(['type' => 'review_ok', 'verdict' => 'declined', 'text' => '']);

        $reply = $this->send('Abdullah ödendi, Özkan ödenmedi');

        $this->assertStringContainsString('not aldım', $reply);
        $this->assertStringNotContainsString('doğruymuş', $reply);
        $r->refresh();
        $this->assertSame('correct', $r->status);
        $this->assertSame('Abdullah ödendi, Özkan ödenmedi', $r->customer_answer);
    }
}
