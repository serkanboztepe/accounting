<?php

namespace Tests\Feature\Eval;

use PHPUnit\Framework\TestCase;

/** Yeniden oynatma puanlaması — AI'sız (ölçüm altyapısının kendi testleri). */
class ReplayScoreTest extends TestCase
{
    public function test_turkish_names_match_without_database_lower(): void
    {
        // 23.10 ölçümü: PostgreSQL LOWER "Ş" harfini çevirmediği için "Şeker'in kardeşi" bulunamamıştı.
        $names = [10 => "Şeker'in kardeşi", 11 => 'KADİR SARIOĞLU', 12 => 'IŞIK Yapı', 13 => 'Abdullah Günhan'];

        $this->assertSame(10, ReplayScore::findParty('şeker', $names));
        $this->assertSame(10, ReplayScore::findParty('ŞEKER', $names));
        $this->assertSame(11, ReplayScore::findParty('kadir sarıoğlu', $names));
        $this->assertSame(12, ReplayScore::findParty('ışık', $names), 'I → ı');
        $this->assertSame(13, ReplayScore::findParty('Günhan', $names));
        $this->assertNull(ReplayScore::findParty('Hazal', $names));
    }

    public function test_newest_matching_party_wins(): void
    {
        $this->assertSame(21, ReplayScore::findParty('ayten', [20 => 'Ayten', 21 => 'Nusaybin bayisi Ayten']));
    }

    public function test_rows_expected_unexpected_and_duplicates_are_separate(): void
    {
        $want = ['has' => [['odeme', 26500]], 'not' => ['alis']];

        $ok = ReplayScore::matchRows([['odeme', 26500.0]], $want);
        $this->assertSame([[], [], []], [$ok['missing'], $ok['unexpected'], $ok['forbidden']]);

        // Çift kayıt: beklenen bir ödeme, iki ödeme oluşmuş → biri beklenmeyen.
        $dup = ReplayScore::matchRows([['odeme', 26500.0], ['odeme', 26500.0]], $want);
        $this->assertSame([], $dup['missing']);
        $this->assertSame(['odeme 26500'], $dup['unexpected']);

        // Yanlış tür: alış + ödeme (Abdullah 22.10) → alış hem beklenmeyen hem yasak.
        $wrong = ReplayScore::matchRows([['alis', 26500.0], ['odeme', 26500.0]], $want);
        $this->assertSame(['alis 26500'], $wrong['unexpected']);
        $this->assertSame(['alis'], $wrong['forbidden']);

        // Hiç kayıt yok → yalnız eksik.
        $none = ReplayScore::matchRows([], $want);
        $this->assertSame(['odeme 26500'], $none['missing']);
        $this->assertSame([], $none['unexpected']);
    }

    public function test_allowed_companion_row_is_not_unexpected(): void
    {
        // Kayıtlı borcu olmayan "borcum ödendi": eski borç (alış) + ödeme — alış izinli yan kayıt.
        $m = ReplayScore::matchRows([['alis', 100000.0], ['odeme', 100000.0]], ['has' => [['odeme', 100000]], 'allow' => [['alis', 100000]]]);

        $this->assertSame([[], []], [$m['missing'], $m['unexpected']]);
    }

    public function test_confirm_footer_is_not_a_question(): void
    {
        $q = ReplayScore::questions([['in' => 'x', 'out' => "💰 ENES → sana: 25.000 ödedi\n\nKaydedeyim mi? *evet*"]], []);

        $this->assertSame([], $q['asked']);
    }

    public function test_question_in_card_is_found_and_judged_against_allowed_patterns(): void
    {
        $card = "💰 ENES UÇAR → sana: 25.000,00 ₺ ödedi\n• Bakiye: 120.000 → 95.000\n❓ Bu ödeme hangi iş için?\n\nKaydedeyim mi? *evet*";
        $amount = "❓ Tutarı ne kadar? Sadece rakamı yazman yeterli (ör. 20000).\nVazgeçmek için *iptal*.";

        $q = ReplayScore::questions([['in' => 'a', 'out' => $card], ['in' => 'b', 'out' => $amount]], ['tutar|ne kadar']);

        $this->assertCount(2, $q['asked']);
        $this->assertSame('Bu ödeme hangi iş için?', $q['asked'][0]['text']);
        $this->assertCount(1, $q['unnecessary'], 'tutar sorusu izinli, iş sorusu gereksiz');
        $this->assertSame([], $q['loops']);
    }

    public function test_same_menu_twice_is_a_loop_even_with_different_numbers(): void
    {
        $menu = fn ($n) => "❓ Abdullah Günhan'a {$n} ₺ — ne için?\n*1* İş/mal geldi · *2* Peşin (iş/mal sonra) · *3* Eski borcu ödedim\nVazgeçmek için *iptal*.";

        $q = ReplayScore::questions([['in' => 'a', 'out' => $menu('20.000,00')], ['in' => 'avans verdim', 'out' => $menu('20.000')]], []);

        $this->assertCount(1, $q['loops']);
        $this->assertCount(2, $q['unnecessary']);
    }

    public function test_allowed_context_in_same_message_counts(): void
    {
        $out = "Hangi işlem için diyorsun, az önceki peynir satışı zaten kapandı (bakiye 0). Başka bir şeyden mi 6.000 TL aldın?";

        $this->assertSame([], ReplayScore::questions([['in' => '6000 aldım', 'out' => $out]], ['zaten|kapandı'])['unnecessary']);
        $this->assertCount(1, ReplayScore::questions([['in' => '6000 aldım', 'out' => $out]], [])['unnecessary']);
    }

    public function test_question_without_marker_is_found_by_question_mark(): void
    {
        $q = ReplayScore::questions([['in' => 'a', 'out' => "Bu 100.000 ₺ Hazal Doğan'a mı ödendi, yoksa ondan mı alındı?\nVazgeçmek için *iptal*."]], []);

        $this->assertSame("Bu 100.000 ₺ Hazal Doğan'a mı ödendi, yoksa ondan mı alındı?", $q['asked'][0]['text']);
    }
}
