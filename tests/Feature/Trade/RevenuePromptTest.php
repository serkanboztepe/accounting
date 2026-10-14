<?php

namespace Tests\Feature\Trade;

use App\Services\Whatsapp\WhatsappSender;
use App\Support\TradeDesk;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Akşam ciro sorusu: yalnız ayarı açık firmada; o gün peşin satış varsa sorulmaz. */
class RevenuePromptTest extends TestCase
{
    use DatabaseTransactions;

    public function test_off_by_default(): void
    {
        config(['modules.revenue_prompt' => false, 'modules.sales_desk' => true]);
        $this->artisan('revenue:prompt', ['--dry-run' => true])->expectsOutputToContain('kapalı')->assertSuccessful();
    }

    public function test_asks_when_no_cash_sale_today_and_skips_after_one(): void
    {
        config(['modules.revenue_prompt' => true, 'modules.sales_desk' => true]);
        \App\Models\Sale::whereNull('party_id')->whereDate('sale_date', now()->toDateString())->delete();

        $this->artisan('revenue:prompt', ['--dry-run' => true])->expectsOutputToContain('Bugünkü ciro')->assertSuccessful();

        TradeDesk::saveSale(['mode' => 'cash', 'manual_amount' => '1000', 'sale_date' => now(\App\Models\Reminder::TIMEZONE)->toDateString()]);
        $this->artisan('revenue:prompt', ['--dry-run' => true])->expectsOutputToContain('sorulmadı')->assertSuccessful();
    }

    /** Kendi şablonu onaylıysa ciro_sorusu (ilk adla); onaysızsa onaylı hatirlatma şablonu. */
    public function test_uses_own_template_when_approved_else_reminder_template(): void
    {
        config([
            'modules.revenue_prompt' => true, 'modules.sales_desk' => true,
            'services.whatsapp.reminder_phones' => ['+905550000009'],
            'services.whatsapp.revenue_content_sid' => 'HXrevenue', 'services.whatsapp.reminder_content_sid' => 'HXreminder',
        ]);
        \App\Models\Sale::whereNull('party_id')->whereDate('sale_date', now(\App\Models\Reminder::TIMEZONE)->toDateString())->delete();
        Cache::put('wa-template-approval:HXrevenue', 'pending', 600);
        Cache::put('wa-template-approval:HXreminder', 'approved', 600);

        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('sendTemplate')->once()->with('+905550000009', 'HXreminder', Mockery::any(), 'hatirlatma')->andReturn(true);
        $this->app->instance(WhatsappSender::class, $sender);
        $this->artisan('revenue:prompt')->expectsOutputToContain('(hatirlatma) ✓')->assertSuccessful();

        Cache::flush();
        Cache::put('wa-template-approval:HXrevenue', 'approved', 600);
        $sender = Mockery::mock(WhatsappSender::class);
        $sender->shouldReceive('sendTemplate')->once()->with('+905550000009', 'HXrevenue', ['1' => 'merhaba'], 'ciro_sorusu')->andReturn(true);
        $this->app->instance(WhatsappSender::class, $sender);
        $this->artisan('revenue:prompt')->expectsOutputToContain('(ciro_sorusu) ✓')->assertSuccessful();
    }

    /** Akkuş (14 Ekim): 3 numara — işaretli numara varsa yalnız ona, yoksa hepsine. */
    public function test_marked_number_only_else_all_active(): void
    {
        $firm = \App\Models\HubFirm::create(['name' => 'Ciro Test', 'url' => 'https://example.test']);
        $phone = fn (string $n, array $a = []) => \App\Models\HubPhone::create($a + ['phone' => $n, 'hub_firm_id' => $firm->id, 'name' => 'Kişi ' . $n]);
        $phone('905550000101');
        $chosen = $phone('905550000102');
        $phone('905550000103', ['is_active' => false]);

        $this->assertEquals(['905550000101', '905550000102'], array_keys(\App\Console\Commands\SendRevenuePrompt::firmRecipients($firm->id)));

        $chosen->update(['receives_revenue_prompt' => true]);
        $this->assertEquals(['905550000102'], array_keys(\App\Console\Commands\SendRevenuePrompt::firmRecipients($firm->id)));
    }
}
