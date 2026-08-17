<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->foreignId('contract_item_id')
                ->nullable()
                ->after('contract_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_item_id');
        });
    }
};
