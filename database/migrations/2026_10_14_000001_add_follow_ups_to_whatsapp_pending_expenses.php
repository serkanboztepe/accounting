<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yarım kalan kayıt takibi: onay bekleyen taslak en fazla 2 kez hatırlatılır
 * (iş bitince cevabın sonunda ya da 30 dk sessizlikte ayrı mesajla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_pending_expenses', function (Blueprint $table) {
            $table->unsignedTinyInteger('follow_ups')->default(0)->after('status');
            $table->timestamp('followed_up_at')->nullable()->after('follow_ups');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_pending_expenses', function (Blueprint $table) {
            $table->dropColumn(['follow_ups', 'followed_up_at']);
        });
    }
};
