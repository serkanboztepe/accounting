<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ders → "Müşteriye sor" (Faz 3): yönetici emin değilse soruyu müşteriye WhatsApp'tan gönderir; cevabı derse yazılır. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('assistant_lessons', 'customer_question')) {
            return;
        }
        Schema::table('assistant_lessons', function (Blueprint $table) {
            $table->unsignedBigInteger('source_firm_id')->nullable()->after('hub_firm_id'); // dersin çıktığı firma (genel derste de)
            $table->text('customer_question')->nullable()->after('expected');               // analizcinin önerdiği nazik soru
            $table->string('asked_phone', 32)->nullable()->after('customer_question');
            $table->timestamp('asked_at')->nullable()->after('asked_phone');
            $table->unsignedBigInteger('asked_announcement_id')->nullable()->after('asked_at'); // 24 saat dışı: sonraki mesajda gider
            $table->text('customer_answer')->nullable()->after('asked_announcement_id');
            $table->timestamp('answered_at')->nullable()->after('customer_answer');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_lessons', function (Blueprint $table) {
            $table->dropColumn(['source_firm_id', 'customer_question', 'asked_phone', 'asked_at', 'asked_announcement_id', 'customer_answer', 'answered_at']);
        });
    }
};
