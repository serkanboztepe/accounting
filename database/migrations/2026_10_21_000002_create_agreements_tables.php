<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anlaşmalar (Faz 6, 21.10): sözleşmenin sade hali — cariyle "şunu şu fiyattan alacağız / satacağız".
 * Anlaşma borç yazmaz; teslimat normal alış/satış belgesidir, kalemi anlaşma kalemine bağlanır
 * (stock_movements.agreement_item_id). Gelen/kalan saklanmaz, teslimatlardan hesaplanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreements', function (Blueprint $table) {
            $table->id();
            // Cari silinirken anlaşma sessizce gitmesin: PartyDeletion "N anlaşma bağlı" der.
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('direction', 20);                // purchase | sale
            // Varsayılan proje — maliyet teslimatın projesine yazılır (teslimde değiştirilebilir).
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('agreement_date');
            $table->decimal('budget', 15, 2)->nullable();   // yalnız fiyat listesinde (miktarsız kalem)
            $table->string('status', 20)->default('open');  // open | done | closed
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['party_id', 'status']);
        });

        Schema::create('agreement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2)->nullable(); // boş = fiyat listesi (miktarsız)
            $table->decimal('unit_price', 15, 2)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->boolean('is_lump')->default(false);     // götürü: quantity 1, unit_price = toplam
            $table->timestamp('closed_at')->nullable();     // fiyat değişince eski kalem kapanır
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('agreement_item_id')->nullable()->after('purchase_return_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreement_item_id');
        });
        Schema::dropIfExists('agreement_items');
        Schema::dropIfExists('agreements');
    }
};
