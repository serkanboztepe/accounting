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
        'label' => 'Çiftçi / tarım / hayvancılık',
        'knowledge' => 'Tarla (ekim, gübre, ilaç, mazot, hasat) ya da hayvan (yem, saman, süt, peynir) işiyle uğraşır; ürünle takas yaygındır '
            . '("peynir verdim, hesaptan düşülecek"); tüccara/kooperatife ürün satar, parası sonra gelebilir; çoban/işçiye yevmiye ya da maaş öder.',
    ],
    'village_produce' => [
        'label' => 'Köy ürünleri satışı',
        'knowledge' => 'Bal, ceviz, peynir, tereyağı gibi köy ürünlerini toplayıp satar; üreticiden alır (çoğu zaman sonra öder), müşteriye '
            . 'kargo ya da elden satar, bir kısmı veresiye; ürün henüz teslim edilmediyse alınan para kaporadır.',
    ],
    'photography' => [
        'label' => 'Fotoğrafçı / medya',
        'knowledge' => 'Düğün, nişan, etkinlik çekimi ve video işi yapar; çekimden önce kapora alır, kalanını teslimde; çekim tarihleri '
            . 'önemlidir (hatırlatma); ekipman, baskı/albüm ve yardımcı ödemeleri gideridir.',
    ],
    'other' => [
        'label' => 'Diğer',
        'knowledge' => null,
    ],
];
