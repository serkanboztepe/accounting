<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land_share_studies', function (Blueprint $table) {
            // Üstte girilen sayılar — kaydedince bloklar/BB otomatik oluşur.
            $table->integer('block_count')->nullable()->after('parsel');
            $table->integer('units_per_block')->nullable()->after('block_count');
        });
    }

    public function down(): void
    {
        Schema::table('land_share_studies', function (Blueprint $table) {
            $table->dropColumn(['block_count', 'units_per_block']);
        });
    }
};
