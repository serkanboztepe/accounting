<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alış belgesi (Alışlar tezgahı, satışın aynası): kalemler = stok girişleri, toplam = cari borcumuz
 * (cariden alındıysa). Peşin alışta alış + ödeme birlikte yazılır. Caride basit "Alış Yaptım" bundan
 * bağımsızdır ve değişmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('purchase_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('manual_amount', 15, 2)->nullable();   // ürünsüz tutar
            $table->boolean('paid')->default(false);               // peşin: parası verildi
            $table->string('payment_type', 20)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->after('sale_return_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->after('sale_return_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
        });
        Schema::dropIfExists('purchases');
    }
};
