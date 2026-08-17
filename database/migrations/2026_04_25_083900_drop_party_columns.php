<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'email',
                'tax_number',
                'tax_office',
                'identity_number',
                'address',
                'is_active',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->string('type')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('tax_office')->nullable();
            $table->string('identity_number')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }
};
