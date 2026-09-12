<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kaleme kanonik ürün/hizmet kimliği (nullable — kademeli geçiş, serbest metin korunur).
        Schema::table('contract_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('contract_id')
                ->constrained()->nullOnDelete();
        });

        // Teslimat, ürün kimliğini kaleminden miras alır; sözleşmesiz hareketler (stok girişi,
        // direkt satış) için de doğrudan ürüne bağlanabilsin diye burada da tutulur.
        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('contract_item_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
