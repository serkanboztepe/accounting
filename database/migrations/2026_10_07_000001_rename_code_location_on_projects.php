<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proje "Kod" → "Ada / Parsel", "Konum" → "Adres".
 * Kolon adı property_tax_projects.cadastral_parcel ile aynı — ileride eşleştirme için.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['code']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('code', 'cadastral_parcel');
            $table->renameColumn('location', 'address');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->text('address')->nullable()->change();
            $table->index('cadastral_parcel');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['cadastral_parcel']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->renameColumn('cadastral_parcel', 'code');
            $table->renameColumn('address', 'location');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index('code');
        });
    }
};
