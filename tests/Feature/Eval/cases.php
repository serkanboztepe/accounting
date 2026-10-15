<?php

/*
 * Asistan deneme seti (Faz 0, 2026-10-16) — GERÇEK vakalardan. Gerçek AI ile uçtan uca (webhook → AI → korumalar → taslak).
 *
 *   group "regression"  bugün doğru olan davranış — hep geçmeli (bozulma yakalanır)
 *   group "capability"  Faz 1–5 hedefi — bugün kalabilir; geçtikçe regression'a taşınır
 *
 * steps:  ['send' => 'mesaj'] | ['pending' => [taslak alanları]] (onay/iptal/düzeltme vakaları için hazır taslak)
 * expect: draft    => son bekleyen taslağın alanları ('@party:Ad', '@project:Ad', '@product:Ad' gerçek id'ye çevrilir;
 *                     liste verilirse değerlerden biri yeter)
 *         reply_has / reply_not => cevapta geçmeli / geçmemeli (büyük-küçük harf duyarsız)
 *         no_new_draft => bu vakada yeni taslak açılmamalı
 *         saved    => ['ledger' => n, 'sales' => n, 'expenses' => n] onaydan sonra bu vakada oluşan kayıt sayısı
 */

return [
    'firms' => [
        'akkus' => [
            'profile' => 'trade', 'modules' => ['projects' => true, 'report_monthly' => true],
            'projects' => ['İzzetpaşa', 'Araştırma', 'Ergenekon'],
            'parties' => ['Abdullah Günhan' => ['is_employee' => true, 'salary_amount' => 50000, 'salary_day' => 15], 'Boztepe Ticaret' => []],
            'products' => [['Un', 'çuval']], 'categories' => ['Yakıt', 'Kira'],
        ],
        'erkan' => [
            'profile' => 'alacak_verecek', 'modules' => [],
            'parties' => ['Zülfikar' => [], 'Recep Demirci' => [], 'Hüseyin' => []], 'categories' => ['Yakıt'],
        ],
        'ferhat' => [
            'profile' => 'mimar', 'modules' => ['projects' => true],
            'projects' => ['EROL ALTINER'],
            'parties' => ['BAHADIR PİR' => [], 'FUAT ALKAN' => [], 'RAMADA OTEL' => [], 'YAKUP TAŞ' => []],
        ],
        'muteahhit' => [
            'profile' => 'muteahhit', 'modules' => ['projects' => true],
            'projects' => ['Cumhuriyet', 'Bahçelievler'],
            'parties' => ['Abdullah Uçar' => [], 'Sinan' => [], 'Ali Usta' => [], 'Kuşak Beton' => [], 'Serkan' => []],
            'categories' => ['Yakıt', 'Kira'],
        ],
        'stok' => [
            'profile' => 'trade', 'modules' => [],
            'parties' => ['Ak Un' => [], 'Mehmet Kaya' => []],
            'products' => [['Un', 'çuval'], ['Maya', 'paket']], 'categories' => ['Yakıt'],
        ],
    ],

    'cases' => [
        // ---------------------------------------------------------------- Akkuş (şube, kasa, ciro, personel)
        ['id' => 'akkus-kasa-sube', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => "Ergenekon kasa\nNakit 5900\nPos 21540"]],
            'expect' => ['draft' => ['kind' => 'trade_sale', 'project_id' => '@project:Ergenekon', 'cash_amount' => 5900, 'card_amount' => 21540]]],
        ['id' => 'akkus-kasa-subesiz-sorar', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Kasa nakit 5000 pos 12000']],
            'expect' => ['draft' => ['kind' => 'trade_sale'], 'reply_has' => ['Hangi şube']]],
        ['id' => 'akkus-K1-acik-taslakta-kasa-iptal-sayilmaz', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Boztepe ticaretten 5 çuval un aldım 7000'], ['send' => "Ergenekon kasa\nNakit 5900\nPos 21540"]],
            'expect' => ['draft' => ['kind' => 'trade_sale', 'cash_amount' => 5900], 'reply_not' => ['iptal edildi']]],
        ['id' => 'akkus-K4-kasa-arti-gider', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Akkuş ergenekon 5000 tl nakit 19483 tl kart 1630 tl gider']],
            'expect' => ['draft' => ['kind' => 'trade_sale', 'amount' => 24483], 'reply_has' => ['1.630']]],
        ['id' => 'akkus-dun-sube-ciro', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Dün ki ergenekon ciro ne kadar']],
            'expect' => ['reply_has' => ['Ergenekon', 'Ciro'], 'no_new_draft' => true]],
        ['id' => 'akkus-tarihli-sube-kasa', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => '14.10.2026 araştırma kasa ne kadar']],
            'expect' => ['reply_has' => ['14 Ekim', 'Araştırma'], 'no_new_draft' => true]],
        ['id' => 'akkus-bugun-ciro', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Bugün cirosu ne kadar']],
            'expect' => ['reply_has' => ['Ciro'], 'reply_not' => ['Ekim 2026 sonucu'], 'no_new_draft' => true]],
        ['id' => 'akkus-K2-carisiz-cek-odendi', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => '105.000 çek ödendi']],
            'expect' => ['reply_has' => ['kime']]],
        ['id' => 'akkus-avans-aldi-odeme', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Abdullah Günhan 20.000 TL maaş avansı aldı']],
            'expect' => ['draft' => ['kind' => 'payment', 'party_id' => '@party:Abdullah Günhan', 'amount' => 20000, 'payment_purpose' => 'advance']]],
        ['id' => 'akkus-personel-amacsiz-odeme-sorar', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Abdullaha 15 bin verdim']],
            'expect' => ['reply_has' => ['maaş', 'avans']]],
        ['id' => 'akkus-maas-verdim', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Abdullahın maaşını verdim']],
            'expect' => ['draft' => ['kind' => 'salary', 'party_id' => '@party:Abdullah Günhan'], 'reply_has' => ['50.000']]],
        ['id' => 'akkus-bugun-gider', 'firm' => 'akkus', 'group' => 'regression',
            'steps' => [['send' => 'Toplam gider ne kadar bugün']],
            'expect' => ['no_new_draft' => true, 'reply_not' => ['anlayamadım']]],

        // ---------------------------------------------------------------- Erkan (avans, alış, yön)
        ['id' => 'erkan-musteri-avansi', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => 'Zülfikar 3 bin verdi, peyniri sonra vereceğim']],
            'expect' => ['draft' => ['kind' => 'collection', 'party_id' => '@party:Zülfikar', 'payment_purpose' => 'advance', 'amount' => 3000]]],
        ['id' => 'erkan-alis-cariden', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "Recep Demirci'den 12 bin yem aldım"]],
            'expect' => ['draft' => ['kind' => 'purchase', 'party_id' => '@party:Recep Demirci', 'amount' => 12000]]],
        ['id' => 'erkan-isimsiz-yemci-sorar', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => 'yemciden 12 bin yem aldım']],
            'expect' => ['reply_has' => ['?'], 'draft' => ['kind' => ['purchase', 'expense']]]],
        ['id' => 'erkan-gelecek-zaman-borc', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "Recep Demirci'ye 67 bin vereceğim"]],
            'expect' => ['draft' => ['kind' => 'debt_note', 'debt_side' => 'payable', 'amount' => 67000]]],

        // ---------------------------------------------------------------- Ferhat (mimar — değişmemeli)
        ['id' => 'ferhat-ekb-ekle-satis', 'firm' => 'ferhat', 'group' => 'regression',
            'steps' => [['send' => 'Bahadır pire 20000 Gönül Kaya Ekb belgesi ekle']],
            'expect' => ['draft' => ['kind' => 'sale', 'party_id' => '@party:BAHADIR PİR', 'amount' => 20000], 'reply_not' => ['Hangi proje']]],
        ['id' => 'ferhat-tahsilat', 'firm' => 'ferhat', 'group' => 'regression',
            'steps' => [['send' => 'Fuat Alkan 75000 tl ödedi']],
            'expect' => ['draft' => ['kind' => 'collection', 'party_id' => '@party:FUAT ALKAN', 'amount' => 75000]]],
        ['id' => 'ferhat-ekstre', 'firm' => 'ferhat', 'group' => 'regression',
            'steps' => [['send' => 'Ramada hesap extresi']],
            'expect' => ['no_new_draft' => true, 'reply_has' => ['RAMADA']]],
        ['id' => 'ferhat-toplam-cari', 'firm' => 'ferhat', 'group' => 'regression',
            'steps' => [['send' => 'Toplam cari nedir']],
            'expect' => ['no_new_draft' => true]],

        // ---------------------------------------------------------------- Müteahhit
        ['id' => 'muteahhit-fiilsiz-maliyet', 'firm' => 'muteahhit', 'group' => 'regression',
            'steps' => [['send' => 'Abdullah Uçar 2 milyon kaba inşaat']],
            'expect' => ['draft' => ['kind' => 'purchase', 'party_id' => '@party:Abdullah Uçar', 'amount' => 2000000]]],
        ['id' => 'muteahhit-kira-gider', 'firm' => 'muteahhit', 'group' => 'regression',
            'steps' => [['send' => 'kira 20 bin ödendi']],
            'expect' => ['draft' => ['kind' => 'expense', 'amount' => 20000]]],
        ['id' => 'muteahhit-baglanti-avans', 'firm' => 'muteahhit', 'group' => 'regression',
            'steps' => [['send' => "Sinan'a yapılan 2026 bağlantısı 2.500.000 ödendi"]],
            'expect' => ['draft' => ['kind' => 'payment', 'party_id' => '@party:Sinan', 'payment_purpose' => 'advance']]],
        ['id' => 'muteahhit-borc-aldim-odeme-degil', 'firm' => 'muteahhit', 'group' => 'regression',
            'steps' => [['send' => "Serkan'dan 10.000 daha borç aldım"]],
            'expect' => ['draft' => ['kind' => ['debt_note', 'collection']], 'reply_not' => ['ödedin']]],
        ['id' => 'muteahhit-proje-adli-alis', 'firm' => 'muteahhit', 'group' => 'regression',
            'steps' => [['send' => 'Cumhuriyet için Ali usta sıva 30 bin']],
            'expect' => ['draft' => ['kind' => 'purchase', 'party_id' => '@party:Ali Usta', 'project_id' => '@project:Cumhuriyet']]],
        ['id' => 'muteahhit-alista-proje-sorar', 'firm' => 'muteahhit', 'group' => 'regression',
            'steps' => [['send' => "Kuşak Beton'dan 50 bin beton aldım"]],
            'expect' => ['draft' => ['kind' => 'purchase'], 'reply_has' => ['Hangi proje']]],

        // ---------------------------------------------------------------- Stoklu satış
        ['id' => 'stok-urunlu-alis', 'firm' => 'stok', 'group' => 'regression',
            'steps' => [['send' => "Ak Un'dan 20 çuval un aldım 17000"]],
            'expect' => ['draft' => ['kind' => 'trade_purchase', 'party_id' => '@party:Ak Un']]],
        ['id' => 'stok-soru', 'firm' => 'stok', 'group' => 'regression',
            'steps' => [['send' => 'un ne kadar kaldı']],
            'expect' => ['no_new_draft' => true, 'reply_has' => ['Un']]],
        ['id' => 'stok-ayar', 'firm' => 'stok', 'group' => 'regression',
            'steps' => [['send' => 'unu günde 3 çuval kullanıyorum 6 kala haber ver']],
            'expect' => ['draft' => ['kind' => 'stock_setting', 'product_id' => '@product:Un', 'daily_usage' => 3, 'reorder_level' => 6]]],
        ['id' => 'stok-personel-ayari', 'firm' => 'stok', 'group' => 'regression',
            'steps' => [['send' => "Mehmet Kaya yeni işçimiz maaşı 40 bin ayın 1'i"]],
            'expect' => ['draft' => ['kind' => 'employee_setting', 'party_id' => '@party:Mehmet Kaya', 'salary_amount' => 40000, 'salary_day' => 1]]],
        ['id' => 'stok-yevmiye', 'firm' => 'stok', 'group' => 'regression',
            'steps' => [['send' => "İşçi Hasan'a 2000 yevmiye verdim"]],
            'expect' => ['draft' => ['kind' => 'salary', 'amount' => 2000]]],

        // ---------------------------------------------------------------- Konuşma (onay / iptal / düzeltme / sohbet)
        ['id' => 'sohbet-selam-kayit-yok', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => 'merhaba']],
            'expect' => ['no_new_draft' => true]],
        ['id' => 'sohbet-tesekkur-kayit-yok', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => 'teşekkürler eline sağlık']],
            'expect' => ['no_new_draft' => true]],
        ['id' => 'onay-evet-kaydeder', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "Recep Demirci'den 12 bin yem aldım"], ['send' => 'evet']],
            'expect' => ['saved' => ['ledger' => 1]]],
        ['id' => 'iptal-kaydetmez', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "Recep Demirci'den 12 bin yem aldım"], ['send' => 'iptal']],
            'expect' => ['saved' => ['ledger' => 0]]],
        ['id' => 'duzeltme-cari-degisir', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "Recep Demirci'den 12 bin yem aldım"], ['send' => 'Recep değil Hüseyin']],
            'expect' => ['draft' => ['kind' => 'purchase', 'party_id' => '@party:Hüseyin', 'amount' => 12000], 'saved' => ['ledger' => 0]]],
        ['id' => 'duzeltme-tutar-degisir', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "Recep Demirci'den 12 bin yem aldım"], ['send' => '12 değil 14 bin']],
            'expect' => ['draft' => ['party_id' => '@party:Recep Demirci', 'amount' => 14000], 'saved' => ['ledger' => 0]]],
        ['id' => 'hatirlatma-saatli', 'firm' => 'erkan', 'group' => 'regression',
            'steps' => [['send' => "yarın 11'de bankaya gideceğim hatırlat"]],
            'expect' => ['draft' => ['kind' => 'reminder', 'event_time' => '11:00']]],

        // ---------------------------------------------------------------- YETENEK (Faz 1–5 hedefleri — bugün kalabilir)
        ['id' => 'F1-un-daha-once-aldik-mi', 'firm' => 'stok', 'group' => 'capability', 'seed' => 'stok-gecmis',
            'steps' => [['send' => 'Daha önce un aldık mı herhangi bir cariden']],
            'expect' => ['no_new_draft' => true, 'reply_has' => ['Ak Un']]],
        ['id' => 'F1-kime-ne-kadar-odendi', 'firm' => 'erkan', 'group' => 'capability', 'seed' => 'erkan-gecmis',
            'steps' => [['send' => 'Bu ay kime ne kadar ödedim']],
            'expect' => ['no_new_draft' => true, 'reply_has' => ['Recep', '8.000']]],
        ['id' => 'F1-bugunku-cirolar-sube-sube', 'firm' => 'akkus', 'group' => 'capability', 'seed' => 'akkus-kasalar',
            'steps' => [['send' => 'Bugün ki cirolar neler']],
            'expect' => ['no_new_draft' => true, 'reply_has' => ['Ergenekon', 'İzzetpaşa', 'Araştırma']]],
        ['id' => 'F1-ay-neden-eksi', 'firm' => 'akkus', 'group' => 'capability', 'seed' => 'akkus-kasalar',
            'steps' => [['send' => 'Bu ay neden eksi']],
            'expect' => ['no_new_draft' => true, 'reply_has' => ['çek ödemesi']]],
        ['id' => 'durust-hafiza-sozu-vermez', 'firm' => 'stok', 'group' => 'regression',
            'steps' => [['send' => 'bundan sonra kalfa deyince Mehmet Kaya yı anla']],
            'expect' => ['no_new_draft' => true, 'reply_not' => ['anlarım', 'anlayacağım', 'hatırlarım', 'hatırlayacağım', 'not aldım']]],
        ['id' => 'F2-takma-ad-hafiza', 'firm' => 'stok', 'group' => 'capability',
            'steps' => [['send' => 'bundan sonra kalfa deyince Mehmet Kaya yı anla']],
            'expect' => ['reply_has' => ['hatırla']]],
    ],

    // Yetenek vakaları için geçmiş veri (vaka içinde yazılır, vaka sonunda geri alınır).
    'seeds' => [
        'stok-gecmis' => [['ledger', 'Ak Un', 'alis', 16500, '-20 days', '20 çuval un']],
        'erkan-gecmis' => [['ledger', 'Recep Demirci', 'odeme', 8000, '-1 days', 'Ödeme'], ['ledger', 'Hüseyin', 'odeme', 2500, '-2 days', 'Ödeme']],
        'akkus-kasalar' => [
            ['sale', 'Ergenekon', 27440, 'today'], ['sale', 'İzzetpaşa', 21845, 'today'], ['sale', 'Araştırma', 51517, 'today'],
            ['expense', 105000, 'today', 'Çek ödemesi'],
        ],
    ],
];
