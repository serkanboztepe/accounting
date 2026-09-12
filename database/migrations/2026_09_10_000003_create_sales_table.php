<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sözleşmesiz direkt satış başlığı (fiş). Kalemleri = stock_movements (out/sale).
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('sale_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Satış kalemi = stok çıkışı; satışa bağ (silinince stok geri döner).
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('contract_id')
                ->constrained()->cascadeOnDelete();
        });

        // Satışın cari borç kaydı; satış silinince borç da geri döner.
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('project_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
        Schema::dropIfExists('sales');
    }
};
