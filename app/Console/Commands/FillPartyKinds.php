<?php

namespace App\Console\Commands;

use App\Models\Party;
use Illuminate\Console\Command;

/**
 * Eski carilere tip (27.10, "Bu cariyle nasıl çalışıyorsun?"): personel → personel; geçmişte satış / tahsilat → müşteri, alış / ödeme /
 * gider / alış anlaşması → tedarikçi, ikisi → ikisi de; hareketi yoksa boş kalır (bugünkü görünüm). Seçilmiş tipe dokunulmaz.
 * Bakiyeye dokunmaz — yalnız etiket. Varsayılan DENEME; yazmak için --apply. --salary-auto: maaşı + günü olan personelde
 * maaş günü kendiliğinden işleme açılır (firma onayıyla).
 *
 *   php artisan parties:fill-kind                       say, yazma
 *   php artisan parties:fill-kind --apply               yaz
 *   php artisan parties:fill-kind --apply --salary-auto
 *   php artisan parties:fill-kind --apply --all-both    personel dışı hepsi "ikisi de" (Yıldız, Akkuş — kullanıcı 27.10: düğme gizlenmesin)
 */
class FillPartyKinds extends Command
{
    protected $signature = 'parties:fill-kind {--apply : Yaz (yoksa yalnız sayar)} {--salary-auto : Personelde maaşı kendiliğinden işlemeyi aç} {--all-both : Personel dışı tüm cariler "ikisi de"}';

    protected $description = 'Tipi boş carilere geçmişlerinden tip verir';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = array_fill_keys([...array_keys(Party::KINDS), 'boş kalır'], 0);
        $allBoth = (bool) $this->option('all-both');
        // --all-both: personel olmayan herkes (boş ya da kendiliğinden dolmuş) "ikisi de"; personel (eski, tipi boş) olduğu gibi kalır.
        $query = $allBoth ? Party::where('is_employee', false)->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Party::KIND_BOTH))
            : Party::whereNull('kind');
        foreach ($query->orderBy('id')->get() as $party) {
            $kind = match (true) {
                $allBoth => Party::KIND_BOTH,
                $party->is_employee => Party::KIND_EMPLOYEE,
                default => Party::kindFromHistory($party->id),
            };
            $counts[$kind ?? 'boş kalır']++;
            if ($apply && $kind !== null) {
                $party->forceFill(['kind' => $kind])->saveQuietly();
            }
        }
        $auto = Party::where('is_employee', true)->where('salary_auto', false)->where('salary_amount', '>', 0)->whereNotNull('salary_day');
        $autoCount = (clone $auto)->count();
        if ($apply && $this->option('salary-auto')) {
            $auto->update(['salary_auto' => true]);
        }

        $this->line(($apply ? 'YAZILDI' : 'DENEME') . ' · müşteri ' . $counts[Party::KIND_CUSTOMER] . ' · tedarikçi ' . $counts[Party::KIND_SUPPLIER]
            . ' · ikisi de ' . $counts[Party::KIND_BOTH] . ' · personel ' . $counts[Party::KIND_EMPLOYEE] . ' · boş ' . $counts['boş kalır']
            . ' · kendiliğinden maaş ' . ($apply && $this->option('salary-auto') ? 'açıldı ' : 'açılabilir ') . $autoCount);

        return self::SUCCESS;
    }
}
