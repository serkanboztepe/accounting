<?php

/*
 * Yalın birim fiyat = fiyat öğretimi (31.10, genel kural): "kepçe saati 1400" gibi KİŞİ / MİKTAR / FİİL olmayan birim fiyat "hatırlayayım mı?"
 * diye sorulur — sektör fark etmez. İstisnalar: birim fiyatı olmayan tutar gider kalır, stoklu işletmede listedeki ürünün fiyatı ürün ayarıdır.
 */

$s = fn (string $text, array $expect = []) => $expect === [] ? ['send' => $text] : ['send' => $text, 'expect' => $expect];
$c = fn (string $id, string $firm, array $steps, array $expect = []) => ['id' => $id, 'firm' => $firm, 'group' => 'capability',
    'steps' => $steps, 'expect' => $expect];

return [
    // Hizmet veren, sektörü farklı: nakliye seferi fiyatı → hatırla, sonra o fiyattan hesap.
    $c('fy-sefer-fiyati-ciftci', 'erkan', [
        $s('nakliye seferi 3000', ['draft' => ['kind' => 'remember'], 'reply_any' => ['hatırla']]), $s('evet'),
    ], ['saved' => ['ledger' => 0, 'sales' => 0, 'expenses' => 0]]),
    $c('fy-sut-litresi', 'erkan', [$s('süt litresi 18')], ['draft' => ['kind' => 'remember'], 'saved' => ['ledger' => 0, 'expenses' => 0]]),
    // Birim fiyat yok → gider (kural karışmaz).
    $c('fy-kira-gider', 'muteahhit', [$s('kira 20000')], ['draft_kind_not' => ['remember']]),
    $c('fy-mazot-gider', 'muteahhit', [$s('10000 mazot')], ['draft' => ['kind' => 'expense', 'amount' => 10000]]),
    // Stoklu işletme: listedeki ürünün alış fiyatı → ürün ayarı (hafıza değil).
    $c('fy-un-cuvali-urun-ayari', 'akkus', [$s('un çuvalı 900')], ['draft' => ['kind' => 'stock_setting'], 'draft_kind_not' => ['remember']]),
];
