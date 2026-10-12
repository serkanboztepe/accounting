<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Peşin (carisiz) satış + ürünsüz tutar (Satışlar tezgahı). Yalnız EKLEME: party_id boş olabilir hale gelir
 * (mevcut satışların hepsi carili, değişmez); nakit/kart dağılımı ve ürünsüz tutar yeni boş sütunlar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->change();
            $table->decimal('manual_amount', 15, 2)->nullable()->after('total_amount');   // ürünsüz tutar (toplu ciro)
            $table->decimal('cash_amount', 15, 2)->nullable()->after('manual_amount');    // peşin: nakit kısmı
            $table->decimal('bank_amount', 15, 2)->nullable()->after('cash_amount');      // peşin: kart/banka kısmı
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['manual_amount', 'cash_amount', 'bank_amount']);
        });
    }
};
