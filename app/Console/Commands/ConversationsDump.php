<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use App\Models\WhatsappMessage;
use App\Models\WhatsappPendingExpense;
use App\Support\AssistantScorecard;
use App\Support\Phone;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sabah dökümü (29.10, kullanıcı: "sabah ben döküm yap dediğimde yaparsın"): otomatik rapor / ders / kayıt kontrolü yerine
 * yönetici sohbette "döküm yap" der, bu komutun çıktısı okunur. YALNIZ OKUMA, AI çağrısı yok. Firma firma: karne rakamları
 * (gelen, kayıt, geri alma, iptal, anlaşılmayan, hata, 15 sn aşan cevap) + konuşma (saat, kim, mesaj, tür) + bu dönemde
 * kapanan/açık kalan taslaklar.
 *
 *   php artisan conversations:dump                 dün 00:00'dan şimdiye (İstanbul)
 *   php artisan conversations:dump --since=2026-10-26 --firm=9
 */
class ConversationsDump extends Command
{
    protected $signature = 'conversations:dump {--since= : Başlangıç günü (YYYY-MM-DD, İstanbul; varsayılan dün)} {--firm= : Yalnız bu firma (hub id)}';

    protected $description = 'WhatsApp konuşmalarını ve asistan rakamlarını firma firma döker (salt okuma)';

    private const BODY_LIMIT = 700;

    public function handle(): int
    {
        $since = $this->option('since')
            ? Carbon::parse($this->option('since'), 'Europe/Istanbul')->startOfDay()
            : now('Europe/Istanbul')->subDay()->startOfDay();
        $since = $since->setTimezone(config('app.timezone'));
        $firms = HubFirm::where('is_active', true)->when($this->option('firm'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();

        $this->line('DÖKÜM — ' . $since->copy()->setTimezone('Europe/Istanbul')->format('d.m.Y H:i') . ' → '
            . now('Europe/Istanbul')->format('d.m.Y H:i'));
        foreach ($firms as $firm) {
            $names = $firm->phones()->pluck('name', 'phone');
            try {
                $out = Tenancy::run($firm, fn () => $this->firm($since, $names->all()));
            } catch (Throwable $e) {
                $out = '  ⚠ okunamadı: ' . $e->getMessage();
            }
            $this->line('');
            $this->line("=== #{$firm->id} {$firm->name}");
            $this->line($out);
        }

        return self::SUCCESS;
    }

    /** Firma içinde (Tenancy::run). */
    private function firm(Carbon $since, array $names): string
    {
        $messages = WhatsappMessage::where('created_at', '>=', $since)->orderBy('id')->get();
        if ($messages->isEmpty()) {
            return '  (mesaj yok)';
        }
        $s = AssistantScorecard::compute($since);
        $lines = [sprintf('  KARNE: gelen %d · kayıt %d · geri alma %d · iptal %d · anlaşılmayan %d · hata %d · açık taslak %d · %d sn aşan cevap %d (en uzun %s sn)',
            $s['in'], $s['saved'], $s['undo'], $s['cancelled'], $s['help'], $s['errors'], $s['open'], AssistantScorecard::TWILIO_LIMIT_SECONDS,
            $s['slow'], $s['max'] ?? '—')];

        $lines[] = '  KONUŞMA:';
        foreach ($messages as $m) {
            $who = $names[$m->phone] ?? Phone::display($m->phone);
            $flag = in_array($m->kind, WhatsappMessage::UNCLEAR_KINDS, true) ? ' ❗' : '';
            $body = preg_replace('/\s+/u', ' ', trim((string) $m->body));
            $lines[] = sprintf('    %s %s %s [%s]%s%s %s', $m->created_at->setTimezone('Europe/Istanbul')->format('d.m H:i:s'),
                $m->direction === 'in' ? '←' : '→', $who, $m->kind ?: '-', $m->has_media ? ' 📎' : '', $flag,
                mb_strlen($body) > self::BODY_LIMIT ? mb_substr($body, 0, self::BODY_LIMIT) . '…' : $body);
        }

        $drafts = WhatsappPendingExpense::where('updated_at', '>=', $since)->orderBy('id')->get(['id', 'phone', 'status', 'summary', 'updated_at']);
        if ($drafts->isNotEmpty()) {
            $lines[] = '  TASLAKLAR:';
            foreach ($drafts as $d) {
                $lines[] = sprintf('    #%d %s %s %s', $d->id, $d->updated_at->setTimezone('Europe/Istanbul')->format('d.m H:i'), $d->status,
                    mb_substr(preg_replace('/\s+/u', ' ', strip_tags((string) $d->summary)), 0, 200));
            }
        }

        return implode("\n", $lines);
    }
}
