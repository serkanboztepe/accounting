<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Giderden kişi sökümü (kullanıcı 28.10): gider = kimseye bağlı olmayan harcama; kişiyle olan her şey kişinin hesabında.
 * Eski kişili giderler firms:expense-parties-to-ledger (fa0a0e2) ile alışa taşındı — 28.10'da tüm firmalarda 0.
 * Emniyet: kişili gider kalmışsa sütun SİLİNMEZ, migration durur (müşteri verisi kendiliğinden taşınmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'party_id')) {
            return;
        }
        $left = DB::table('expenses')->whereNotNull('party_id')->count();
        if ($left > 0) {
            throw new RuntimeException("expenses.party_id silinmedi: {$left} kişili gider var — önce kişinin hesabına taşınmalı (fa0a0e2 komutu).");
        }
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_id');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });
    }
};
