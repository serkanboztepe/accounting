<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Otomatik stoktan düş (fırın: "20 çuval un aldım, günde 3 kullanıyorum, 6 kalınca haber ver").
 * Açıksa her sabah günlük miktar gerçek stok çıkışı ("Kullanım") olarak yazılır; stok eşiğe inince
 * sabah 08:00 WhatsApp'tan "bitmek üzere" denir (ürün başına bir kez, eşiğin üstüne çıkınca sıfırlanır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('auto_usage')->default(false)->after('is_active');
            $table->decimal('daily_usage', 15, 2)->nullable()->after('auto_usage');
            $table->decimal('reorder_level', 15, 2)->nullable()->after('daily_usage');
            $table->date('usage_booked_through')->nullable()->after('reorder_level');
            $table->timestamp('low_stock_alerted_at')->nullable()->after('usage_booked_through');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['auto_usage', 'daily_usage', 'reorder_level', 'usage_booked_through', 'low_stock_alerted_at']);
        });
    }
};
