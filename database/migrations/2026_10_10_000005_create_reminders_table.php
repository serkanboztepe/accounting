<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp hatırlatmaları (firma veritabanı). Kalıp: OLAY (gün + varsa saat) + NE KADAR ÖNCE.
 * Hatırlatma saatleri kodda hesaplanır (Reminder::alarmsFor): saat varsa 1 saat önce, yalnız gün
 * varsa 1 gün önce 08:00 + o gün 08:00; "X'te hatırlat" (is_alarm) tam o an. next_fire_at = sıradaki.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32);                    // isteyen telefon (Phone::normalize)
            $table->string('text', 500);                    // ne: "düğün çekimi", "Ali'den parayı al"
            $table->foreignId('party_id')->nullable()->constrained()->nullOnDelete();
            $table->date('event_date');                     // olay günü (tekrarlıda sıradaki)
            $table->time('event_time')->nullable();         // söylendiyse saat
            $table->unsignedInteger('lead_minutes')->nullable(); // söylenen "ne kadar önce" (dk); null = varsayılan
            $table->boolean('is_alarm')->default(false);    // "15'te hatırlat" → tam o an, öncesi yok
            $table->string('repeat', 10)->nullable();       // null | weekly | monthly | yearly
            $table->unsignedTinyInteger('anchor_day')->nullable(); // aylık/yıllıkta asıl gün (31 → kısa ayda 30/28, sonra yine 31)
            $table->dateTime('next_fire_at')->nullable();   // sıradaki hatırlatma; null = bitti
            $table->string('status', 12)->default('active'); // active | done | cancelled | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('last_sent_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable(); // "tamam" / bağlı tahsilat-ödeme onaylandı
            $table->timestamps();

            $table->index(['status', 'next_fire_at']);
            $table->index(['phone', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
