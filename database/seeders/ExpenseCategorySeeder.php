<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Yeni firmanın başlangıç gider kategorileri — sektöre göre. İnşaat kategorileri (Nakliye, Vinç,
 * Ruhsat…) yalnız inşaat firmalarına; diğerleri birkaç genel kategoriyle başlar, gerisini WhatsApp
 * asistanı ihtiyaç oldukça açar (kullanıcı kararı 2026-10-10: medya stüdyosuna "Vinç" yüklenmiş,
 * otobüs bileti "Nakliye"ye yazılmıştı). Firma içinde Tenancy::run altında çalışır → app.profile firmanın.
 */
class ExpenseCategorySeeder extends Seeder
{
    public const CONSTRUCTION = ['Nakliye', 'Vinç', 'Ruhsat', 'Muhasebe', 'Vergi', 'Sigorta', 'Elektrik', 'Su'];

    public const GENERAL = ['Kira', 'Fatura', 'Vergi', 'Sigorta', 'Muhasebe', 'Ulaşım'];

    /** @return list<string> */
    public static function forProfile(?string $profile): array
    {
        return in_array($profile, ['toptanci', 'alacak_verecek'], true)
            ? self::GENERAL
            : self::CONSTRUCTION; // müteahhit, mimar, "hepsi açık"
    }

    public function run(): void
    {
        foreach (self::forProfile(config('app.profile')) as $name) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name]);
        }
    }
}
