<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emlak beyanı projesini (opsiyonel) ERP projesine bağla.
 * Nullable: dış müşteri binaları ERP projesine bağlı olmadan da girilebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('name')
                ->constrained('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_tax_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
