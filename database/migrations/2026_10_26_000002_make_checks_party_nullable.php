<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carisiz çek (kullanıcı 26.10): Çekler ekranında cari seçilmeden de çek kaydedilebilir — yalnız çek olarak durur,
 * cariye işlenmez (CheckLedger::canWrite cari ister). Var olan çeklerin carisi değişmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', fn (Blueprint $table) => $table->foreignId('party_id')->nullable()->change());
    }

    public function down(): void
    {
        // Carisiz çek varsa geri dönüş durur (veri kaybolmasın).
        Schema::table('checks', fn (Blueprint $table) => $table->foreignId('party_id')->nullable(false)->change());
    }
};
