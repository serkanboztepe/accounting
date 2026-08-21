<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Biz kimiz" — tek satır. Çıktılarda (teklif/sözleşme) firma başlığı olarak kullanılır.
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();          // Ünvan (zorunlu alan MVP'de)
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_office')->nullable();      // Vergi dairesi
            $table->string('tax_number')->nullable();      // Vergi no
            $table->text('quote_template')->nullable();    // Teklif çıktı şablonu (yer tutuculu)
            $table->text('contract_template')->nullable(); // Sözleşme çıktı şablonu
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
