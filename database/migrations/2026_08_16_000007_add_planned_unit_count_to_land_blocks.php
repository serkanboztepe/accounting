<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land_blocks', function (Blueprint $table) {
            // Bloğa girilen hedef daire sayısı — "Bağımsız bölümleri oluştur"
            // aksiyonu bu sayıya göre BB üretir.
            $table->integer('planned_unit_count')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('land_blocks', function (Blueprint $table) {
            $table->dropColumn('planned_unit_count');
        });
    }
};
