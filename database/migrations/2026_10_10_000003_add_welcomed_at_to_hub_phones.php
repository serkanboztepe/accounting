<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Hub: karşılama şablonu bu numaraya ne zaman gönderildi — gönderildiyse "Hoş geldin gönder" gizlenir. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_phones', function (Blueprint $table) {
            $table->timestamp('welcomed_at')->nullable()->after('receives_reminders');
        });
    }

    public function down(): void
    {
        Schema::table('hub_phones', function (Blueprint $table) {
            $table->dropColumn('welcomed_at');
        });
    }
};
