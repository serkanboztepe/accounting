<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land_share_studies', function (Blueprint $table) {
            // Mimari projedeki arsa payı cetvelinin ortak paydası (ör. 1000).
            // BB'lerin arsa_payda'sı boşsa bundan dolar; boşsa arsa payı kullanılmaz.
            $table->integer('land_share_denominator')->nullable()->after('units_per_block');
        });
    }

    public function down(): void
    {
        Schema::table('land_share_studies', function (Blueprint $table) {
            $table->dropColumn('land_share_denominator');
        });
    }
};
