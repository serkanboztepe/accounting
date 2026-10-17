<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Firma kurulumunda maliyet kaydı: AI çağrısı ya da bizim gönderdiğimiz şablon mesaj. */
class UsageLog extends Model
{
    public const TYPE_AI = 'ai';

    public const TYPE_WA_TEMPLATE = 'wa_template';

    protected $fillable = [
        'type', 'model', 'input_tokens', 'output_tokens',
        'cache_write_tokens', 'cache_read_tokens', 'cost_usd',
    ];

    protected $casts = ['cost_usd' => 'float'];

    /** Anthropic yanıtındaki `usage` alanından kayıt. Kayıt hatası asıl işi bozmasın. */
    public static function recordAi(string $model, array $usage): void
    {
        try {
            [$in, $out] = config("costs.ai_per_mtok.{$model}", [0, 0]);
            $input = (int) ($usage['input_tokens'] ?? 0);
            $output = (int) ($usage['output_tokens'] ?? 0);
            $write = (int) ($usage['cache_creation_input_tokens'] ?? 0);
            $read = (int) ($usage['cache_read_input_tokens'] ?? 0);

            $cost = ($input * $in
                + $output * $out
                + $write * $in * config('costs.cache_write_multiplier')
                + $read * $in * config('costs.cache_read_multiplier')) / 1_000_000;

            $row = [
                'model' => $model,
                'input_tokens' => $input, 'output_tokens' => $output,
                'cache_write_tokens' => $write, 'cache_read_tokens' => $read,
                'cost_usd' => round($cost, 6),
            ];
            self::reportDevUsage($row);
            static::create(['type' => self::TYPE_AI] + $row);
        } catch (Throwable $e) {
            Log::warning('AI kullanım kaydı yazılamadı', ['error' => $e->getMessage()]);
        }
    }

    /** Yerel denemeler: aynı anahtardan harcanan para hub'daki toplu maliyete gitsin (testler AI'ı mock'lar, gönderilmez). */
    private static function reportDevUsage(array $row): void
    {
        $url = (string) config('services.dev_usage.url');
        $secret = (string) config('services.dev_usage.secret');
        // Testler AI'ı mock'lar → gönderilmez; gerçek AI ile deneme seti (eval) harcaması gönderilir (16.10: kayıtsız ~12 $ krediyi bitirdi).
        if ($url === '' || $secret === '' || (app()->runningUnitTests() && ! config('services.dev_usage.report_in_tests'))) {
            return;
        }
        try {
            $params = array_map('strval', $row);
            \Illuminate\Support\Facades\Http::timeout(3)->asForm()
                ->withHeaders(\App\Support\HubSignature::headers($params, $secret))
                ->post($url, $params);
        } catch (Throwable $e) {
            Log::warning('Yerel AI maliyeti hub\'a gönderilemedi', ['error' => $e->getMessage()]);
        }
    }

    /** Bizim başlattığımız şablon mesaj: Twilio + Meta utility ücreti. */
    public static function recordTemplate(string $name): void
    {
        try {
            static::create([
                'type' => self::TYPE_WA_TEMPLATE, 'model' => $name,
                'cost_usd' => config('costs.twilio_per_message') + config('costs.meta_template_utility'),
            ]);
        } catch (Throwable $e) {
            Log::warning('Şablon kullanım kaydı yazılamadı', ['error' => $e->getMessage()]);
        }
    }

    /** Ay özeti (firma kurulumu) — hub bunu imzalı istekle çeker. */
    public static function monthSummary(string $month): array
    {
        $rows = static::query()
            ->where('created_at', '>=', $month . '-01')
            ->where('created_at', '<', date('Y-m-01', strtotime($month . '-01 +1 month')))
            ->get();

        $ai = $rows->where('type', self::TYPE_AI);
        $tpl = $rows->where('type', self::TYPE_WA_TEMPLATE);

        return [
            'month' => $month,
            'ai_calls' => $ai->count(),
            'ai_input_tokens' => (int) $ai->sum('input_tokens'),
            'ai_output_tokens' => (int) $ai->sum('output_tokens'),
            'ai_cache_read_tokens' => (int) $ai->sum('cache_read_tokens'),
            'ai_cache_write_tokens' => (int) $ai->sum('cache_write_tokens'),
            'ai_cost_usd' => round((float) $ai->sum('cost_usd'), 4),
            'templates' => $tpl->count(),
            'template_cost_usd' => round((float) $tpl->sum('cost_usd'), 4),
        ];
    }
}
