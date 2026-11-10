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

    // ── Model (kullanıcı kararı 09.11: doğrudan en yetenekli model; 1–2 ay sonra gerçek kayıtlarla kademeli düşürme) ──
    'model' => env('AGENT_MODEL', 'claude-fable-5-2'),
    // Ana model hata verirse / kesilirse o tur bununla bir kez daha denenir.
    'backup_model' => env('AGENT_BACKUP_MODEL', 'claude-opus-5-5'),
    // Düşünme derinliği: rutin tur / belirsiz-para-düzeltme turu (kod seçer). Fable'da düşünme hep açıktır.
    'effort' => env('AGENT_EFFORT', 'medium'),
    'effort_hard' => env('AGENT_EFFORT_HARD', 'high'),
    // Güvenlik süzgeci bir isteği geri çevirirse sunucu aynı isteği önerilen yedek modelde çalıştırır.
    'server_fallbacks' => (bool) env('AGENT_SERVER_FALLBACKS', true),
    'max_steps' => (int) env('AGENT_MAX_STEPS', 8),
    'max_tokens' => (int) env('AGENT_MAX_TOKENS', 16000),
    'timeout' => (int) env('AGENT_TIMEOUT', 180),

    // Firma başına günlük ajan harcaması (USD); aşılırsa o gün eski asistan sürer.
    'daily_cost_usd' => (float) env('AGENT_DAILY_COST_USD', 15),
    // Aynı numarada art arda bu kadar hata → o numara 1 saat eski asistana döner.
    'breaker_errors' => 3,

    // Bağlam: son kaç saatin konuşması ve en çok kaç mesaj.
    'history_hours' => 24,
    'history_messages' => 40,
];
