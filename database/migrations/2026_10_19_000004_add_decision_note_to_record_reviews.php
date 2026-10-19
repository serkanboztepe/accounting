<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kayıt kontrolü kapatılırken not (kullanıcı 19.10: "olayın nasıl çözüldüğünü göreyim; AI da sonra kullanır"). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_reviews') && ! Schema::hasColumn('record_reviews', 'decision_note')) {
            Schema::table('record_reviews', fn (Blueprint $table) => $table->text('decision_note')->nullable()->after('decided_at'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('record_reviews', 'decision_note')) {
            Schema::table('record_reviews', fn (Blueprint $table) => $table->dropColumn('decision_note'));
        }
    }
};
