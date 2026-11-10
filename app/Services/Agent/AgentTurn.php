<?php

namespace App\Services\Agent;

use App\Services\Agent\Ops\AgentProposal;
use App\Services\Agent\Ops\Numbers;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ajan turu (plan v5 §1): "ajan canlı" firmada gelen mesaj buraya gelir.
 *
 * prepare() turu alıp almayacağına hemen karar verir (anahtar var mı, günlük sınır, devre kesici): almazsa null döner ve mesajı
 * ESKİ YOL aynen işler. Alırsa bir iş döner; kontrolcü onu Twilio'ya cevap verdikten sonra çalıştırır (ajan düşünürken Twilio'nun
 * 15 sn sınırı dolmasın), cevapları WhatsApp API ile gönderir. Müşteri hiçbir durumda cevapsız kalmaz.
 */
class AgentTurn
{
    public const SORRY = 'Şu an toparlayamadım, birazdan tekrar yazar mısın? 🙏';

    /**
     * @param  callable(): ?array{media_type:string,data:string}  $media  ek (fotoğraf/PDF) — yalnız tur alınırsa indirilir
     * @return (callable(): list<string>)|null
     */
    public function prepare(string $phone, string $body, int $numMedia, callable $media, ?int $messageId = null): ?callable
    {
        if ((string) config('services.anthropic.api_key') === '' || self::overDailyLimit() || self::breakerOpen($phone)) {
            return null;
        }

        return fn (): array => $this->run($phone, $body, $numMedia > 0 ? $media() : null, $messageId);
    }

    /** @return list<string> müşteriye gidecek mesaj(lar) */
    public function run(string $phone, string $body, ?array $media, ?int $messageId = null): array
    {
        $toolbox = new AgentToolbox($phone, $body);
        $log = ['mode' => AgentGate::LIVE, 'phone' => $phone, 'message' => $body, 'has_media' => $media !== null];
        try {
            $result = app(AgentRunner::class)->run(
                AgentContext::system($phone),
                AgentContext::messages($phone, $body, $media, $messageId),
                $toolbox,
                AgentContext::state($phone),
                self::effort($phone, $body, $media !== null),
            );
            self::addCost($result['cost']);
            $replies = self::replies($result['text'], $toolbox->outcome);
            if ($toolbox->outcome['card'] !== null && $toolbox->outcome['proposal_id'] !== null
                && $toolbox->outcome['question'] === null && $toolbox->outcome['confirmed'] === null) {
                AgentProposal::markSent((int) $toolbox->outcome['proposal_id']); // kart bu cevapla gidiyor
            }
            Cache::forget(self::breakerKey($phone));
            AgentLog::write($log + ['model' => $result['model'], 'steps' => $result['steps'], 'stop' => $result['stop'], 'replies' => $replies,
                'outcome' => $toolbox->outcome, 'usage' => $result['usage'], 'cost_usd' => $result['cost'], 'seconds' => $result['seconds']]);

            return $replies;
        } catch (Throwable $e) {
            report($e);
            Log::error('Ajan turu hatası', ['msg' => $e->getMessage()]);
            Cache::put(self::breakerKey($phone), (int) Cache::get(self::breakerKey($phone), 0) + 1, now()->addHour());
            AgentLog::write($log + ['error' => mb_substr($e->getMessage(), 0, 500), 'outcome' => $toolbox->outcome]);

            // Bu turda bir şey kaydedildiyse (onay geçti, sonra hata oldu) müşteri bunu bilmeli.
            return [$toolbox->outcome['confirmed'] ?? self::SORRY];
        }
    }

    /**
     * Müşteriye giden mesaj: rakamlı her şeyi KOD yazar (kart, onay metni, soru). Ajanın kendi cümlesi yalnız rakam taşımıyorsa
     * ve kısaysa yanına eklenir — kartla çelişen ikinci bir rakam müşteriye gitmesin.
     *
     * @return list<string>
     */
    public static function replies(string $agentText, array $outcome): array
    {
        $agentText = trim($agentText);
        $fixed = $outcome['confirmed'] ?? $outcome['question'] ?? $outcome['card'] ?? null;
        if ($fixed === null) {
            return [$agentText !== '' ? $agentText : self::SORRY];
        }
        $safe = $agentText !== '' && mb_strlen($agentText) <= 200
            && array_filter(Numbers::in($agentText), fn ($n) => $n >= 100) === []
            && ! preg_match('/kayded|kaydett|yazdım|sildim|✅/ui', $agentText); // "kaydettim" yalnız kodun sözüdür

        $parts = array_values(array_filter([$safe ? $agentText : null, $fixed]));

        return [implode("\n\n", $parts)];
    }

    /** Rutin tur medium; açık kart varken itiraz/düzeltme, uzun ya da ekli mesaj high. "evet" gibi kısa onay low. */
    public static function effort(string $phone, string $body, bool $hasMedia): string
    {
        $open = AgentProposal::open($phone) !== null;
        $short = mb_strlen(trim($body)) <= 12;
        if ($open && $short && \App\Services\Whatsapp\ChatAssistant::hasAssent($body)) {
            return 'low';
        }
        if ($hasMedia || ($open && ! $short) || mb_strlen($body) > 160) {
            return (string) config('agent.effort_hard');
        }

        return (string) config('agent.effort');
    }

    // ── Sınırlar ─────────────────────────────────────────────────────

    private static function costKey(): string
    {
        return Tenancy::key('agent-cost:' . now('Europe/Istanbul')->toDateString());
    }

    public static function costToday(): float
    {
        return (float) Cache::get(self::costKey(), 0.0);
    }

    public static function addCost(float $usd): void
    {
        Cache::put(self::costKey(), round(self::costToday() + $usd, 6), now()->addDays(2));
    }

    public static function overDailyLimit(): bool
    {
        return self::costToday() >= (float) config('agent.daily_cost_usd');
    }

    private static function breakerKey(string $phone): string
    {
        return Tenancy::key('agent-errors:' . sha1($phone));
    }

    public static function breakerOpen(string $phone): bool
    {
        return (int) Cache::get(self::breakerKey($phone), 0) >= (int) config('agent.breaker_errors');
    }
}
