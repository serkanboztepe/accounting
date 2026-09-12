<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Bu proje bu müşteriye ait" — nullable sahip cari.
        // Kullanıcının kendi inşaat projeleri sahipsiz (null) kalır; zorunlu değil.
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('name')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('party_id');
        });
    }
};
