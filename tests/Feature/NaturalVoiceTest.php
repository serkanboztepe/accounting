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

    private string $summary = "💸 *Sen* → Fatih 👷 (yeni personel): 20.000,00 ₺ ödedin\n• Tür: Maaş avansı (maaşından düşülür)\n• Tarih: 2026-10-16\n• Bakiye: 0 → alacağın 20.000,00\n\nKaydedeyim mi? *evet*";

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

    /** Kullanıcı 20.10: "bu da gereksiz, direkt alıştan başlasın" — soru yokken kart AYNEN gider, AI çağrılmaz. */
    public function test_card_without_question_is_sent_as_is_without_ai(): void
    {
        $this->fakeAi(['lead' => "Fatih'e 20 bin maaş avansı verdin.", 'ask' => 'Kaydedeyim mi?']);

        $this->assertSame($this->summary, NaturalVoice::render($this->summary, 'Personelim Fatihe 20 bin avans verdim', 20000));
        Http::assertNothingSent();
    }

    /** Firma 7, 20.10 23:33: soru (❓) yokken AI kendi uzun sorusunu yazıp proje satırını tekrarlıyordu — kodun kısa satırı kalır. */
    public function test_no_question_keeps_short_code_footer(): void
    {
        $this->fakeAi(['lead' => "Fatih'e 20 bin verdin.", 'ask' => 'Proje Deneme Fatih mi, yoksa başka proje mi (1-2-0)? Uygunsa "evet" diyebilirsin.']);

        $out = NaturalVoice::render($this->summary, 'Fatihe 20 bin verdim', 20000);

        $this->assertStringEndsWith("\n\nKaydedeyim mi? *evet*", $out);
        $this->assertStringNotContainsString('diyebilirsin', $out);
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
        $summary = "🆕 *Ürün kartı açılacak — kontrol et:*\n• Yem (adet)\nBirim yanlışsa düzeltmeyi yaz (ör. \"maya paket\").\n\nKaydedeyim mi? *evet*";
        $this->fakeAi(['lead' => 'Elindeki 33 adet yemi stoklara yeni ürün olarak açıyorum, günde 16 adet tükendiğini not düştüm.', 'ask' => 'Kaydedeyim mi?']);

        $this->assertSame($summary, NaturalVoice::render($summary, '33 adet yem var elimde gunde 16 adet gidiyor stoklara ekle'));
    }

    /** Çiftlik 17.10 10:14: kayıtta iki kalem de "Yem" iken giriş "besi yemi / buzağı yemi" diyerek yanlış eşleşmeyi örttü. */
    public function test_item_name_not_in_record_is_not_allowed(): void
    {
        $summary = "🛒 *Peşin alış — kontrol et:*\n   • 20 Adet Ezme (stok 5 → 25)\n   • 30 Adet Yem (stok 33 → 63)\n   • 10 Adet Yem (stok 33 → 43)\n• Toplam: 0,00 ₺\n❓ Kimden aldın? Parasını verdiysen \"peşin\" yaz.";
        $this->fakeAi(['lead' => '20 ezme, 30 besi yemi ve 10 buzağı yemi geldi, stoğa bu şekilde işliyorum.', 'ask' => 'Kimden aldın?']);

        $this->assertSame($summary, NaturalVoice::render($summary, '20 ezme 30 besi yemi 10 buzağı yemi geldi'));

        // Kayıttaki adla yazarsa geçer (ek toleransı: "yemi" ~ "Yem"); giriş cümlesi yok, kart en üstte.
        $this->fakeAi(['lead' => 'Ezme ve yemi stoğa ekliyorum.', 'ask' => 'Kimden aldın?']);
        $out = NaturalVoice::render($summary, '20 ezme 30 yemi geldi');
        $this->assertStringStartsWith('🛒 *Peşin alış', $out);
        $this->assertStringContainsString('• 10 Adet Yem (stok 33 → 43)', $out);
        $this->assertStringEndsWith('Kimden aldın?', $out);
    }

    public function test_person_name_not_in_record_is_not_allowed(): void
    {
        $summary = "💸 *Sen* → Sinan Sele: 5.000,00 ₺ ödedin\n\nKaydedeyim mi? *evet*";
        $this->fakeAi(['lead' => "Sinan Usta'ya 5 bin verdin.", 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($summary, NaturalVoice::render($summary, 'Sinan ustaya 5 bin verdim', 5000));

        $this->fakeAi(['lead' => "Sinan Sele'ye 5 bin verdin.", 'ask' => 'Kaydedeyim mi?']);
        $this->assertSame($summary, NaturalVoice::render($summary, 'Sinan ustaya 5 bin verdim', 5000), 'soru yok → kart aynen');
    }

    /** Seçenekli alt satır kodun beklediği cevaplardır ("o bana" / "ben ona") — AI'ın sorusuyla yutulmaz. */
    public function test_option_footer_is_kept_verbatim(): void
    {
        $summary = "📒 Ali: 3.000,00 ₺ borç\n\n*o bana* (o sana borçlu) ya da *ben ona* (sen ona borçlusun) yaz, vazgeçmek için *iptal*.";
        $this->fakeAi(['lead' => 'Ali ile 3 bin borç var.', 'ask' => 'Kim kime borçlu?']);

        $out = NaturalVoice::render($summary, 'Ali 3000 borç', 3000);

        $this->assertStringStartsWith('📒 Ali', $out);
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

        // Yalnız soru varken giriş cümlesi yok (Serkan 19.10: "uzatıyor uzatıyor").
        $this->assertSame("Asansör takıldı mı, yoksa peşin mi ödüyorsun?\nVazgeçmek için *iptal*.", $out);
    }

    /** Kartta soru varken: kart üstte, AI yalnız soruyu sadeleştirir — giriş cümlesi yazılmaz. */
    public function test_question_card_has_no_lead(): void
    {
        $summary = "💸 *Sen* → Ali: 20.000,00 ₺ ödedin\n❓ Ali'nin işi bitti mi, yoksa önceden mi ödüyorsun?\n\nKaydedeyim mi? *evet*";
        $this->fakeAi(['lead' => "Ali'ye ödeme yaptığını anladım.", 'ask' => "Ali'nin işi bitti mi, yoksa önceden mi ödüyorsun?"]);

        $out = NaturalVoice::render($summary, "Ali'ye 20 bin verdim", 20000);

        $this->assertStringStartsWith('💸 *Sen* → Ali', $out);
        $this->assertStringNotContainsString('anladım', $out);
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
