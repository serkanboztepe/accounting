<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BB dağılımı: bir BB kişilere pay/payda ile bölünür. Paylaşımlı BB'de
        // aynı section'a birden çok satır; toplamları 1/1 olmalı.
        Schema::create('land_section_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('land_sections')->cascadeOnDelete();
            $table->foreignId('shareholder_id')->constrained('land_shareholders')->cascadeOnDelete();
            $table->integer('pay')->default(1);
            $table->integer('payda')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_section_allocations');
    }
};
