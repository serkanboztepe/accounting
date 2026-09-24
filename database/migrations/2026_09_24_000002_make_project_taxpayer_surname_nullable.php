<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mükellef bilgisi artık property_tax_taxpayers tablosunda; projedeki eski
 * taxpayer_surname alanı vestigial — yeni proje açılırken zorunlu olmasın.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->string('taxpayer_surname')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->string('taxpayer_surname')->nullable(false)->change();
        });
    }
};
