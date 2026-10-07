<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Başlangıç tarihi artık sorulmuyor (= oluşturma günü) — boş olanları doldur. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projects')
            ->whereNull('start_date')
            ->update(['start_date' => DB::raw('DATE(created_at)')]);
    }

    public function down(): void
    {
        //
    }
};
