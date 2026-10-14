<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Akşam ciro sorusu numara bazında: firmada işaretli numara varsa yalnız onlara, yoksa tüm numaralara
 * (Akkuş Fırın: 3 numara, soru tek kişiye gitsin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_phones', function (Blueprint $table) {
            $table->boolean('receives_revenue_prompt')->default(false)->after('nudge_unanswered');
        });
    }

    public function down(): void
    {
        Schema::table('hub_phones', function (Blueprint $table) {
            $table->dropColumn('receives_revenue_prompt');
        });
    }
};
