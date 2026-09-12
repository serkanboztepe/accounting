<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stok defteri — proje maliyeti (contract_deliveries) dünyasından TAMAMEN ayrı.
        // Stok = Σ(giren) − Σ(çıkan). contract_deliveries'e dokunulmaz.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // Stok yalnız katalog ürününde tutulur (hizmet/serbest kalem yok).
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Yön: giren (in) / çıkan (out)
            $table->string('direction');
            // Sebep: purchase (mal girişi) · sale (satış) · return (iade) · adjustment (sayım/açılış)
            $table->string('reason')->default('purchase');

            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('amount', 15, 2)->nullable();

            // Kimden aldım / kime sattım (opsiyonel)
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            // Hangi şantiye (opsiyonel — depo hareketinde boş)
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            // Sözleşmeyle ilişkiliyse izlenebilirlik linki (maliyete karışmaz)
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();

            $table->date('movement_date');

            // İade → orijinal satış hareketine bağ (net sevk + fazla iade uyarısı)
            $table->foreignId('related_movement_id')->nullable()
                ->constrained('stock_movements')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
