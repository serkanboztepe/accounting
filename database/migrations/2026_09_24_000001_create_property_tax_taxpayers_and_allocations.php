<?php

use App\Models\PropertyTaxProject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paylı mülkiyet: çok mükellef + daire↔mükellef atama (hisse pay/payda).
 * Kat Karşılığı deseni (LandShareholder + LandSectionAllocation) ile aynı.
 *
 * - property_tax_taxpayers: projeye bağlı mükellefler
 * - property_tax_unit_taxpayer: daire ↔ mükellef pivot + hisse (pay/payda)
 * - property_tax_units.land_share_denominator: arsa payı paydası artık daire bazında
 *
 * Mevcut veri korunur: her projenin tek mükellefi "ilk mükellef" olarak taşınır,
 * tüm daireleri o mükellefe tam hisse (1/1) ile atanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_tax_taxpayers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_tax_project_id')->constrained('property_tax_projects')->cascadeOnDelete();
            $table->string('surname');                       // Soyadı (Unvanı) / Adı Soyadı
            $table->string('first_name')->nullable();        // Adı (ayrı istenirse)
            $table->string('tax_id')->nullable();            // TC / VKN
            $table->string('property_registry_no')->nullable();
            $table->string('phone_area_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax_area_code')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->string('filer_role')->default('taxpayer'); // taxpayer | proxy
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Arsa payı paydası daire bazında (pay zaten land_share_numerator'da)
        Schema::table('property_tax_units', function (Blueprint $table) {
            $table->unsignedInteger('land_share_denominator')->nullable()->after('land_share_numerator');
        });

        Schema::create('property_tax_unit_taxpayer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_tax_unit_id')->constrained('property_tax_units')->cascadeOnDelete();
            $table->foreignId('property_tax_taxpayer_id')->constrained('property_tax_taxpayers')->cascadeOnDelete();
            $table->unsignedInteger('pay')->default(1);    // mükellefin bu dairedeki hissesi (pay)
            $table->unsignedInteger('payda')->default(1);  // hisse paydası
            $table->timestamps();
            // Kısa isim: MySQL identifier 64 karakter sınırı (otomatik ad çok uzun).
            $table->unique(['property_tax_unit_id', 'property_tax_taxpayer_id'], 'ptx_unit_taxpayer_unique');
        });

        // ── Mevcut veriyi taşı ─────────────────────────────────────────────
        PropertyTaxProject::query()->with('blocks.units')->get()->each(function (PropertyTaxProject $project) {
            $taxpayerId = DB::table('property_tax_taxpayers')->insertGetId([
                'property_tax_project_id' => $project->id,
                'surname'                 => $project->taxpayer_surname ?: '—',
                'first_name'              => $project->taxpayer_first_name,
                'tax_id'                  => $project->tax_id,
                'property_registry_no'    => $project->property_registry_no,
                'phone_area_code'         => $project->phone_area_code,
                'phone'                   => $project->phone,
                'fax_area_code'           => $project->fax_area_code,
                'fax'                     => $project->fax,
                'email'                   => $project->email,
                'filer_role'              => $project->filer_role ?: 'taxpayer',
                'sort_order'              => 0,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]);

            foreach ($project->blocks as $block) {
                foreach ($block->units as $unit) {
                    // arsa payı paydasını bloktan daireye kopyala
                    DB::table('property_tax_units')->where('id', $unit->id)
                        ->update(['land_share_denominator' => $block->land_share_denominator]);

                    // tüm daireleri ilk mükellefe tam hisse ile ata
                    DB::table('property_tax_unit_taxpayer')->insert([
                        'property_tax_unit_id'     => $unit->id,
                        'property_tax_taxpayer_id' => $taxpayerId,
                        'pay'                      => 1,
                        'payda'                    => 1,
                        'created_at'               => now(),
                        'updated_at'               => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_tax_unit_taxpayer');
        Schema::table('property_tax_units', function (Blueprint $table) {
            $table->dropColumn('land_share_denominator');
        });
        Schema::dropIfExists('property_tax_taxpayers');
    }
};
