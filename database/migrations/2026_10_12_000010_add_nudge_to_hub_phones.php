<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akşam mesajı ("Bugün aklında bir şey var mı?"): numara bazında açık/kapalı ("bana yazma" kapatır),
 * son gönderim ve üst üste cevapsız sayısı (3 olunca haftada bire düşer; kişi yazınca sıfırlanır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_phones', function (Blueprint $table) {
            $table->boolean('nudge_enabled')->default(true)->after('welcomed_at');
            $table->timestamp('nudge_sent_at')->nullable()->after('nudge_enabled');
            $table->unsignedTinyInteger('nudge_unanswered')->default(0)->after('nudge_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('hub_phones', function (Blueprint $table) {
            $table->dropColumn(['nudge_enabled', 'nudge_sent_at', 'nudge_unanswered']);
        });
    }
};
