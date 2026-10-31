<?php

/*
 * Yön işletmenin işinden çıkar (31.10, genel kural): Türkçede ad çoğu zaman eksiz ("Ayşe Kara iş yaptım", "Uğur Altıntaş bugün 7 saat
 * kepçe çalıştı") — ek yokluğu yön belirsizliği değil. İşletmenin sattığı iş → satış, dışarıdan aldığı iş → alış (sorma); ikisi de
 * olabiliyorsa sorar. Aynı kalıp müteahhitte alış olmalı (bozulmamalı).
 */

$s = fn (string $text, array $expect = []) => $expect === [] ? ['send' => $text] : ['send' => $text, 'expect' => $expect];
$c = fn (string $id, string $firm, array $steps, array $expect = []) => ['id' => $id, 'firm' => $firm, 'group' => 'capability',
    'steps' => $steps, 'expect' => $expect];

return [
    // Alim'in gerçek cümleleri (31.10 20:00, firma 14): soru sormadan satış.
    $c('yn-alim-ugur-kepce', 'tok', [$s('Uğur Altıntaş bugün 7 saat kepçe çalıştı saati 3000 tl den')],
        ['draft' => ['kind' => 'sale', 'amount' => 21000], 'ready' => true, 'reply_not' => ['şantiye']]),
    $c('yn-alim-ugur-sefer', 'tok', [$s('Uğur Altıntaş a bugün 25 sefer stabilize nakliyesi yapıldı seferi 1000 TL’den')],
        ['draft' => ['kind' => 'sale', 'amount' => 25000], 'ready' => true]),
    $c('yn-alim-eksiz-is-yaptim', 'tok', [$s('Ayşe Kara 15 bin hafriyat işi yaptım')],
        ['draft' => ['kind' => 'sale', 'amount' => 15000], 'ready' => true]),
    // Müteahhit: aynı kalıp ALIŞ (kepçeciyi / ustayı dışarıdan tutar) — bozulmamalı.
    $c('yn-muteahhit-kepceci', 'yeni_muteahhit', [$s('Demirci Hasan bugün 7 saat kepçe çalıştı saati 2000')],
        ['draft_kind_not' => ['sale', 'trade_sale'], 'reply_not' => ['alacağın']]),
    $c('yn-muteahhit-usta', 'yeni_muteahhit', [$s('Ali Usta bugün 3 gün çalıştı yevmiyesi 2500')],
        ['draft_kind_not' => ['sale', 'trade_sale'], 'reply_not' => ['alacağın']]),
    // Mimar: eksiz ad + kendi işi → satış.
    $c('yn-mimar-eksiz-proje', 'ferhat', [$s('Bahadır Pir 40 bin proje çizimi yaptım')],
        ['draft' => ['kind' => 'sale', 'amount' => 40000]]),
    // Stoklu, hem alıp hem satan: un hem Ak Un'dan alınır hem satılır → yön sorulmalı ya da açık fiil yoksa tahmin etmemeli.
    $c('yn-toptan-iki-yon-sorar', 'stok', [$s('Mehmet Kaya 50 çuval un 850')],
        ['asks' => true]),
];
