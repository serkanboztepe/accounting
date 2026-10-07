<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp hub'ı (APP_ROLE=hub) tabloları: telefon → firma kurulumu.
 * Firma kurulumlarında da oluşur ama boş kalır (kod tabanı tek).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_firms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');      // https://yildiz.boztepeler.com
            $table->string('secret');   // firmanın .env HUB_SECRET'ı ile aynı
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hub_phones', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();  // normalize: 905321234567
            $table->foreignId('hub_firm_id')->constrained('hub_firms')->cascadeOnDelete();
            $table->string('name')->nullable(); // kimin telefonu (not)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_phones');
        Schema::dropIfExists('hub_firms');
    }
};
