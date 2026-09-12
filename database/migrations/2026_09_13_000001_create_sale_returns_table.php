<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // İade başlığı (parti) — her "İade Al" işlemi bir sale_return.
        // Silinince stok girişleri + cari alacağı birlikte geri döner (cascade).
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('return_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sale_return_id')->nullable()->after('sale_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->foreignId('sale_return_id')->nullable()->after('sale_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_return_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_return_id');
        });
        Schema::dropIfExists('sale_returns');
    }
};
