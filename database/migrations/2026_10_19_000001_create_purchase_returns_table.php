<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alış iadesi (satış iadesinin aynası): alınan malın bir kısmı / tamamı tedarikçiye geri verildi. Alış silinmez;
 * iade başlığı + stok çıkışı (out/return) + cari "alış iadesi" (borcumuz azalır). Silinince stok ve cari birlikte döner.
 * Belgesiz basit alışın iadesi başlıksızdır — yalnız cari satırı (purchase_return_id boş).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('return_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_return_id')->nullable()->after('purchase_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->foreignId('purchase_return_id')->nullable()->after('purchase_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_return_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_return_id');
        });
        Schema::dropIfExists('purchase_returns');
    }
};
