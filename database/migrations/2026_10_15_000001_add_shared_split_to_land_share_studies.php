<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land_share_studies', function (Blueprint $table) {
            // Ortak BB'de hisse nasıl bölünür: null/'equal' = eşit, 'existing' = kişilerin mevcut hissesi oranında.
            $table->string('shared_split', 16)->nullable()->after('land_share_denominator');
        });
    }

    public function down(): void
    {
        Schema::table('land_share_studies', function (Blueprint $table) {
            $table->dropColumn('shared_split');
        });
    }
};
