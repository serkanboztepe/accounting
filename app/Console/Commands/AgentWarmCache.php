<?php

namespace App\Console\Commands;

use App\Models\DevUsage;
use App\Models\UsageLog;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentRunner;
use App\Services\Agent\AgentToolbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ajanın ortak önekini (araç tanımları + çekirdek talimat, ≈21,7k token, tüm firmalarda aynı) Anthropic önbelleğinde sıcak tutar.
 * Ölçüm (18.11): maliyetin %85'i, seyrek yazan müşterinin ilk mesajında bu önekin baştan yazılmasıydı (~0,25 $/mesaj).
 * `max_tokens: 0` istek yalnız öneki işler, cevap üretmez: önbellekteyse okuma (~0,004 $) ve 1 saatlik süre tazelenir,
 * değilse bir kez yazılır. Model, araçlar, başlıklar ve blok AgentRunner ile birebir aynı olmalı — yoksa önek eşleşmez.
 * Maliyet hub'da "Geliştirme" altında `agent-warm` kaynağıyla görünür. Hata akışı bozmaz.
 *
 *   php artisan agent:warm-cache
 */
class AgentWarmCache extends Command
{
    protected $signature = 'agent:warm-cache';

    protected $description = 'Ajanın ortak önekini önbellekte sıcak tutar';

    public function handle(): int
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '' || ! config('agent.warm_cache', true)) {
            $this->info('Kapalı.');

            return self::SUCCESS;
        }
        $model = (string) config('agent.model');
        $headers = ['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
        $payload = [
            'model' => $model,
            'max_tokens' => 0,
            'system' => [AgentContext::coreBlock()],
            'tools' => AgentToolbox::definitions(),
            'messages' => [['role' => 'user', 'content' => '.']],
            'output_config' => ['effort' => (string) config('agent.effort')],
        ];
        if (config('agent.server_fallbacks')) {
            $headers['anthropic-beta'] = AgentRunner::FALLBACK_BETA;
            $payload['fallbacks'] = 'default';
        }

        try {
            $response = Http::withHeaders($headers)->connectTimeout(10)->timeout(60)->post(AgentRunner::ENDPOINT, $payload);
        } catch (Throwable $e) {
            report($e);

            return self::SUCCESS;
        }
        if ($response->failed()) {
            \Illuminate\Support\Facades\Log::warning('Ajan önbellek ısıtma başarısız', ['status' => $response->status(), 'body' => mb_substr((string) $response->body(), 0, 300)]);

            return self::SUCCESS;
        }

        $usage = (array) $response->json('usage', []);
        $served = (string) ($response->json('model') ?: $model);
        $cost = UsageLog::costOf($served, $usage);
        try {
            DevUsage::create(['source' => 'agent-warm', 'model' => $served,
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0), 'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'cache_write_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0), 'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cost_usd' => round($cost, 6)]);
        } catch (Throwable) {
            // kayıt yazılamadıysa ısıtma yine olmuştur
        }
        $this->info(sprintf('okuma %d · yazma %d · %.4f $', $usage['cache_read_input_tokens'] ?? 0, $usage['cache_creation_input_tokens'] ?? 0, $cost));

        return self::SUCCESS;
    }
}
