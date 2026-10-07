<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySettings extends Model
{
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
