<?php

namespace Tests\Feature;

use App\Models\ConversationReport;
use App\Models\Party;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\ConversationAnalyzer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Günlük konuşma analizi: dünün konuşmaları → Claude → numaralı rapor + yöneticiye WhatsApp özeti.
 * Carilerin adları Claude'a gitmez (ilk isim dahil), rapordaki tutarlar gizlenir.
 * Tarih 2031: geliştirme veritabanındaki gerçek konuşmalar karışmasın.
 */
class ConversationAnalysisTest extends TestCase
{
    use DatabaseTransactions;

    private const DAY = '2031-02-01';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.api_key' => 'test-key',
            'services.anthropic.analysis_model' => 'claude-opus-5-5',
            'services.twilio.sid' => 'ACtest',
            'services.twilio.token' => 'tok',
            'services.whatsapp.from' => '+14786665916',
            'services.whatsapp.admin_phones' => ['0545 360 67 83'],
        ]);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => "ÖZET: 1 takılma, 1 öneri — kira 20.000 iki kez yazıldı\n\n## Genel durum\nKullanıcı 20.000 tl kira yazdı, 2031 yılında, saat 21:36.\n\n## Öneriler\n**1. Deneme** — *Sana soru:* sorsun mu?"]],
                'usage' => ['input_tokens' => 10000, 'output_tokens' => 2000],
            ]),
            'api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201),
        ]);
    }

    private function message(string $direction, string $body, ?string $kind = null): void
    {
        $m = WhatsappMessage::create(['phone' => '905540000000', 'direction' => $direction, 'body' => $body, 'kind' => $kind]);
        $m->forceFill(['created_at' => self::DAY . ' 21:36:00'])->save();
    }

    public function test_writes_numbered_masked_report_and_sends_whatsapp_summary(): void
    {
        Party::create(['name' => 'Tuncay Sarıhan Test']);
        $this->message('in', "Tuncay'ın ekstresini at", 'statement');
        $this->message('out', 'Tuncay Sarıhan Test: hesap kapalı');

        $this->artisan('conversations:analyze', ['--date' => self::DAY])->assertSuccessful();

        // Claude'a giden metinde carinin ne tam adı ne ilk adı var.
        Http::assertSent(function (ClientRequest $r) {
            if (! str_contains($r->url(), 'anthropic')) {
                return false;
            }
            $sent = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($sent, 'Cari-') && ! str_contains($sent, 'Tuncay') && ! str_contains($sent, 'Sarıhan');
        });

        $report = ConversationReport::whereDate('report_date', self::DAY)->sole();
        $this->assertSame(2, $report->message_count);
        $this->assertSame(1, $report->firm_count);
        $this->assertStringContainsString('kira [tutar] iki kez', $report->summary);
        $this->assertStringContainsString('[tutar] tl kira', $report->body);
        $this->assertStringContainsString('2031 yılında, saat 21:36', $report->body); // yıl ve saat kalır
        $this->assertStringNotContainsString('ÖZET:', $report->body);
        $this->assertEqualsWithDelta(0.08, (float) $report->cost_usd, 0.0001);         // 10k×4 + 2k×20 / 1M
        $this->assertNotNull($report->sent_at);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), 'api.twilio.com')
            && $r['To'] === 'whatsapp:+905453606783'
            && str_contains($r['Body'], "Rapor #{$report->id}")
            && str_contains($r['Body'], "\"Rapor {$report->id}: …\""));
    }

    public function test_same_day_is_not_analyzed_twice_unless_forced(): void
    {
        $this->message('in', '5 bin yakıt aldım', 'expense');
        $this->artisan('conversations:analyze', ['--date' => self::DAY, '--no-send' => true])->assertSuccessful();
        $this->artisan('conversations:analyze', ['--date' => self::DAY, '--no-send' => true])->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(1, ConversationReport::whereDate('report_date', self::DAY)->count());

        $this->artisan('conversations:analyze', ['--date' => self::DAY, '--no-send' => true, '--force' => true])->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertSame(1, ConversationReport::whereDate('report_date', self::DAY)->count());
    }

    public function test_day_without_messages_writes_no_report(): void
    {
        $this->artisan('conversations:analyze', ['--date' => '2031-02-02'])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, ConversationReport::whereDate('report_date', '2031-02-02')->count());
    }

    public function test_amount_masking_keeps_years_times_and_small_numbers(): void
    {
        $this->assertSame(
            '[tutar] tl, [tutar], [tutar] ₺, 47 tl, 2026 yılı, 21:36, 2026-10-10, 10.10',
            ConversationAnalyzer::maskAmounts('20.000 tl, 20000, 1.250,50 ₺, 47 tl, 2026 yılı, 21:36, 2026-10-10, 10.10'),
        );
    }
}
