<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "3 günde 1 adet gidiyor" (Oktay 18.10: 0,33/gün yazılıyordu) — günlük miktar kaç günde bir düşülür (boş = her gün). */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('usage_every_days')->nullable()->after('daily_usage');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('usage_every_days');
        });
    }
};
