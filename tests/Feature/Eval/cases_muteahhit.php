<?php

/*
 * Yeni müteahhit müşterisi deneme seti (30.10) — firma "yeni_muteahhit", tohum "ym-temel" (cases.php).
 * Her şekilde yazılmış gündelik müteahhit mesajları: gider, adsız usta, anlaşma → iş → ödeme, yön, çek, satış, proje,
 * düzeltme, sorular, personel. Grup "capability": hedef davranış — düşenler raporlanır, onaylananlar regression'a taşınır.
 *
 *   EVAL_ONLY=ym-… php artisan test --group=eval --filter=test_capability
 */

$c = fn (string $id, array $steps, array $expect = []) => ['id' => $id, 'firm' => 'yeni_muteahhit', 'group' => 'capability', 'seed' => 'ym-temel',
    'steps' => $steps, 'expect' => $expect];
$s = fn (string $text, array $expect = []) => $expect === [] ? ['send' => $text] : ['send' => $text, 'expect' => $expect];

return [
    // ---------------------------------------------------------------- A. Gider
    $c('ym-gider-mazot-proje', [$s('Cumhuriyet şantiyesine 5 bin mazot aldım')],
        ['draft' => ['kind' => 'expense', 'amount' => 5000, 'project_id' => '@project:Cumhuriyet Apartmanı'], 'reply_not' => ['kime']]),
    $c('ym-gider-kira-yazim-bozuk', [$s('santiye kirasi 20bin odendi')],
        ['draft' => ['kind' => 'expense', 'amount' => 20000], 'reply_not' => ['kime']]),
    $c('ym-gider-isci-yemegi', [$s('işçilere 3500 yemek parası verdim')],
        ['draft' => ['kind' => 'expense', 'amount' => 3500], 'reply_not' => ['Hangi usta']]),
    $c('ym-gider-coklu', [$s('mazot 5 bin, şantiye kirası 15 bin, yemek 2 bin')],
        ['draft' => ['kind' => 'expense'], 'reply_any' => ['Sırada', '➡️', '15.000'], 'reply_not' => ['mı?']]),
    $c('ym-gider-bir-bucuk-milyon', [$s('bir buçuk milyon hafriyat parası ödedim')],
        ['draft' => ['amount' => 1500000]]),
    $c('ym-gider-ofis-faturasi', [$s('ofis elektrik faturası 3 bin')],
        ['draft' => ['kind' => 'expense', 'amount' => 3000], 'reply_not' => ['kime']]),

    // ---------------------------------------------------------------- B. Adsız usta (kullanıcı kararı 30.10)
    $c('ym-adsiz-karocu-kucuk-gider', [$s('Karocuya 50 bin ödedim')],
        ['draft' => ['kind' => 'expense', 'amount' => 50000], 'reply_not' => ['kime', 'Hangi usta']]),
    $c('ym-adsiz-hafriyatci-buyuk-cari', [$s('Hafriyatçıya 250 bin verdim')],
        ['draft' => ['kind' => 'payment', 'amount' => 250000], 'reply_has' => ['Hafriyatçı'], 'reply_not' => ['Gider']]),
    $c('ym-adsiz-ustaya-ad-sorar', [$s('Ustaya 20 bin verdim')],
        ['draft_kind_not' => 'expense', 'reply_any' => ['Hangi usta', 'adını']]),
    $c('ym-adsiz-asansorcu-osmani-sorar', [$s('Asansörcüye 300 bin ödedim')],
        ['reply_has' => ['Asansör Osman']]),
    $c('ym-adsiz-asansorcu-yazim-osman', [$s('asansorcuya 1m verdim')],
        ['reply_has' => ['Asansör Osman'], 'draft' => ['amount' => 1000000]]),
    $c('ym-adsiz-asansorcu-osman-cevap', [$s('Asansörcüye 300 bin ödedim', ['reply_has' => ['Asansör Osman']]), $s("evet Osman'a")],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Asansör Osman', 'amount' => 300000]]),
    $c('ym-adsiz-odenmedi-kime', [$s('Boyacı 80 bin iş yaptı, parasını vermedim')],
        ['draft' => ['kind' => 'purchase', 'amount' => 80000], 'reply_any' => ['Kime', 'adını', 'Boyacı']]),

    // ---------------------------------------------------------------- C. Anlaşma → iş → ödeme
    $c('ym-anlasma-yeni-miktarli', [$s("Demirci Hasan ile 20 ton demiri tonu 28 bin'den anlaştım")],
        ['draft' => ['kind' => 'agreement', 'party_id' => '@party:Demirci Hasan']]),
    $c('ym-anlasma-goturu-yeni-cari', [$s("Mehmet elektrikçi ile Barbaros'un elektrik tesisatını 400 bine götürü anlaştık")],
        ['draft' => ['kind' => 'agreement'], 'reply_has' => ['400.000']]),
    $c('ym-anlasma-beton-geldi', [$s('Kuşaktan 40 m3 beton geldi')],
        ['draft' => ['kind' => 'agreement_delivery', 'party_id' => '@party:Kuşak Beton'], 'reply_has' => ['40']]),
    $c('ym-anlasma-sivanin-bir-kismi', [$s('Ali usta 300 m2 sıva yaptı')],
        ['draft' => ['kind' => 'agreement_delivery', 'party_id' => '@party:Ali Usta'], 'reply_has' => ['300']]),
    $c('ym-anlasma-yarisini-bitirdi', [$s('Ali usta sıvanın yarısını bitirdi')],
        ['draft' => ['kind' => 'agreement_delivery', 'party_id' => '@party:Ali Usta'], 'reply_has' => ['500']]),
    $c('ym-anlasma-adli-odeme-hangisi', [$s('Ali ustaya 50 bin verdim')],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Ali Usta', 'amount' => 50000], 'draft_kind_not' => 'agreement_delivery']),
    $c('ym-anlasma-odeme-is-sayilmaz', [$s('Sıva işçiliğine 50 bin ödedim', ['draft_kind_not' => 'agreement_delivery']), $s("Ali ustanın anlaşmasından düş")],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Ali Usta', 'amount' => 50000]]),
    $c('ym-anlasma-ne-kaldi', [$s('Ali ustayla anlaşmada ne kaldı')],
        ['reply_has' => ['Ali Usta'], 'no_new_draft' => true]),

    // ---------------------------------------------------------------- D. Cari: alış, ödeme, yön, yarım bilgi
    $c('ym-alis-odenmedi-yeni-usta', [$s('Mehmet usta 30 bin elektrik işi yaptı, ödemedim')],
        ['draft' => ['kind' => 'purchase', 'amount' => 30000, 'paid' => false]]),
    $c('ym-odeme-borca', [$s("Demirci Hasan'a 50 bin ödedim")],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Demirci Hasan', 'amount' => 50000], 'reply_not' => ['hangi iş', 'ne için']]),
    $c('ym-yon-osman-aldi', [$s('Osman 200 bin aldı')],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Asansör Osman', 'amount' => 200000]]),
    $c('ym-yon-iade-geldi', [$s('Kuşak betondan 10 bin iade geldi')],
        ['draft' => ['kind' => 'collection', 'party_id' => '@party:Kuşak Beton', 'amount' => 10000]]),
    $c('ym-gelecek-zaman-odeme-degil', [$s("Demirci Hasan'a yarın 40 bin vereceğim")],
        ['draft' => ['kind' => 'reminder'], 'reply_has' => ['yeni borç yazmıyorum'], 'reply_not' => ['ödedin', 'neden']]),
    $c('ym-tutarsiz-odeme-sorar', [$s("Osman'a verdim")],
        ['reply_any' => ['ne kadar', 'tutar', 'Kaç', 'kaç']]),
    $c('ym-kimsiz-odeme-sorar', [$s('200 bin ödedim')],
        ['reply_any' => ['kime', 'Kime', 'ne için']]),

    // ---------------------------------------------------------------- E. Çek
    $c('ym-cek-taserona-aylik', [$s("Ali ustaya 3 çek verdim 100'er bin, kasımdan itibaren her ay")],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Ali Usta', 'amount' => 300000]]),
    $c('ym-cek-tek-vadeli', [$s('Kuşak betona 15.12 vadeli 250 bin çek verdim')],
        ['draft' => ['kind' => 'payment', 'party_id' => '@party:Kuşak Beton', 'amount' => 250000]]),

    // ---------------------------------------------------------------- F. Satış / tahsilat
    $c('ym-tahsilat-taksit', [$s('Ahmet Yılmaz 250 bin taksit ödedi')],
        ['draft' => ['kind' => 'collection', 'party_id' => '@party:Ahmet Yılmaz', 'amount' => 250000]]),
    $c('ym-daire-satis-yeni-musteri', [$s("Barbaros'tan 5 numaralı daireyi Veli Kaya'ya 4 milyona sattım")],
        ['draft' => ['kind' => 'sale', 'amount' => 4000000]]),
    $c('ym-musteri-borcu-sorgu', [$s('Ahmet Yılmaz ne kadar borçlu')],
        ['reply_has' => ['2.000.000'], 'no_new_draft' => true]),

    // ---------------------------------------------------------------- G. Proje
    $c('ym-proje-yeni-adi', [$s("Yeni şantiye Kıbrıs Şehitleri'ne 5 bin mazot")],
        ['draft' => ['kind' => 'expense', 'amount' => 5000], 'reply_has' => ['Kıbrıs']]),
    $c('ym-proje-kisa-ad', [$s('Cumhuriyete 12 bin hırdavat aldım')],
        ['draft' => ['kind' => 'expense', 'amount' => 12000, 'project_id' => '@project:Cumhuriyet Apartmanı']]),
    $c('ym-proje-numara-secimi', [$s('Şantiyeye 8 bin nakliye ödedim', ['reply_has' => ['Hangi proje']]), $s('2')],
        ['saved' => ['expenses' => 1]]),

    // ---------------------------------------------------------------- H. Düzeltme
    $c('ym-duzelt-tutar', [$s('Cumhuriyete mazot 5 bin'), $s('yanlış yazdım 4500 olacak')],
        ['draft' => ['amount' => 4500], 'saved' => ['expenses' => 0]]),
    $c('ym-duzelt-proje', [$s('Cumhuriyete mazot 5 bin'), $s("Barbaros'a yaz")],
        ['draft' => ['project_id' => '@project:Barbaros Sitesi', 'amount' => 5000]]),

    // ---------------------------------------------------------------- I. Sorular (rakamlar tutmalı)
    $c('ym-sorgu-proje-maliyeti', [$s("Cumhuriyet'in maliyeti ne")],
        ['reply_has' => ['Cumhuriyet'], 'no_new_draft' => true]),
    $c('ym-sorgu-osmana-verilen', [$s("Osman'a ne kadar verdim")],
        ['reply_has' => ['500.000'], 'no_new_draft' => true]),
    $c('ym-sorgu-borclarim', [$s('kime ne kadar borcum var')],
        ['reply_has' => ['Demirci Hasan', '120.000'], 'no_new_draft' => true]),
    $c('ym-sorgu-bu-ay-gider', [$s('bu ay ne harcadım')],
        ['no_new_draft' => true, 'reply_not' => ['anlayamadım']]),

    // ---------------------------------------------------------------- J. Personel
    $c('ym-personel-bekci-maas', [$s("bekçi Hüseyin'e 25 bin maaş verdim")],
        ['draft' => ['kind' => ['salary', 'payment'], 'amount' => 25000]]),
    $c('ym-yevmiye-uc-isci', [$s("3 işçiye 1500'den yevmiye verdim")],
        ['draft' => ['kind' => 'expense', 'amount' => 4500], 'reply_not' => ['adlarını', 'isimlerini']]),
];
