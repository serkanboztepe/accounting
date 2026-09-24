<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arsa Alanı proje bazında tek olmalı (mimar) — bloktan projeye taşı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->decimal('land_area', 15, 2)->nullable()->after('cadastral_parcel');
        });

        // Her projeye, bloklarından ilk dolu arsa alanını kopyala
        if (Schema::hasColumn('property_tax_blocks', 'land_area')) {
            DB::table('property_tax_projects')->orderBy('id')->each(function ($project) {
                $area = DB::table('property_tax_blocks')
                    ->where('property_tax_project_id', $project->id)
                    ->whereNotNull('land_area')
                    ->value('land_area');
                if ($area !== null) {
                    DB::table('property_tax_projects')->where('id', $project->id)->update(['land_area' => $area]);
                }
            });

            Schema::table('property_tax_blocks', function (Blueprint $table) {
                $table->dropColumn('land_area');
            });
        }
    }

    public function down(): void
    {
        Schema::table('property_tax_blocks', function (Blueprint $table) {
            $table->decimal('land_area', 15, 2)->nullable();
        });
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->dropColumn('land_area');
        });
    }
};
