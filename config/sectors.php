<?php

/**
 * İşletme türü (sektör) — asistanın karşısındakinin işini bilmesi için (plan once-anla-sonra-kaydet.md, Faz D).
 * Paketten (modül seti, hub_firms.settings.profile) AYRI: Taner'in paketi alacak-verecek, işi inşaat.
 * Hub → firma → Modüller penceresinde seçilir; yeni sektör buraya eklenir (anahtar İngilizce, ad Türkçe).
 * `knowledge`: asistanın bağlamına giren kısa iş bilgisi — kayıt türünü doğru anlaması için, kural değil.
 */
return [
    'construction' => [
        'label' => 'İnşaat / müteahhit',
        'knowledge' => 'Daire/konut inşaatı yapar. Ustalar (sıva, boya, elektrik, kaba inşaat) iş bitince ya da hakedişle ödenir; '
            . 'işten önce verilen para avanstır. "Bağlantı" = fiyatı sabitlenmiş, peşin ödenen mal alımı (beton, demir); mal geldikçe düşer. '
            . 'Asansör, kapı, pencere, dolap gibi işler önce satın alınır, sonra gelir/takılır — ödeme teslim demek değildir. '
            . 'Ödemeler sık sık vadeli çekle yapılır; çek teslimi kanıtlamaz. Hatır çeki = birinin kendi çekini bize vermesi, karşılığı ona borçtur. '
            . 'Daire karşılığı iş/mal (takas) olabilir.',
    ],
    'architecture' => [
        'label' => 'Mimarlık / mühendislik',
        'knowledge' => 'Müşteriye proje çizimi, ruhsat, EKB, kat irtifakı gibi hizmetler satar (satış); işten önce kapora alır, teslimde kalanını. '
            . 'Giderleri yakıt, ofis, harç ve dışarıya yaptırılan işlerdir.',
    ],
    'bakery' => [
        'label' => 'Fırın / pastane',
        'knowledge' => 'Şubeler her akşam kasa girer: nakit + pos = o günün peşin satışı. Un, yağ, susam gibi hammadde tedarikçiden alınır '
            . '(çoğu veresiye, sonra ödenir). Personele maaş ve avans verilir.',
    ],
    'hardware' => [
        'label' => 'Nalbur / yapı market',
        'knowledge' => 'Ürünlü alış-satış yapar; müşteriye veresiye satar ve tahsilat yapar; toptancıdan mal alır.',
    ],
    'tyre_shop' => [
        'label' => 'Lastikçi / oto servis',
        'knowledge' => 'Lastik satar, montaj-balans hizmeti verir; veresiye müşterisi olur; toptancıdan lastik alır.',
    ],
    'farm' => [
        'label' => 'Çiftçi / hayvancı',
        'knowledge' => 'Hayvan, yem, saman, süt ve peynirle uğraşır; ürünle takas yaygındır ("peynir verdim, hesaptan düşülecek"); '
            . 'tüccara ürün satar; çoban/işçiye yevmiye ya da maaş öder.',
    ],
    'other' => [
        'label' => 'Diğer',
        'knowledge' => null,
    ],
];
