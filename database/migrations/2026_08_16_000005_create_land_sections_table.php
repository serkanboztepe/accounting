<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bağımsız bölümler (BB). v1'de düz kayıt — eklenti/parent bağlama yok.
        Schema::create('land_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained('land_blocks')->cascadeOnDelete();
            $table->string('bb_no');
            $table->string('type')->default('daire'); // daire/dukkan/ofis/depo/diger
            $table->string('floor')->nullable();
            // Yalnız ARSA PAYLI yönteminde doldurulur.
            $table->integer('arsa_pay')->nullable();
            $table->integer('arsa_payda')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_sections');
    }
};
