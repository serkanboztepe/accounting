<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kayıt kontrolleri (Faz 3.1, merkez / hub): gece analizi "yanlış anlaşılmış olabilecek kayıt" bulunca öneri düşer; yönetici
 * hub'da "Müşteriye sor" ya da "Gerek yok" der. Soru maskesi kaldırılmış (firma içi) gerçek adla gider; cevap buraya yazılır,
 * düzeltmeyi asistan müşterinin onayıyla yapar. Firma veritabanlarında da oluşur ama boş kalır (kod tabanı tek).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_reviews')) {
            return;
        }
        Schema::create('record_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_report_id')->nullable();
            $table->unsignedBigInteger('hub_firm_id')->nullable();
            $table->string('firm_name')->nullable();
            $table->string('user_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('happened_at')->nullable();     // "13.10 12:41" (analizdeki gibi)
            $table->text('problem');                        // ne yanlış kaydedilmiş olabilir
            $table->text('fix')->nullable();                // doğrusu ne olabilir
            $table->text('question')->nullable();           // müşteriye sorulacak (gerçek adla)
            $table->string('status', 12)->default('pending'); // pending | asked | dismissed | resolved
            $table->timestamp('asked_at')->nullable();
            $table->unsignedBigInteger('asked_announcement_id')->nullable();
            $table->text('customer_answer')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'hub_firm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_reviews');
    }
};
