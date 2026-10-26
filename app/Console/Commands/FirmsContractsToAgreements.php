<?php

namespace App\Console\Commands;

use App\Models\HubFirm;
use App\Support\ContractConversion;
use App\Support\Money;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bir firmanın sözleşmelerini anlaşmalara çevirir (ContractConversion). Varsayılan DENEME: her şey yapılır, sonunda geri alınır,
 * yalnız rapor basılır. Gerçekten yazmak için --write (önce firma yedeği).
 */
class FirmsContractsToAgreements extends Command
{
    protected $signature = 'firms:contracts-to-agreements {--firm= : Firma id (tek panelde zorunlu)} {--write : Gerçekten yaz}';

    protected $description = 'Sözleşmeleri anlaşmalara çevirir (varsayılan: deneme, yazmaz)';

    public function handle(): int
    {
        $work = function (): int {
            DB::beginTransaction();
            try {
                $r = ContractConversion::run();
            } catch (Throwable $e) {
                DB::rollBack();
                $this->error('Dönüşüm durdu, hiçbir şey yazılmadı: ' . $e->getMessage());

                return self::FAILURE;
            }
            $this->option('write') ? DB::commit() : DB::rollBack();

            $this->info(sprintf('%d anlaşma, %d kalem, %d teslimat → alış, %d ödeme, %d çek bağlandı, %d fatura nota yazıldı',
                $r['agreements'], $r['items'], $r['deliveries'], $r['payments'], $r['checks'], $r['invoices']));
            foreach ($r['rows'] as $row) {
                $same = abs($row['delivered'] - $row['agreement_delivered']) < 0.01 ? '✓' : '✗';
                $this->line(sprintf('  #%d → AG#%d %s · %s · toplam %s · teslim %s → %s %s · ödenen %s', $row['contract'], $row['agreement'],
                    $row['title'], $row['party'], Money::format($row['total']), Money::format($row['delivered']),
                    Money::format($row['agreement_delivered']), $same, Money::format($row['paid'])));
            }
            foreach ($r['notes'] as $note) {
                $this->line('  • ' . $note);
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
