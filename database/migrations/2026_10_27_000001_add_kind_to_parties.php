<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cari tipi (kullanıcı 27.10: "Bu cariyle nasıl çalışıyorsun?"): customer / supplier / both / employee — cari sayfasında yalnız
 * o tipin düğmeleri, Cariler listesinde tipe göre süzme. Boş = bugünkü görünüm (tüm düğmeler); ilk hareketten kendiliğinden dolar.
 * salary_auto: personelin maaşı maaş günü kendiliğinden işlenir (yeni personelde açık, eskilerde kapalı — firma firma açılır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            if (! Schema::hasColumn('parties', 'kind')) {
                $table->string('kind', 20)->nullable()->after('notes');
            }
            if (! Schema::hasColumn('parties', 'salary_auto')) {
                $table->boolean('salary_auto')->default(false)->after('salary_day');
            }
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn(['kind', 'salary_auto']);
        });
    }
};
