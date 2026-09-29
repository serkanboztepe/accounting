<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sözleşme ödemelerine ödeme durumu (giderdeki gibi): ödendi / ödenmedi.
 * Varsayılan ödenmedi — plan yerine "ödenmedi satır" mantığı. Çek ödemelerinde
 * bu durum bağlı çekin statüsüne yansır (ödenmedi→verildi, ödendi→ödendi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_payments', function (Blueprint $table): void {
            $table->string('status')->default('unpaid')->after('payment_type');
        });

        // Mevcut ödemeler status kavramından önce girildi — hepsi gerçek ödemeydi → 'paid'.
        // (Yeni satırlar default 'unpaid' gelir.)
        DB::table('contract_payments')->update(['status' => 'paid']);
    }

    public function down(): void
    {
        Schema::table('contract_payments', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
