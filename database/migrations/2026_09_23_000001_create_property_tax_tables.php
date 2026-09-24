<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emlak Vergisi Bildirimi (Bina) modülü — Property Tax Declaration.
 *
 * Hiyerarşi: Project → Block → Unit (Proje → Blok → Daire).
 * Ortak/değişmeyen bilgi üst seviyede tutulur, alt seviye devralır (miras).
 *   - Project: mükellef + belediye/konum + ada/parsel + beyan bilgileri (bir kez)
 *   - Block:   bina ortak özellikleri (inşaat türü/sınıfı, kalorifer, asansör… — bir kez)
 *   - Unit:    yalnız kendine özel alanlar (daire no, kat/sıra, yüzölçümü, arsa payı)
 *
 * Her blok kendi beyanname sayfalarını (daireler 3'erli) + krokisini üretir.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── PROJECT: ortak bilgi (tüm bloklar paylaşır) ────────────────────
        Schema::create('property_tax_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Proje/site adı (ör. "Yıldız Sitesi")

            // Taxpayer (Mükellef)
            $table->string('taxpayer_surname');            // Soyadı (Unvanı)
            $table->string('taxpayer_first_name')->nullable(); // Adı (gerçek kişi)
            $table->string('tax_id')->nullable();          // TC / VKN
            $table->string('property_registry_no')->nullable();
            $table->string('phone_area_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('fax_area_code')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();

            // Taxpayer address (opsiyonel)
            $table->string('owner_address_street')->nullable();
            $table->string('owner_address_lane')->nullable();
            $table->string('owner_address_door_no')->nullable();
            $table->string('owner_address_apartment_no')->nullable();
            $table->string('owner_address_district')->nullable();
            $table->string('owner_address_city')->nullable();
            $table->string('postal_code')->nullable();

            // Municipality / location (bloklar paylaşır)
            $table->string('city')->nullable();          // İl — ör. ERZİNCAN
            $table->string('district')->nullable();      // İlçe — ör. MERKEZ
            $table->string('municipality')->nullable();  // Belediye — ör. MERKEZ-ERZİNCAN
            $table->string('neighborhood')->nullable();  // Mahalle — ör. HOCABEY (unit override edebilir)
            $table->string('street')->nullable();        // Cadde/Sokak — ör. 1056 (unit override edebilir)
            $table->string('cadastral_parcel')->nullable(); // Ada/Parsel — ör. 880/294

            // Declaration (Beyan)
            $table->string('declaration_year')->nullable();
            $table->string('filing_reason')->default('first_acquisition'); // first_acquisition | change
            $table->string('filer_role')->default('taxpayer');            // taxpayer | proxy
            $table->date('declaration_date')->nullable();

            $table->timestamps();
        });

        // ── BLOCK: bina ortak özellikleri (unit'ler devralır) ──────────────
        Schema::create('property_tax_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_tax_project_id')->constrained('property_tax_projects')->cascadeOnDelete();
            $table->string('name'); // Blok adı (A Blok, B Blok…)

            $table->decimal('land_area', 15, 2)->nullable();        // Bina arsasının alanı (m²)
            $table->unsignedInteger('land_share_denominator')->nullable(); // arsa payı paydası (ör. 8 → 1/8)
            $table->string('building_door_no')->nullable();         // Bina/kapı no (ör. 3)

            // Ortak özellikler (unit null bırakırsa buradan devralır)
            $table->string('construction_type')->nullable();        // ör. B.ARME
            $table->string('construction_class')->nullable();       // ör. 3.SINIF
            $table->string('usage_type')->nullable();               // ör. MESKEN
            $table->date('construction_completion_date')->nullable();
            $table->date('acquisition_date')->nullable();
            $table->string('restriction_status')->nullable();       // ör. YOK
            $table->string('exemption_status')->nullable();         // ör. YOK
            $table->string('reduced_tax')->nullable();
            $table->string('share_ratio')->nullable();              // ör. TAM
            $table->boolean('has_heating')->default(false);
            $table->boolean('has_elevator')->default(false);

            $table->timestamps();
        });

        // ── UNIT: yalnız kendine özel alanlar (+ opsiyonel override) ────────
        Schema::create('property_tax_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_tax_block_id')->constrained('property_tax_blocks')->cascadeOnDelete();

            $table->string('unit_no');                          // 1, 2, 3…
            $table->unsignedInteger('floor_no')->nullable();    // kroki için
            $table->unsignedInteger('floor_position')->nullable(); // 1=sol, 2=sağ…
            $table->decimal('area', 15, 2)->nullable();         // Dıştan dışa yüzölçümü (m²)
            $table->unsignedInteger('land_share_numerator')->default(1); // arsa payı payı (ör. 1 → 1/8)

            // Override (null ise bloktan/projeden devralır)
            $table->string('usage_type')->nullable();
            $table->string('construction_class')->nullable();
            $table->string('share_ratio')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('street')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_tax_units');
        Schema::dropIfExists('property_tax_blocks');
        Schema::dropIfExists('property_tax_projects');
    }
};
