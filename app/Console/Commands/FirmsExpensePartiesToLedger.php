<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\HubFirm;
use App\Support\ExpensePartyMove;
use App\Support\Money;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kişiye bağlı eski giderleri kişinin hesabına alış olarak taşır (ExpensePartyMove). Varsayılan DENEME: her şey yapılır,
 * sonunda geri alınır, yalnız rapor basılır. Gerçekten yazmak için --write (önce firma yedeği).
 */
class FirmsExpensePartiesToLedger extends Command
{
    protected $signature = 'firms:expense-parties-to-ledger {--firm= : Firma id (tek panelde zorunlu)} {--write : Gerçekten yaz}';

    protected $description = 'Kişiye bağlı giderleri kişinin hesabına alış olarak taşır (varsayılan: deneme, yazmaz)';

    public function handle(): int
    {
        $work = function (): int {
            ActivityLog::via('support', 'Destek');
            DB::beginTransaction();
            try {
                $r = ExpensePartyMove::run();
            } catch (Throwable $e) {
                DB::rollBack();
                $this->error('Taşıma durdu, hiçbir şey yazılmadı: ' . $e->getMessage());

                return self::FAILURE;
            } finally {
                ActivityLog::clearVia();
            }
            $this->option('write') ? DB::commit() : DB::rollBack();

            $this->info(count($r['rows']) . ' kişili gider → alış' . ($r['rows'] ? ' (' . collect($r['rows'])->where('paid', true)->count() . ' ödenmiş: alış + ödeme)' : ''));
            foreach ($r['rows'] as $row) {
                $this->line(sprintf('  #%d %s · %s · %s ₺ · %s · %s · %s%s', $row['id'], $row['date'], $row['party'], Money::format($row['amount']),
                    $row['paid'] ? 'ödenmiş' : 'ödenmemiş', $row['project'] ?? 'projesiz', $row['description'],
                    $row['advance_word'] ? ' ⚠️ açıklamada "avans" geçiyor' : ''));
            }
            foreach ($r['checks'] as $line) {
                $this->line('  ' . $line);
            }
            foreach ($r['kinds'] as $line) {
                $this->line('  • ' . $line);
            }
            $this->info($this->option('write') ? 'YAZILDI.' : 'DENEME — hiçbir şey yazılmadı (yazmak için --write).');

            return self::SUCCESS;
        };

        if (! Tenancy::enabled()) {
            return $work();
        }
        $firm = HubFirm::find($this->option('firm'));
        if (! $firm?->isLocal()) {
            $this->error('--firm=<id> ver (tek paneldeki firma).');

            return self::FAILURE;
        }

        return Tenancy::run($firm, $work);
    }
}
