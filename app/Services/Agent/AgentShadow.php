<?php

namespace App\Services\Agent;

/**
 * Ajan gölgesi (plan v5 §8): eski yol cevabını verdikten SONRA ajan aynı mesajı gerçek geçmişle işler; öneri doğrulanır,
 * deneme kaydı yapılır, hiçbir şey yazılmaz ve müşteriye hiçbir şey gönderilmez. Sonuç AgentLog'a "shadow" olarak düşer.
 *
 * Aşama A: yalnız kanca (iş yapmaz). Gölge turu Aşama D/F'de gelir.
 */
class AgentShadow
{
    /** @param  list<string>  $legacyReplies  eski yolun bu mesaja verdiği cevap(lar) */
    public function observe(string $phone, string $body, int $numMedia, array $legacyReplies): void
    {
        // Aşama F
    }
}
