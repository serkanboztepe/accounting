<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // 'product' = Ürün (stoklu), 'service' = Hizmet (stoksuz)
            $table->string('type')->default('product');
            $table->string('category')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            // Varsayılan satış/hizmet fiyatı — seçince otomatik gelir
            $table->decimal('default_price', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Stok kolonu YOK — mevcut, hareketlerden (giren−çıkan) türetilir.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
