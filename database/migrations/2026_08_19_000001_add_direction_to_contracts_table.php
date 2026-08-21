<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // alim  = tedarikçi/taşeron sözleşmesi (para çıkışı, maliyete girer) — varsayılan
            // satis = tekliften doğan satış sözleşmesi (alacak, para girişi, maliyete GİRMEZ)
            $table->string('direction')->default('alim')->index()->after('contract_type');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }
};
