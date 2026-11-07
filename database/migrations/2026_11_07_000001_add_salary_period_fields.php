<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maaş planı v4 (07.11): yeni tablo yok, kolon.
 *  - party_ledger_entries.salary_period: yeni maaş satırının / ayı söylenmiş maaş ödemesinin ayı ("2026-11"). Eski satırlar boş kalır,
 *    ay açıklamadan okunur (SalaryLedger). Açıklama elle değişse de ay şaşmaz.
 *  - parties.salary_first_month / salary_first_amount: ilk kayıttaki "bu ay ne yapayım?" seçimi (0 = o ay maaş yazılmaz, N günlük tutar).
 *  - parties.salary_followup: ödemesi yazılmayan maaş için "ödeme görünmüyor" takibi (sonraki soru günü, cevapsız sayısı, sorma).
 * MySQL'de DDL geri alınmaz → her adım hasColumn ile tekrar çalışabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('party_ledger_entries', 'salary_period')) {
            Schema::table('party_ledger_entries', function (Blueprint $table) {
                $table->char('salary_period', 7)->nullable()->after('due_date');
            });
        }
        foreach ([
            'salary_first_month' => fn (Blueprint $t) => $t->char('salary_first_month', 7)->nullable()->after('salary_auto'),
            'salary_first_amount' => fn (Blueprint $t) => $t->decimal('salary_first_amount', 15, 2)->nullable()->after('salary_first_month'),
            'salary_followup' => fn (Blueprint $t) => $t->json('salary_followup')->nullable()->after('salary_first_amount'),
        ] as $column => $add) {
            if (! Schema::hasColumn('parties', $column)) {
                Schema::table('parties', fn (Blueprint $table) => $add($table));
            }
        }
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', fn (Blueprint $t) => $t->dropColumn('salary_period'));
        Schema::table('parties', fn (Blueprint $t) => $t->dropColumn(['salary_first_month', 'salary_first_amount', 'salary_followup']));
    }
};
