<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // contract_deliveries: quantity zorunlu yap + unit_id ekle
        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->decimal('quantity', 15, 2)->nullable(false)->change();
            $table->foreignId('unit_id')->nullable()->after('quantity')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->decimal('quantity', 15, 2)->nullable()->change();
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};