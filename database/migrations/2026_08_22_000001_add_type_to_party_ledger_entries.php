<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cari hareket tipi (çift yönlü): satis | alis | tahsilat | odeme.
     * Yön (borç/alacak) tipten türetilir. direction kolonu bakiye hesabı için
     * senkron tutulur (model saving hook'u).
     */
    public function up(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->string('type')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
