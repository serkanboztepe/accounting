<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            // Yapı Sahibi — bina krokisinde (en altta) gösterilir. Proje bazında tek.
            $table->string('building_owner')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->dropColumn('building_owner');
        });
    }
};
