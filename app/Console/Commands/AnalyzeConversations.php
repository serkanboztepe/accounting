<?php

namespace App\Console\Commands;

use App\Models\ConversationReport;
use App\Services\Whatsapp\ConversationAnalyzer;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Günlük konuşma analizi: dünün tüm WhatsApp konuşmaları → Claude → numaralı rapor (hub: Günlük
 * Analiz) + yöneticiye WhatsApp özeti. Merkezde çalışır (tenants:run DEĞİL — firmaları kendisi gezer).
 *
 *   php artisan conversations:analyze                     dün
 *   php artisan conversations:analyze --date=2026-10-09   belli bir gün
 *   php artisan conversations:analyze --no-send --force   özeti gönderme / mevcut raporu yenile
 */
class AnalyzeConversations extends Command
{
    protected $signature = 'conversations:analyze {--date= : Gün (Y-m-d), varsayılan dün} {--no-send : WhatsApp özeti gönderme} {--force : O günün raporu varsa yeniden yaz}';

    protected $description = 'Dünkü WhatsApp konuşmalarını analiz edip numaralı rapor yazar';

    public function handle(ConversationAnalyzer $analyzer, WhatsappSender $sender): int
    {
        $day = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();
        $day = $day->startOfDay();

        $existing = ConversationReport::whereDate('report_date', $day)->first();
        if ($existing && ! $this->option('force')) {
            $this->info("Rapor #{$existing->id} ({$day->format('d.m.Y')}) zaten var. Yenilemek için --force.");

            return self::SUCCESS;
        }

        $data = $analyzer->collect($day);
        if ($data['message_count'] === 0) {
            $this->info("{$day->format('d.m.Y')}: konuşma yok, rapor yazılmadı.");

            return self::SUCCESS;
        }

        $this->line("{$day->format('d.m.Y')}: {$data['message_count']} mesaj, {$data['firm_count']} firma → analiz ediliyor…");
        $result = $analyzer->analyze($data['transcript'], $day);

        $report = $existing ?? new ConversationReport(['report_date' => $day->toDateString()]);
        $report->fill([
            'message_count' => $data['message_count'],
            'firm_count' => $data['firm_count'],
            'summary' => $result['summary'],
            'body' => $result['body'],
            'model' => $result['model'],
            'cost_usd' => $result['cost_usd'],
        ])->save();

        $this->info("Rapor #{$report->id} yazıldı (\${$result['cost_usd']}): {$result['summary']}");
        $lessons = $this->storeLessons($report, $result['lessons'] ?? []);
        if ($lessons > 0) {
            $this->info("{$lessons} ders önerisi onaya düştü (hub → Dersler).");
        }

        if (! $this->option('no-send')) {
            $this->sendSummary($report, $sender);
        }

        return self::SUCCESS;
    }

    /** Gece analizinin ders önerileri → onay bekleyen (hub → Dersler). Aynı kural zaten varsa tekrar eklenmez. */
    private function storeLessons(ConversationReport $report, array $lessons): int
    {
        $firms = \App\Models\HubFirm::pluck('id', 'name');
        $n = 0;
        foreach ($lessons as $l) {
            $rule = trim((string) ($l['rule'] ?? ''));
            if ($rule === '' || \App\Models\AssistantLesson::where('rule', $rule)->exists()) {
                continue;
            }
            $general = ($l['scope'] ?? '') === 'general';
            \App\Models\AssistantLesson::create([
                'conversation_report_id' => $report->id,
                'hub_firm_id' => $general ? null : ($firms[$l['firm'] ?? ''] ?? null),
                'source_firm_id' => $firms[$l['firm'] ?? ''] ?? null,
                'customer_question' => isset($l['customer_question']) && is_string($l['customer_question']) ? mb_substr($l['customer_question'], 0, 1000) : null,
                'firm_name' => $l['firm'] ?? null,
                'problem' => mb_substr((string) $l['problem'], 0, 2000),
                'cause' => isset($l['cause']) ? mb_substr((string) $l['cause'], 0, 1000) : null,
                'rule' => mb_substr($rule, 0, 1000),
                'example_message' => isset($l['example_message']) ? mb_substr((string) $l['example_message'], 0, 1000) : null,
                'expected' => isset($l['expected']) ? mb_substr((string) $l['expected'], 0, 1000) : null,
                'status' => 'pending',
            ]);
            $n++;
        }

        return $n;
    }

    private function sendSummary(ConversationReport $report, WhatsappSender $sender): void
    {
        $phones = config('services.whatsapp.admin_phones', []);
        if ($phones === []) {
            $this->line('Özet gönderilmedi: WHATSAPP_ADMIN_PHONES boş.');

            return;
        }

        $text = "📊 *Rapor #{$report->id}* (" . $report->report_date->locale('tr')->translatedFormat('j F') . ")\n"
            . $report->summary . "\n\n"
            . 'Ayrıntı: ' . rtrim((string) config('app.url'), '/') . "/hub → Günlük Analiz\n"
            . "Yorumların için Claude'a \"Rapor {$report->id}: …\" yaz."
            . (($pending = \App\Models\AssistantLesson::where('status', 'pending')->count()) > 0
                ? "\n🎓 {$pending} ders önerisi onayını bekliyor: /hub → Dersler" : '');

        $sent = false;
        foreach ($phones as $phone) {
            $sent = $sender->sendText($phone, $text) || $sent;
        }
        if ($sent) {
            $report->update(['sent_at' => now()]);
        }
        $this->line($sent ? 'WhatsApp özeti gönderildi.' : 'WhatsApp özeti gönderilemedi (log).');
    }
}
