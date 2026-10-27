<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Models\Reminder;
use App\Services\Whatsapp\EmployeeDraft;
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
    protected $signature = 'salaries:accrue {--dry-run : Yazmadan göster}';

    protected $description = 'Maaş günü gelen personelin maaşını işler';

    public function handle(): int
    {
        $today = now(Reminder::TIMEZONE)->startOfDay();
        $due = Party::where('is_employee', true)->where('salary_auto', true)->where('salary_amount', '>', 0)->whereNotNull('salary_day')
            ->get()->filter(fn (Party $p) => min((int) $p->salary_day, $today->daysInMonth) === $today->day);

        foreach ($due as $party) {
            if ($this->option('dry-run')) {
                $this->line($party->name . ': ' . (EmployeeDraft::accrued($party, $today) ? 'bu ay işlenmiş' : 'işlenecek'));

                continue;
            }
            $this->line($party->name . ': ' . (EmployeeDraft::accrue($party, $today) ? 'işlendi' : 'bu ay zaten işlenmiş'));
        }

        return self::SUCCESS;
    }
}
