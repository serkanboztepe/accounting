<?php

namespace Tests\Feature;

use App\Services\Whatsapp\NaturalVoice;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Doğal konuşma: AI özeti insan gibi yazar; rakam/uyarı/soru güvenliği tutmazsa kalıp aynen gider. */
class NaturalVoiceTest extends TestCase
{
    use DatabaseTransactions;

    private string $summary = "💸 *Sen* → Fatih 👷 (yeni personel): 20.000,00 ₺ ödedin\n• Tür: Maaş avansı (maaşından düşülür)\n• Tarih: 2026-10-16\n• Bakiye: 0 → alacağın 20.000,00\n\nKaydedeyim mi? *evet* / *tamam* / *ok* — yanlış bir şey varsa düzeltmeyi yazman yeterli.";

    protected function setUp(): void
    {
        parent::setUp();
        config(['modules' => ModuleProfiles::resolve('trade', ['natural_voice' => true]), 'services.anthropic.api_key' => 'test']);
    }

    private function fakeAi(array $reply): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory); // önceki sahte cevap kalmasın
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'write_reply', 'input' => $reply]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10]], 200)]);
    }

    public function test_natural_reply_is_used_when_safe(): void
    {
        $this->fakeAi(['lead' => "Fatih'e 20 bin maaş avansı verdin, onu personel olarak açıyorum — maaşından düşeriz.",
            'card' => ['👷 Fatih · Maaş avansı · 20.000 ₺ · bugün'], 'ask' => 'Kaydedeyim mi?']);

        $out = NaturalVoice::render($this->summary, 'Personelim Fatihe 20 bin avans verdim', 20000);

        $this->assertStringStartsWith("Fatih'e 20 bin maaş avansı verdin", $out);
        $this->assertStringContainsString('👷 Fatih · Maaş avansı · 20.000 ₺', $out);
        $this->assertStringEndsWith('Kaydedeyim mi?', $out);
    }

    public function test_invented_number_falls_back_to_template(): void
    {
        $this->fakeAi(['lead' => "Fatih'e 25 bin avans verdin.", 'card' => ['Fatih · 25.000 ₺'], 'ask' => 'Kaydedeyim mi?']);

        $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'Personelim Fatihe 20 bin avans verdim', 20000));
    }

    public function test_lost_main_amount_warning_or_question_falls_back(): void
    {
        $this->fakeAi(['lead' => 'Fatih için avans kaydı hazır.', 'card' => ['👷 Fatih · Maaş avansı'], 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'avans', 20000), 'ana tutar kayboldu');

        $warn = "🛒 Alış: 2.000,00 ₺\n⚠️ Birim fiyat 100,00 ₺ — son alış 1.430,00 ₺ idi.\n\nKaydedeyim mi?";
        $this->fakeAi(['lead' => '20 çuval un aldın, toplam 2.000 ₺.', 'card' => ['Un · 2.000 ₺'], 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($warn, NaturalVoice::render($warn, '20 çuval un aldım 100 tl', 2000), 'uyarı kayboldu');

        $q = "🧾 Un satışı\n❓ Kaç çuval sattın, kaç liraya?";
        $this->fakeAi(['lead' => 'Un satışını yazıyorum.', 'card' => ['Un'], 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($q, NaturalVoice::render($q, 'un sattım'), 'soru kayboldu');
    }

    public function test_disabled_module_or_no_time_keeps_template(): void
    {
        Http::fake();
        config(['modules.natural_voice' => false]);
        $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'x', 20000));

        config(['modules.natural_voice' => true]);
        \App\Services\Whatsapp\ChatAssistant::$deadline = microtime(true) + 1;
        try {
            $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'x', 20000));
        } finally {
            \App\Services\Whatsapp\ChatAssistant::$deadline = null;
        }
        Http::assertNothingSent();
    }
}
