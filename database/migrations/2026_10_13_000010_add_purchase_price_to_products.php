<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alış fiyatı (fırın: "unu 850'den alıyorum"). default_price SATIŞ fiyatıdır; fiyatı söylenmeyen alış
 * ("Boztepe'den 10 un geldi") artık son alış fiyatıyla hesaplanır, satış fiyatıyla değil. Her alışta güncellenir.
 * Yalnız ekleme: boş sütun, mevcut veriye dokunmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_price', 15, 2)->nullable()->after('default_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('purchase_price');
        });
    }
};
