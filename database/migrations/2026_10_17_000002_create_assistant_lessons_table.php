<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistanın dersleri (Faz 3, merkez / hub): her gece konuşma analizi asistanın hatalarından ders ÖNERİR; yönetici hub'da
 * onaylar. Onaylı ders asistanın talimatına girer (firma dersi yalnız o firmaya, genel ders herkese). İçerik MASKELİ
 * (cari adları "Cari-N", tutarlar gizli). Firma veritabanlarında da oluşur ama boş kalır (kod tabanı tek).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_lessons')) {
            return;
        }
        Schema::create('assistant_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_report_id')->nullable();   // hangi gece raporundan
            $table->unsignedBigInteger('hub_firm_id')->nullable();     // null = genel ders (tüm firmalar)
            $table->string('firm_name')->nullable();
            $table->text('problem');                                   // ne oldu (kısa alıntı)
            $table->text('cause')->nullable();                         // neden
            $table->text('rule');                                      // asistana kural (tek cümle)
            $table->text('example_message')->nullable();               // deneme vakası: kullanıcı mesajı
            $table->text('expected')->nullable();                      // deneme vakası: beklenen davranış
            $table->string('status', 12)->default('pending');          // pending | approved | rejected | retired
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'hub_firm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_lessons');
    }
};
