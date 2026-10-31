<?php

/*
 * 31.10 Yeni Yapı demosunun GERÇEK cümleleri (kullanıcı Alim Tok'a sunum öncesi denedi) — firma "hafriyat" (Alim Tok düzeni:
 * sektör hafriyat, stoklu, anlaşma yönü both). Beklenen = doğru davranış; demoda bozulanlar: 999.990, "4 TL mi?", "130 TL mi?",
 * hafriyat alış sanıldı, "zaten kaydettik" (yeni/aynı sorulmadı), uydurma açıklama.
 */

$c = fn (string $id, array $steps, array $expect = []) => ['id' => $id, 'firm' => 'hafriyat', 'group' => 'capability',
    'steps' => $steps, 'expect' => $expect];
$s = fn (string $text, array $expect = []) => $expect === [] ? ['send' => $text] : ['send' => $text, 'expect' => $expect];

return [
    // 11:01 — yön sorusu → "Satış" → 1.000.000 (999.990 değil) → 400 tuğla teslimi 133.333,33.
    $c('bg-tugla-1milyon-teslim', [
        $s('Ali boztepeye 3000 tuğla 1 milyondan anlaştım', ['reply_any' => ['sattım', 'satış anlaşması'], 'reply_not' => ['999.990']]),
        $s('Satış', ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale', 'party_id' => '@party:Ali Boztepe'],
            'reply_has' => ['1.000.000'], 'reply_not' => ['999.990']]),
        $s('Evet'),
        $s('Ali boztepeye 400 tuğla gönderdim', ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'],
            'reply_has' => ['133.333,33']]),
    ]),
    // 11:13 — kepçe kaydı, sonra aynı tutarda ikinci cümle: kendi karar vermez, yeni mi aynı mı sorar.
    $c('bg-kepce-tekrar-sorar', [
        $s('Ali boztepeye 4 saat kepçe saati 1000tl', ['draft' => ['kind' => ['sale', 'trade_sale'], 'amount' => 4000], 'reply_not' => ['TL mi']]),
        $s('evet'),
        $s('Ali Boztepe ye kepçe hizmeti 4000 tl', ['reply_any' => ['yeni', 'aynı'], 'reply_not' => ['gerek yok']]),
    ]),
    // 11:15 — tahsilat.
    $c('bg-1000-odedi', [$s('Ali Boztepe 1000tl ödedi')],
        ['draft' => ['kind' => 'collection', 'party_id' => '@party:Ali Boztepe', 'amount' => 1000]]),
    // 11:16 — "5000'lik iş yaptım": satış, açıklama uydurulmaz (önceki kepçe konuşmasından "Kepçe hizmeti" yazılmıştı).
    $c('bg-5000lik-is', [
        $s('Ali boztepeye 4 saat kepçe saati 1000tl'), $s('evet'),
        $s('Ali Boztepe 5000 lik iş yaptım', ['draft' => ['kind' => ['sale', 'trade_sale'], 'party_id' => '@party:Ali Boztepe', 'amount' => 5000]]),
        $s('evet'),
    ], ['balance' => ['@party:Ali Boztepe' => 9000]]),
    // 11:18 — hafriyat alındı = Abdullah'ın toprağını biz aldık = ona iş yaptık (alış değil), ölçek sorusu yok.
    $c('bg-mukap-alindi', [
        $s('Abdullah uçar 1000 mükap hafriyat alındı mükap 130 tl', ['draft' => ['kind' => ['sale', 'trade_sale'], 'amount' => 130000],
            'reply_not' => ['TL mi', 'borçlanırsın']]),
        $s('evet'),
    ], ['balance' => ['@party:Abdullah Uçar' => 130000], 'stock' => ['Mükap' => null, 'Hafriyat' => null]]),
    // 11:23 — "sattım" açık: yön sorulmaz; teslim 500 × 500 = 250.000.
    $c('bg-alim-5milyon-teslim', [
        $s('Alim tok 10.000 tuğla 5 milyon anlaştım sattım', ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale'],
            'reply_has' => ['5.000.000'], 'reply_not' => ['Satış anlaşması mı']]),
        $s('Evet'),
        $s('Alime 500 tuğla gitti', ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'], 'reply_has' => ['250.000']]),
    ]),
    // 11:25 — gider ve hatırlatma.
    $c('bg-10000-mazot', [$s('10000 mazot')], ['draft' => ['kind' => 'expense', 'amount' => 10000], 'reply_not' => ['TL mi']]),
    $c('bg-belediye-hatirlat', [$s('3 gün sonra belediyeyi hatırlat')], ['draft' => ['kind' => 'reminder']]),
];
