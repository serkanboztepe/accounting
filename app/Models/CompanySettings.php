<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySettings extends Model
{
    use Concerns\LogsActivity;

    public function activityAction(): string
    {
        return 'Firma ayarları';
    }

    public function activityDetails(): array
    {
        $short = fn (?string $t) => $t === null || $t === '' ? null : mb_strimwidth(strip_tags($t), 0, 300, '…') . ' (' . mb_strlen($t) . ' karakter)';

        return \App\Models\ActivityLog::compact([
            'Ünvan'              => $this->title,
            'Adres'              => $this->address,
            'Telefon'            => $this->phone,
            'E-posta'            => $this->email,
            'Vergi dairesi'      => $this->tax_office,
            'Vergi no'           => $this->tax_number,
            'Teklif şablonu'     => $short($this->quote_template),
            'Sözleşme şablonu'   => $short($this->contract_template),
        ]);
    }

    protected $fillable = [
        'title',
        'address',
        'phone',
        'email',
        'tax_office',
        'tax_number',
        'quote_template',
        'contract_template',
    ];

    // Cari PIN'i sadece CariLock üzerinden (hash'li) yazılır/okunur.
    protected $hidden = ['cari_pin'];

    /**
     * Tek satırlık ayar kaydını döndürür (yoksa oluşturur).
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
