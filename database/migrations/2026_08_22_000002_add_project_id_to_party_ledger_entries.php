<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cari hareketine opsiyonel proje ETİKETİ. Filtre/çıktı içindir —
     * proje maliyet raporuna GİRMEZ (yalnız cari ekstresini gruplar/süzer).
     */
    public function up(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('party_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
