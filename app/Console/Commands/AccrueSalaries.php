<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Models\Reminder;
use App\Services\Whatsapp\EmployeeDraft;
use App\Support\SalaryLedger;
use Illuminate\Console\Command;

/**
 * Maaş günü kendiliğinden işleme (kullanıcı 27.10: "hep avansla kapatıyorsa hiç maaşa basmaz, hesap bozulur"). Kartında maaşı + maaş
 * günü olan ve "kendiliğinden işle" açık personelde o gün "Maaş — Ay Yıl" yazılır (o ay yoksa); avans / kesinti bakiyeden düşer,
 * ödemeyi kullanıcı yazar. 08:00 maaş hatırlatması "… maaşı işlendi" der (EmployeeDraft::reminderNote). Sabah 07:50, firma firma.
 *
 *   php artisan salaries:accrue            işle
 *   php artisan salaries:accrue --dry-run  yalnız göster
 */
class AccrueSalaries extends Command
{
    protected $signature = 'salaries:accrue {--dry-run : Yazmadan göster} {--date= : Bu güne göre (YYYY-MM-DD; kontrol için, --dry-run ile)}';

    protected $description = 'Maaş günü gelen personelin maaşını işler';

    public function handle(): int
    {
        if ($this->option('date') && ! $this->option('dry-run')) {
            $this->error('--date yalnız --dry-run ile (başka güne maaş yazılmaz).');

            return self::FAILURE;
        }
        $today = $this->option('date')
            ? \Illuminate\Support\Carbon::parse($this->option('date'), Reminder::TIMEZONE)->startOfDay()
            : now(Reminder::TIMEZONE)->startOfDay();
        // Maaş günü bugün olan + bu ay içinde günü geçip yazılmamış olan (sunucu bir sabah çalışmadıysa — plan v4: telafi yalnız bu ay).
        // İlk maaş ayından (salary_first_month) ve Kasım 2026'dan önceki ay yazılmaz.
        $due = SalaryLedger::eligibleEmployees()->filter(function (Party $p) use ($today) {
            $period = $today->format('Y-m');

            return min((int) $p->salary_day, $today->daysInMonth) <= $today->day && $period >= SalaryLedger::BOUNDARY
                && (! $p->salary_first_month || $period >= $p->salary_first_month);
        });

        foreach ($due as $party) {
            $day = $today->copy()->day(min((int) $party->salary_day, $today->daysInMonth));
            if ($this->option('dry-run')) {
                $this->line($party->name . ': ' . (EmployeeDraft::accrued($party, $day) ? 'bu ay işlenmiş'
                    : (SalaryLedger::salaryFor($party, $day->format('Y-m')) < 0.01 ? 'bu ay yazılmayacak (ilk ay)' : 'işlenecek')));

                continue;
            }
            $row = EmployeeDraft::accrued($party, $day) ? null : EmployeeDraft::accrue($party, $day);
            $this->line($party->name . ': ' . ($row ? 'işlendi' : 'yazılmadı (bu ay işlenmiş ya da ilk ay)'));
        }

        return self::SUCCESS;
    }
}
