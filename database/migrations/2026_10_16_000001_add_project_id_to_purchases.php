<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Alış belgesinin projesi / şubesi (satışta zaten var): stok hareketine ve cari satırına da yazılır. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchases', 'project_id')) {
            return;
        }
        Schema::table('purchases', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('party_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
