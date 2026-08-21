<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ödeme planı (tip + tarih + tutar) — belge/çıktı içindir.
     * Gerçek ödeme defteri (contract_payments) DEĞİLDİR; tahsilat sayılmaz,
     * "kalan" hesaplarına girmez. Dönüştürmede teklifin planı sözleşmeye kopyalanır.
     */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('payment_plan')->nullable()->after('notes');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->json('payment_plan')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('payment_plan');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('payment_plan');
        });
    }
};
