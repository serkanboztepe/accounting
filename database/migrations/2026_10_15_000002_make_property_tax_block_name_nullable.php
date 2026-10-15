<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blok adı formda bilerek boş geçilebilir (tek apartman: krokide/beyannamede blok adı yazılmaz),
 * ama kolon NOT NULL'du → boş adla kayıt patlıyordu (v1 ve v2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tax_blocks', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('property_tax_blocks', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
