<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blok seviyesinde varsayılan arsa payı PAY'ı da tutulsun (payda zaten vardı).
 * Böylece "Varsayılan Arsa Payı" pay/payda olarak girilir; daire kendi
 * pay/paydasını girmezse bloğunkini devralır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tax_blocks', function (Blueprint $table) {
            $table->unsignedInteger('land_share_numerator')->nullable()->after('land_share_denominator');
        });
    }

    public function down(): void
    {
        Schema::table('property_tax_blocks', function (Blueprint $table) {
            $table->dropColumn('land_share_numerator');
        });
    }
};
