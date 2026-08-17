<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_shareholders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_id')->constrained('land_share_studies')->cascadeOnDelete();
            // Cari bağı opsiyonel: müteahhit genelde Cari, aile bireyleri serbest isim.
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->string('name');
            $table->integer('current_pay')->default(0);
            $table->integer('current_payda')->default(1);
            $table->boolean('is_contractor')->default(false);
            // Yalnız GRUP yönteminde kullanılır.
            $table->string('group_key')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_shareholders');
    }
};
