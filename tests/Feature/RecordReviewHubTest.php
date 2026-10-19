<?php

namespace Tests\Feature;

use App\Filament\Hub\RecordReviews\Pages\ManageRecordReviews;
use App\Filament\Hub\RecordReviews\RecordReviewResource;
use App\Models\RecordReview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hub → Kayıt Kontrolleri düzeni (kullanıcı 19.10): Açık / Cevap geldi / Kapandı sekmeleri; tek "Kapat" (sonuç + not); "Müşteriye sor"
 * kutusunda gidecek mesajın tam hali (düzenlenir, birebir gider).
 */
class RecordReviewHubTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (! isset(\Filament\Facades\Filament::getPanels()['hub'])) {
            $this->app->register(\App\Providers\Filament\HubPanelProvider::class);
        }
        \Filament\Facades\Filament::setCurrentPanel('hub');
        $this->actingAs(\App\Models\HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-' . uniqid() . '@example.com', 'password' => 'x']), 'hub');
    }

    private function review(array $o = []): RecordReview
    {
        return RecordReview::create($o + ['firm_name' => 'Testfırın', 'problem' => 'Bizim Toptan 18.261 gider yazıldı (problem-' . uniqid() . ')',
            'question' => 'Bizim Toptan 18.261 içinde un var mıydı?', 'status' => 'pending']);
    }

    public function test_tabs_split_open_answered_closed(): void
    {
        $open = $this->review();
        $waiting = $this->review(['status' => 'asked', 'asked_at' => now()]);
        $answered = $this->review(['status' => 'asked', 'asked_at' => now(), 'customer_answer' => 'evet 10 çuval un', 'answered_at' => now()]);
        $closed = $this->review(['status' => 'correct', 'decision_note' => 'doğru']);

        $this->assertTrue(RecordReview::open()->whereKey([$open->id, $waiting->id])->count() === 2);
        $this->assertSame([$answered->id], RecordReview::answered()->whereKey([$open->id, $waiting->id, $answered->id, $closed->id])->pluck('id')->all());
        $this->assertSame([$closed->id], RecordReview::closed()->whereKey([$open->id, $answered->id, $closed->id])->pluck('id')->all());

        Livewire::test(ManageRecordReviews::class)->assertOk()->assertSee('Cevap geldi')->assertSee('Kapandı')
            ->assertCanSeeTableRecords([$open, $waiting])->assertCanNotSeeTableRecords([$answered, $closed]);
        Livewire::test(ManageRecordReviews::class, ['activeTab' => 'answered'])->assertCanSeeTableRecords([$answered])->assertSee('evet 10 çuval un');
    }

    public function test_close_with_result_and_note(): void
    {
        $r = $this->review(['status' => 'asked', 'asked_at' => now(), 'customer_answer' => 'doğru', 'answered_at' => now()]);

        Livewire::test(ManageRecordReviews::class, ['activeTab' => 'answered'])
            ->callTableAction('close', $r, ['status' => 'correct', 'decision_note' => 'Emre doğru dedi'])->assertHasNoTableActionErrors();

        $r->refresh();
        $this->assertSame('correct', $r->status);
        $this->assertSame('Emre doğru dedi', $r->decision_note);
        $this->assertNotNull($r->decided_at);
    }

    public function test_ask_box_shows_the_full_message_that_will_be_sent(): void
    {
        $draft = (new \ReflectionMethod(RecordReviewResource::class, 'draftMessage'))->invoke(null, $this->review());

        $this->assertStringStartsWith('Merhaba 👋 Bir kaydınızı kontrol etmek istiyorum:', $draft);
        $this->assertStringContainsString('Bizim Toptan 18.261 içinde un var mıydı?', $draft);
        $this->assertStringContainsString('düzeltmeyi size onaylatırım', $draft);

        $sent = $this->review(['status' => 'asked', 'asked_at' => now(), 'question' => 'Daha önce giden tam metin']);
        $this->assertSame('Daha önce giden tam metin', (new \ReflectionMethod(RecordReviewResource::class, 'draftMessage'))->invoke(null, $sent));
    }
}
