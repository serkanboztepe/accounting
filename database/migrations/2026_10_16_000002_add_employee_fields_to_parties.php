<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Personel = cari + işaret (ayrı modül yok): maaş tutarı ve ödeme günü (maaş günü hatırlatması, avans mahsubu). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('parties', 'is_employee')) {
            return;
        }
        Schema::table('parties', function (Blueprint $table) {
            $table->boolean('is_employee')->default(false)->after('notes');
            $table->decimal('salary_amount', 15, 2)->nullable()->after('is_employee');
            $table->unsignedTinyInteger('salary_day')->nullable()->after('salary_amount');
        });
    }

    public function down(): void
    {
        Schema::table('parties', function (Blueprint $table) {
            $table->dropColumn(['is_employee', 'salary_amount', 'salary_day']);
        });
    }
};
