<?php

namespace App\Services\Agent;

use App\Models\UsageLog;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Ajan döngüsü (plan v5 §5): model → araç çağrıları → sonuçlar → model … ta ki cevap verene ya da araç kutusu turu bitirene kadar.
 *
 * Modelin döndürdüğü içerik (düşünme blokları dahil) bir sonraki isteğe HİÇ DEĞİŞTİRİLMEDEN geri verilir; geçmişe yalnız ekleme
 * yapılır. Aynı adımdaki tüm araç sonuçları tek kullanıcı mesajında döner. Projedeki diğer Claude çağrıları gibi düz HTTP
 * (Http::fake ile sınanır, UsageLog ile ölçülür).
 */
final class AgentRunner
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * @param  list<array<string, mixed>>  $system
     * @param  list<array<string, mixed>>  $messages
     * @return array{text: string, steps: list<array<string, mixed>>, usage: array<string, int>, cost: float, model: string, stop: ?string, seconds: float}
     */
    public function run(array $system, array $messages, AgentToolbox $toolbox, string $state, string $effort): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Anthropic anahtarı yok.');
        }
        $started = microtime(true);
        $model = (string) config('agent.model');
        $tools = AgentToolbox::definitions();
        // Araç tanımları + çekirdek talimat tek sabit önek; son araçta kesme noktası gerekmez (sistem bloğundaki kesme noktası ikisini kapsar).
        $messages[] = ['role' => 'system', 'content' => $state];

        $steps = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
        $cost = 0.0;
        $text = '';
        $stop = null;
        $usedBackup = false;

        for ($step = 1; $step <= (int) config('agent.max_steps'); $step++) {
            $payload = [
                'model' => $model,
                'max_tokens' => (int) config('agent.max_tokens'),
                'system' => $system,
                'tools' => $tools,
                'messages' => $messages,
                'output_config' => ['effort' => $effort],
                // Döngü içinde büyüyen kuyruk (araç sonuçları) adımdan adıma önbellekten okunsun.
                'cache_control' => ['type' => 'ephemeral'],
            ];
            $response = $this->call($apiKey, $payload);
            if ($response === null && ! $usedBackup && ($backup = (string) config('agent.backup_model')) !== '' && $backup !== $model && $step === 1) {
                // Ana model cevap vermedi (hata / kesinti): tur başındaysak yedek modelle bir kez daha.
                $usedBackup = true;
                $model = $backup;
                $payload['model'] = $model;
                $response = $this->call($apiKey, $payload);
            }
            if ($response === null) {
                throw new RuntimeException('Model cevap vermedi.');
            }

            $served = (string) ($response['model'] ?? $model);
            $u = (array) ($response['usage'] ?? []);
            UsageLog::recordAi($served, $u);
            $cost += UsageLog::costOf($served, $u);
            foreach (array_keys($usage) as $k) {
                $usage[$k] += (int) ($u[$k] ?? 0);
            }
            $stop = $response['stop_reason'] ?? null;
            $content = (array) ($response['content'] ?? []);

            if ($stop === 'refusal') {
                $steps[] = ['step' => $step, 'refusal' => $response['stop_details'] ?? null];
                $text = '';
                break;
            }
            $text = trim(implode("\n", array_map(fn ($b) => ($b['type'] ?? null) === 'text' ? (string) $b['text'] : '', $content)));
            $calls = array_values(array_filter($content, fn ($b) => ($b['type'] ?? null) === 'tool_use'));
            if ($calls === [] || $stop !== 'tool_use') {
                $steps[] = ['step' => $step, 'text' => $text, 'stop' => $stop];
                break;
            }

            // Modelin içeriği aynen geri verilir (düşünme blokları dahil).
            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];
            foreach ($calls as $call) {
                $input = is_array($call['input'] ?? null) ? $call['input'] : [];
                $name = (string) ($call['name'] ?? '');
                $out = $toolbox->run($name, $input);
                $steps[] = ['step' => $step, 'tool' => $name, 'input' => $input, 'result' => AgentLog::brief($out)];
                $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) ($call['id'] ?? ''),
                    'content' => (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'is_error' => isset($out['hata'])];
            }
            $messages[] = ['role' => 'user', 'content' => $results];

            if ($toolbox->finished()) {
                $text = ''; // soru soruldu ya da kaydedildi: müşteriye giden metni kod üretti
                $stop = 'finished_by_tool';
                break;
            }
            if ($step === (int) config('agent.max_steps')) {
                $stop = 'max_steps';
                $text = '';
            }
        }

        return ['text' => $text, 'steps' => $steps, 'usage' => $usage, 'cost' => round($cost, 6), 'model' => $model, 'stop' => $stop,
            'seconds' => round(microtime(true) - $started, 2)];
    }

    /** @return array<string, mixed>|null başarısızsa null (günlüğe yazılır) */
    private function call(string $apiKey, array $payload): ?array
    {
        $headers = ['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
        if (config('agent.server_fallbacks')) {
            $headers['anthropic-beta'] = self::FALLBACK_BETA;
            $payload['fallbacks'] = 'default';
        }
        try {
            $response = Http::withHeaders($headers)->connectTimeout(10)->timeout((int) config('agent.timeout'))
                ->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning('Ajan: model isteği başarısız', ['status' => $response->status(), 'model' => $payload['model'],
                'body' => mb_substr((string) $response->body(), 0, 500)]);

            return null;
        }

        return (array) $response->json();
    }
}
