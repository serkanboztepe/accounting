<?php

/*
 * Satıcı tarafı deneme seti (31.10) — firma "satici", tohum "st-temel" (cases.php): Yapı Market Mehmet'le açık tuğla SATIŞ
 * anlaşması (10.000 × 100, hiç gitmedi; panelde "müşteri"), Kuşak'tan kum ALIŞ anlaşması, Ahmet Yılmaz 300.000 alacak.
 * "Anlaştık → teslim ettim → parasını aldım" yönü karışmamalı. Grup "capability".
 */

$c = fn (string $id, array $steps, array $expect = []) => ['id' => $id, 'firm' => 'satici', 'group' => 'capability', 'seed' => 'st-temel',
    'steps' => $steps, 'expect' => $expect];
$s = fn (string $text, array $expect = []) => $expect === [] ? ['send' => $text] : ['send' => $text, 'expect' => $expect];

return [
    // ---------------------------------------------------------------- Anlaşma
    $c('st-kullanici-cumlesi-kimsiz', [$s('sattım 10.000 tuğla 1 milyona anlaştım 3000 tuğla gitti')],
        ['reply_any' => ['Kime', 'kime', 'Kiminle', 'kiminle', 'kimin']]),
    $c('st-anlasma-ve-ilk-teslim', [$s("Ahmet Yılmaz'a 10.000 tuğla sattım 1 milyona anlaştık, 3000 tuğla gitti",
        ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale', 'party_id' => '@party:Ahmet Yılmaz'], 'reply_has' => ['1.000.000']]), $s('evet')],
        ['reply_any' => ['3.000', '3000', '300.000']]),
    // Kullanıcının cümlesi (31.10): "sattım" yok; firma "Alış + satış" → yön sorulur ("Satış anlaşması mı yaptın, alış mı?"),
    // "sattım" → satış anlaşması (Ali Boztepe yeni cari, 10.000 × 100 = 1.000.000) → onay → "5 bin tuğla gönderdim" = o anlaşmadan satış teslimi.
    $c('st-yeni-musteri-anlasti', [
        $s("10.000 tuğla 1 milyondan Ali Boztepe'ye anlaştık", ['reply_any' => ['sattım', 'Satış anlaşması', 'satış anlaşması'], 'reply_not' => ['ödedin']]),
        $s('sattım', ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale', 'party_name' => 'Ali Boztepe'], 'reply_has' => ['1.000.000']]),
        $s('evet'),
        $s("Ali Boztepe'ye 5 bin tuğla gönderdim", ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'],
            'reply_has' => ['5.000', '500.000']]),
    ]),
    $c('st-anlasma-musteri-ipucu', [$s("Yapı Market Mehmet ile 2000 tuğla daha adedi 105'ten anlaştık")],
        ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet']]),
    $c('st-anlasma-ne-kaldi', [$s("Mehmet'le tuğla anlaşmasında ne kaldı")],
        ['reply_has' => ['10.000'], 'no_new_draft' => true]),

    // ---------------------------------------------------------------- Teslim (satış anlaşmasından)
    $c('st-teslim-gitti', [$s("Yapı Market Mehmet'e 3000 tuğla gitti")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet'], 'reply_has' => ['300.000']]),
    $c('st-teslim-gonderdim-kisa-ad', [$s("Mehmet'e 2 kamyon tuğla gönderdim, 4000 adet")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet'], 'reply_has' => ['4.000']]),
    $c('st-teslim-parasini-almadim', [$s("Yapı Market'e 3000 tuğla teslim ettim, parasını almadım")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'], 'reply_not' => ['ödedin']]),
    $c('st-teslim-pesin-aldim', [$s("Yapı Market'e 1000 tuğla gitti, 100 bin peşin aldım")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'paid' => true]]),
    // Teslim cümleleri (kullanıcı 31.10: "teslim ettim", "gönderdim", "5 bin tuğla gönderdim") — hepsi satış teslimi, 5.000 × 100 = 500.000.
    $c('st-teslim-ettim', [$s("Yapı Market Mehmet'e 5 bin tuğla teslim ettim")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet'], 'reply_has' => ['5.000', '500.000']]),
    $c('st-teslim-gonderdim-bin', [$s("Mehmet'e 5 bin tuğla gönderdim")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet'], 'reply_has' => ['5.000']]),
    $c('st-teslim-yolladim', [$s("Yapı Market'e bugün 5000 tuğla yolladık")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet'], 'reply_has' => ['5.000']]),
    $c('st-teslim-indirdik', [$s("Mehmet'in deposuna 5 bin tuğla indirdik")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale', 'party_id' => '@party:Yapı Market Mehmet']]),
    $c('st-teslim-kimsiz', [$s('5 bin tuğla gönderdim')],
        ['reply_any' => ['Kime', 'kime', 'Mehmet'], 'reply_not' => ['geldi', 'ödedin']]),
    $c('st-teslim-ettik-yazili-sayi', [$s("Yapı Market Mehmet'e beş bin tuğla teslim ettik")],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'], 'reply_has' => ['5.000']]),
    $c('st-alis-yonu-karismaz', [$s('Kuşaktan 20 m3 kum geldi')],
        ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'purchase', 'party_id' => '@party:Kuşak Beton']]),

    // ---------------------------------------------------------------- Para
    $c('st-tahsilat-hangisi', [$s('Yapı Market Mehmet 200 bin ödedi')],
        ['draft' => ['kind' => 'collection', 'party_id' => '@party:Yapı Market Mehmet', 'amount' => 200000], 'reply_has' => ['Hangisi için']]),
    $c('st-tahsilat-anlasmadan-dus', [$s("Yapı Marketten 150 bin aldım, tuğla anlaşmasından düş")],
        ['draft' => ['kind' => 'collection', 'party_id' => '@party:Yapı Market Mehmet', 'amount' => 150000], 'reply_not' => ['ödedin']]),
    $c('st-tahsilat-cek', [$s("Yapı Market Mehmet'ten 15.12 vadeli 250 bin çek aldım")],
        ['draft' => ['kind' => 'collection', 'party_id' => '@party:Yapı Market Mehmet', 'amount' => 250000]]),
    $c('st-gelecek-tahsilat-yeni-alacak-degil', [$s('Ahmet Yılmaz yarın 100 bin verecek')],
        ['draft_kind_not' => ['debt_note', 'collection'], 'reply_not' => ['400.000']]),
    $c('st-musteri-borcu', [$s('Ahmet Yılmaz ne kadar borçlu')],
        ['reply_has' => ['300.000'], 'no_new_draft' => true]),

    // ---------------------------------------------------------------- Anlaşmasız satış
    $c('st-anlasmasiz-satis-yeni-musteri', [$s("Veli Kaya'ya 500 tuğla sattım 60 bin, parasını sonra verecek")],
        ['draft' => ['kind' => ['sale', 'trade_sale'], 'amount' => 60000]]),
];
