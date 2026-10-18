<?php

namespace Tests\Feature;

use App\Services\Whatsapp\NaturalVoice;
use App\Support\ModuleProfiles;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Doğal konuşma: AI yalnız giriş cümlesini ve son soruyu yazar; kart kodun özetinden AYNEN gelir.
 * Rakam/ad/uyarı/soru güvenliği tutmazsa kalıp aynen gider.
 */
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

    public function test_natural_lead_on_top_of_code_card(): void
    {
        $this->fakeAi(['lead' => "Fatih'e 20 bin maaş avansı verdin, onu personel olarak açıyorum — maaşından düşeriz.", 'ask' => 'Kaydedeyim mi?']);

        $out = NaturalVoice::render($this->summary, 'Personelim Fatihe 20 bin avans verdim', 20000);

        $this->assertStringStartsWith("Fatih'e 20 bin maaş avansı verdin", $out);
        // Kart kodun satırları, aynen.
        $this->assertStringContainsString("💸 *Sen* → Fatih 👷 (yeni personel): 20.000,00 ₺ ödedin\n• Tür: Maaş avansı (maaşından düşülür)", $out);
        $this->assertStringContainsString('• Bakiye: 0 → alacağın 20.000,00', $out);
        $this->assertStringEndsWith('Kaydedeyim mi?', $out);
    }

    public function test_ai_card_lines_are_never_used(): void
    {
        // Eski şema: AI kart yazsa bile kullanılmaz.
        $this->fakeAi(['lead' => 'Fatih için avans hazır.', 'card' => ['👷 Fatih · Günlük 500 ₺'], 'ask' => 'Kaydedeyim mi?']);

        $out = NaturalVoice::render($this->summary, 'Fatihe 20 bin avans verdim, günlük 500', 20000);

        $this->assertStringNotContainsString('Günlük 500', $out);
        $this->assertStringContainsString('• Tür: Maaş avansı', $out);
    }

    public function test_invented_number_falls_back_to_template(): void
    {
        $this->fakeAi(['lead' => "Fatih'e 25 bin avans verdin.", 'ask' => 'Kaydedeyim mi?']);

        $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'Personelim Fatihe 20 bin avans verdim', 20000));
    }

    /** Çiftlik 16.10 14:47: kayıtta olmayan "günde 16" kullanıcının mesajından alınıp onay kartına girmişti. */
    public function test_number_only_in_user_message_is_not_allowed(): void
    {
        $summary = "🆕 *Ürün kartı açılacak — kontrol et:*\n• Yem (adet)\nBirim yanlışsa düzeltmeyi yaz (ör. \"maya paket\").\n\nKaydedeyim mi? *evet* / *tamam* / *ok* — yanlış bir şey varsa düzeltmeyi yazman yeterli.";
        $this->fakeAi(['lead' => 'Elindeki 33 adet yemi stoklara yeni ürün olarak açıyorum, günde 16 adet tükendiğini not düştüm.', 'ask' => 'Kaydedeyim mi?']);

        $this->assertSame($summary, NaturalVoice::render($summary, '33 adet yem var elimde gunde 16 adet gidiyor stoklara ekle'));
    }

    /** Çiftlik 17.10 10:14: kayıtta iki kalem de "Yem" iken giriş "besi yemi / buzağı yemi" diyerek yanlış eşleşmeyi örttü. */
    public function test_item_name_not_in_record_is_not_allowed(): void
    {
        $summary = "🛒 *Peşin alış — kontrol et:*\n   • 20 Adet Ezme (stok 5 → 25)\n   • 30 Adet Yem (stok 33 → 63)\n   • 10 Adet Yem (stok 33 → 43)\n• Toplam: 0,00 ₺\n❓ Kimden aldın? Parasını verdiysen \"peşin\" yaz.";
        $this->fakeAi(['lead' => '20 ezme, 30 besi yemi ve 10 buzağı yemi geldi, stoğa bu şekilde işliyorum.', 'ask' => 'Kimden aldın?']);

        $this->assertSame($summary, NaturalVoice::render($summary, '20 ezme 30 besi yemi 10 buzağı yemi geldi'));

        // Kayıttaki adla yazarsa geçer (ek toleransı: "yemi" ~ "Yem").
        $this->fakeAi(['lead' => 'Ezme ve yemi stoğa ekliyorum.', 'ask' => 'Kimden aldın?']);
        $out = NaturalVoice::render($summary, '20 ezme 30 yemi geldi');
        $this->assertStringStartsWith('Ezme ve yemi stoğa ekliyorum.', $out);
        $this->assertStringContainsString('• 10 Adet Yem (stok 33 → 43)', $out);
        $this->assertStringEndsWith('Kimden aldın?', $out);
    }

    public function test_person_name_not_in_record_is_not_allowed(): void
    {
        $summary = "💸 *Sen* → Sinan Sele: 5.000,00 ₺ ödedin\n\nKaydedeyim mi? *evet* / *tamam* / *ok* — yanlış bir şey varsa düzeltmeyi yazman yeterli.";
        $this->fakeAi(['lead' => "Sinan Usta'ya 5 bin verdin.", 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($summary, NaturalVoice::render($summary, 'Sinan ustaya 5 bin verdim', 5000));

        $this->fakeAi(['lead' => "Sinan Sele'ye 5 bin verdin.", 'ask' => 'Kaydedeyim mi?']);
        $this->assertStringStartsWith("Sinan Sele'ye 5 bin verdin.", NaturalVoice::render($summary, 'Sinan ustaya 5 bin verdim', 5000));
    }

    /** Seçenekli alt satır kodun beklediği cevaplardır ("o bana" / "ben ona") — AI'ın sorusuyla yutulmaz. */
    public function test_option_footer_is_kept_verbatim(): void
    {
        $summary = "📒 Ali: 3.000,00 ₺ borç\n\n*o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun) yaz, vazgeçmek için *iptal*.";
        $this->fakeAi(['lead' => 'Ali ile 3 bin borç var.', 'ask' => 'Kim kime borçlu?']);

        $out = NaturalVoice::render($summary, 'Ali 3000 borç', 3000);

        $this->assertStringStartsWith('Ali ile 3 bin borç var.', $out);
        $this->assertStringEndsWith('*o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun) yaz, vazgeçmek için *iptal*.', $out);
        $this->assertStringNotContainsString('Kim kime borçlu?', $out);
    }

    public function test_lost_warning_or_question_falls_back(): void
    {
        $this->fakeAi(['lead' => 'Fatih için avans kaydı hazır.', 'ask' => 'Kaydedeyim mi?']);
        $out = NaturalVoice::render($this->summary, 'avans', 20000);
        $this->assertStringContainsString('20.000,00 ₺', $out, 'ana tutar kartta (koddan) hep var');

        $warn = "🛒 Alış: 2.000,00 ₺\n⚠️ Birim fiyat 100,00 ₺ — son alış 1.430,00 ₺ idi.\n\nKaydedeyim mi?";
        $this->fakeAi(['lead' => '20 çuval un aldın, toplam 2.000 ₺.', 'ask' => 'Kaydedeyim mi?']);
        $this->assertStringContainsString('⚠️ Birim fiyat 100,00 ₺', NaturalVoice::render($warn, '20 çuval un aldım 100 tl', 2000), 'uyarı kartta aynen');

        $q = "🧾 Un satışı\n❓ Kaç çuval sattın, kaç liraya?";
        $this->fakeAi(['lead' => 'Un satışını yazıyorum.', 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($q, NaturalVoice::render($q, 'un sattım'), 'soru kayboldu');
    }

    /** "Maaş ödemesini aldım" — onaydan önce iddia ve para yönü belirsiz (gerçek AI denemesi 18.10). */
    public function test_first_person_past_claim_falls_back(): void
    {
        $this->fakeAi(['lead' => "Fatih'in avans ödemesini aldım, hesabı açıyorum.", 'ask' => 'Kaydedeyim mi?']);

        $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'Fatihe 20 bin avans verdim', 20000));
    }

    public function test_question_path_keeps_cancel_line_last(): void
    {
        $q = "❓ Asansör takıldı mı, yoksa peşin mi ödüyorsunuz?\nVazgeçmek için *iptal*.";
        $this->fakeAi(['lead' => "Osman'a ödeme yaptığını anladım.", 'ask' => 'Asansör takıldı mı, yoksa peşin mi ödüyorsun?']);

        $out = NaturalVoice::render($q, "Osman'a asansör parası verdim");

        $this->assertSame("Osman'a ödeme yaptığını anladım.\n\nAsansör takıldı mı, yoksa peşin mi ödüyorsun?\nVazgeçmek için *iptal*.", $out);
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
