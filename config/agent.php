<?php

/*
| Ajan asistan (plan v5) sınırları. Varsayılanlar kullanıcı kararı (09.11.2026); firma başına hub'dan değişebilir (ileride).
| Model ve maliyet ayarları Aşama D'de eklenir.
*/
return [
    // Bu tutar ve üstündeki tek işlem "evet" ile değil, TUTARI YAZARAK onaylanır.
    'large_amount' => (float) env('AGENT_LARGE_AMOUNT', 500000),

    // Silme: kart başına en çok, günde en çok (müşterinin gördüğü kayıt sayısı); bu sayıdan itibaren "sil" yazarak onay.
    'delete_per_card' => (int) env('AGENT_DELETE_PER_CARD', 20),
    'delete_per_day' => (int) env('AGENT_DELETE_PER_DAY', 50),
    'delete_word_from' => (int) env('AGENT_DELETE_WORD_FROM', 3),

    // Bir öneride en çok işlem.
    'ops_per_proposal' => 12,

    // Açık öneri bu kadar dakika "evet" bekler; sonrası bayat (yeniden önerilir).
    'proposal_minutes' => 60,
];
