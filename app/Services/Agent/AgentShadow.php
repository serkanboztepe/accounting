<?php

namespace App\Services\Agent;

use Throwable;

/**
 * Ajan gölgesi (plan v5 §8): eski yol cevabını verdikten SONRA ajan aynı mesajı gerçek geçmişle işler. Öneriler denenir ve kartı
 * çıkar ama SAKLANMAZ; onay, vazgeçme, hafıza uygulanmaz; müşteriye hiçbir şey gönderilmez. Sonuç AgentLog'a "shadow" olarak yazılır —
 * eski yolun cevabıyla yan yana (sabah dökümü ve karşılaştırma için). Gölge tek turu ölçer; çok turlu kalite ayrı sınanır.
 */
class AgentShadow
{
    /**
     * @param  list<string>  $legacyReplies  eski yolun bu mesaja verdiği cevap(lar)
     * @param  array{media_type:string,data:string}|null  $media
     */
    public function observe(string $phone, string $body, ?array $media, array $legacyReplies, ?int $messageId = null): void
    {
        if ((string) config('services.anthropic.api_key') === '' || AgentTurn::overDailyLimit()) {
            return;
        }
        $toolbox = new AgentToolbox($phone, $body, shadow: true);
        $log = ['mode' => AgentGate::SHADOW, 'phone' => $phone, 'message' => $body, 'has_media' => $media !== null, 'legacy_replies' => $legacyReplies];
        try {
            $result = app(AgentRunner::class)->run(AgentContext::system($phone), AgentContext::messages($phone, $body, $media, $messageId),
                $toolbox, AgentContext::state($phone), AgentTurn::effort($phone, $body, $media !== null));
            AgentTurn::addCost($result['cost']);
            AgentLog::write($log + ['model' => $result['model'], 'steps' => $result['steps'], 'stop' => $result['stop'],
                'replies' => AgentTurn::replies($result['text'], $toolbox->outcome), 'outcome' => $toolbox->outcome,
                'usage' => $result['usage'], 'cost_usd' => $result['cost'], 'seconds' => $result['seconds']]);
        } catch (Throwable $e) {
            AgentLog::write($log + ['error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
