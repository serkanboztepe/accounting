<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Günlük konuşma analizi raporları (merkez / hub). Her gece tüm firmaların WhatsApp konuşmaları
 * Claude'a okutulur; numaralı rapor (id = rapor no) burada durur. İçerik MASKELİ: carilerin adları
 * ve tutarlar rapora geçmez (müşterinin müşterisi verisi merkezde ham durmasın).
 * Firma veritabanlarında da oluşur ama boş kalır (kod tabanı tek).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reports', function (Blueprint $table) {
            $table->id();                                  // rapor numarası
            $table->date('report_date')->unique();         // hangi günün konuşmaları
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedSmallInteger('firm_count')->default(0);
            $table->string('summary', 500);                // WhatsApp özeti (tek satır)
            $table->longText('body');                      // rapor (markdown)
            $table->string('model')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamp('sent_at')->nullable();      // WhatsApp özeti gönderildi
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reports');
    }
};
