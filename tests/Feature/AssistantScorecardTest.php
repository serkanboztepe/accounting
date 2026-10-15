<?php

namespace Tests\Feature;

use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Support\AssistantScorecard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Asistan karnesi: kayıt / geri alma / anlaşılmadı / yarım / 15 sn üstü doğru sayılır (fark ölçülür — yerel veri karışmaz). */
class AssistantScorecardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_counts_and_slow_replies(): void
    {
        $before = AssistantScorecard::compute();
        $phone = '905550000777';
        $at = now()->subHours(3);
        $msg = function (string $dir, ?string $kind, $time) use ($phone) {
            $m = WhatsappMessage::create(['phone' => $phone, 'direction' => $dir, 'body' => 'x', 'kind' => $kind]);
            $m->forceFill(['created_at' => $time])->save();
        };
        $msg('in', 'trade_sale', $at);
        $msg('out', null, $at->copy()->addSeconds(3));
        $msg('in', 'undo', $at->copy()->addMinute());
        $msg('out', null, $at->copy()->addMinute()->addSeconds(20)); // Twilio sınırını aştı
        $msg('in', 'help', $at->copy()->addMinutes(2));
        $msg('out', null, $at->copy()->addMinutes(2)->addSeconds(2));
        WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => [], 'summary' => 's', 'status' => 'confirmed']);
        $open = WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => [], 'summary' => 's', 'status' => 'awaiting_confirmation']);
        $open->forceFill(['created_at' => now()->subHours(2)])->save();
        WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => [], 'summary' => 's', 'status' => 'awaiting_confirmation']); // 1 saatten yeni: yarım değil

        $after = AssistantScorecard::compute();

        $this->assertSame(3, $after['in'] - $before['in']);
        $this->assertSame(1, $after['undo'] - $before['undo']);
        $this->assertSame(1, $after['help'] - $before['help']);
        $this->assertSame(1, $after['saved'] - $before['saved']);
        $this->assertSame(1, $after['open'] - $before['open']);
        $this->assertSame(1, $after['slow'] - $before['slow']);
        $this->assertGreaterThanOrEqual(20, $after['max']);
    }

    public function test_hub_page_renders(): void
    {
        if (! isset(\Filament\Facades\Filament::getPanels()['hub'])) {
            $this->app->register(\App\Providers\Filament\HubPanelProvider::class);
        }
        \Filament\Facades\Filament::setCurrentPanel('hub');
        $this->actingAs(\App\Models\HubUser::forceCreate(['name' => 'Hub', 'email' => 'hub-' . uniqid() . '@example.com', 'password' => 'x']), 'hub');

        \Livewire\Livewire::test(\App\Filament\Hub\Pages\AssistantScorecardPage::class)
            ->assertOk()->assertSee('Son 7 gün')->assertSee('15 sn üstü')->assertSee('Toplam');
    }
}
