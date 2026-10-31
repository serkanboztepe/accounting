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
    $c('hf-dokum', [$s('Ali Boztepe döküm')],
        ['reply_not' => ['Kimin hesap'], 'no_new_draft' => true]),
];
