<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saatsiz (gün) hatırlatmasının gönderileceği saat (Ferhat 25.10: "Hatırlatmaları saat 10:00 da yap" — 30 Ekim ödemesi
 * 29 + 30 Ekim 08:00 yerine 10:00). Boş = 08:00. Olayın kendi saati (randevu 11'de) event_time'dır, bu değil.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reminders', 'notify_time')) {
            Schema::table('reminders', fn (Blueprint $table) => $table->time('notify_time')->nullable()->after('event_time'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reminders', 'notify_time')) {
            Schema::table('reminders', fn (Blueprint $table) => $table->dropColumn('notify_time'));
        }
    }
};
