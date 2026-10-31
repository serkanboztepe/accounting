<?php

/*
 * Hafriyat deneme seti (31.10, Alim Tok demosu) — firma "hafriyat" (sektör excavation, stoklu, anlaşma yönü sale = "alım derse alım").
 * "X'ten hafriyat aldım" = X'e iş yaptık (satış, hizmet, stoğa girmez); hesaplanan tutara ölçek sorusu yok; malzeme alışı gerçek alış.
 */

$c = fn (string $id, array $steps, array $expect = []) => ['id' => $id, 'firm' => 'hafriyat', 'group' => 'capability',
    'steps' => $steps, 'expect' => $expect];
$s = fn (string $text, array $expect = []) => $expect === [] ? ['send' => $text] : ['send' => $text, 'expect' => $expect];

return [
    $c('hf-mikap-aldim-satis', [$s("Ali boztepeden 1300 mükap aldım 130 tl")],
        ['draft' => ['kind' => ['sale', 'trade_sale'], 'party_id' => '@party:Ali Boztepe', 'amount' => 169000], 'reply_not' => ['TL mi', 'borçlanırsın']]),
    $c('hf-kepce-saat', [$s('Ali boztepeye 4 saat kepçe saati 1000tl')],
        ['draft' => ['kind' => ['sale', 'trade_sale'], 'party_id' => '@party:Ali Boztepe', 'amount' => 4000], 'reply_not' => ['TL mi']]),
    $c('hf-sefer-toprak', [$s("Abdullah Uçar'ın şantiyesinden 20 sefer toprak çıkardık seferi 2500")],
        ['draft' => ['kind' => ['sale', 'trade_sale'], 'party_id' => '@party:Abdullah Uçar', 'amount' => 50000]]),
    $c('hf-borclu-hafriyat', [$s('Abdullah uçar m3 130 tl den 1000m3 borçlu')],
        ['draft' => ['kind' => ['sale', 'trade_sale', 'debt_note'], 'party_id' => '@party:Abdullah Uçar', 'amount' => 130000], 'reply_not' => ['borçlanırsın']]),
    $c('hf-fabrikadan-tugla-alis', [$s('Akdağ tuğladan 10 bin tuğla aldım tanesi 6 lira')],
        ['draft' => ['kind' => ['purchase', 'trade_purchase'], 'party_id' => '@party:Akdağ Tuğla Fabrikası', 'amount' => 60000]]),
    $c('hf-mazot-gider', [$s('kepçeye 8000 mazot')],
        ['draft' => ['kind' => 'expense', 'amount' => 8000]]),
    $c('hf-tugla-satis-anlasma', [$s("Ali Boztepe'ye 10.000 tuğla 1 milyondan anlaştık sattım")],
        ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale', 'party_id' => '@party:Ali Boztepe'], 'reply_has' => ['1.000.000']]),
    // Alim Tok'a giden tanıtım mesajındaki çek cümlesi — firma 14 ile aynı ayarlı "tok" firmasında (31.10).
    ['id' => 'tok-cek-aldim', 'firm' => 'tok', 'group' => 'capability', 'seed' => 'tok-ali-borclu',
        'steps' => [$s("Ali Boztepe'den 15.12 vadeli 200 bin çek aldım", ['draft' => ['kind' => 'collection', 'party_id' => '@party:Ali Boztepe',
            'amount' => 200000], 'reply_has' => ['15.12'], 'reply_not' => ['verdin', 'ödedin']]), $s('evet')],
        'expect' => ['balance' => ['@party:Ali Boztepe' => 100000]]],
    // Kart açılır mı (31.10): anlaşmasız iş = düz satış (kart yok); fiyat anlaşması = kalem kartı + teslimde düşer; kişisiz fiyat = sorulur.
    ['id' => 'tok-kepce-1500', 'firm' => 'tok', 'group' => 'capability',
        'steps' => [$s("Ali'ye 4 saat kepçe, saati 1500", ['draft' => ['kind' => 'sale', 'party_id' => '@party:Ali Boztepe', 'amount' => 6000],
            'reply_not' => ['TL mi', 'ürün kartı']]), $s('evet')],
        'expect' => ['balance' => ['@party:Ali Boztepe' => 6000]]],
    ['id' => 'tok-hafriyat-140-anlasma', 'firm' => 'tok', 'group' => 'capability',
        'steps' => [
            $s("Ali'yle hafriyatın m3'ünü 140'tan anlaştık", ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale',
                'party_id' => '@party:Ali Boztepe'], 'reply_has' => ['140']]),
            $s('evet'),
            $s("Ali'ye 500 m3 hafriyat yaptık", ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'], 'reply_has' => ['70.000']]),
            $s('evet'),
        ], 'expect' => ['balance' => ['@party:Ali Boztepe' => 70000]]],
    ['id' => 'tok-kepce-saati-kisisiz', 'firm' => 'tok', 'group' => 'capability',
        'steps' => [$s('kepçe saati 1500')], 'expect' => ['saved' => ['ledger' => 0, 'sales' => 0]]],
    // Önceden söylenen saat fiyatı (kullanıcı 31.10: "5 saat kepçe çalıştı dediğinde önceden söylediyse 5 × 1400 yapsın").
    ['id' => 'tok-kepce-anlasma-1400', 'firm' => 'tok', 'group' => 'capability',
        'steps' => [
            $s("Ali'yle kepçe saatini 1400'den anlaştık", ['draft' => ['kind' => 'agreement', 'agreement_direction' => 'sale'], 'reply_has' => ['1.400']]),
            $s('evet'),
            $s("Ali'ye 5 saat kepçe çalıştı", ['draft' => ['kind' => 'agreement_delivery', 'delivery_direction' => 'sale'], 'reply_has' => ['7.000']]),
            $s('evet'),
        ], 'expect' => ['balance' => ['@party:Ali Boztepe' => 7000]]],
    ['id' => 'tok-kepce-hafiza-1400', 'firm' => 'tok', 'group' => 'capability',
        'steps' => [
            $s('kepçe saati 1400, unutma'),
            $s('evet'),
            $s("Ali'ye 5 saat kepçe çalıştı", ['draft' => ['amount' => 7000], 'reply_has' => ['7.000']]),
            $s('evet'),
        ], 'expect' => ['balance' => ['@party:Ali Boztepe' => 7000]]],
    // Fiyat değişince hafıza güncellenir, eski fiyat kullanılmaz (31.10).
    ['id' => 'tok-kepce-hafiza-guncelle', 'firm' => 'tok', 'group' => 'capability',
        'steps' => [
            $s('kepçe saati 1400, unutma'), $s('evet'),
            $s('kepçe saati artık 1500'), $s('evet'),
            $s("Ali'ye 5 saat kepçe çalıştı", ['draft' => ['amount' => 7500], 'reply_has' => ['7.500'], 'reply_not' => ['7.000']]),
            $s('evet'),
        ], 'expect' => ['balance' => ['@party:Ali Boztepe' => 7500]]],
    $c('hf-dokum', [$s('Ali Boztepe döküm')],
        ['reply_not' => ['Kimin hesap'], 'no_new_draft' => true]),
];
