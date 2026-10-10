<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cari hareketine isteğe bağlı ödeme tarihi (vade): "Ali'ye bal sattım, 14 Kasım'da ödeyecek".
 * Satış ve alış satırlarında kullanılır; ödeme tarihi sabahı hatırlatılır (ödendiyse hatırlatılmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('entry_date');
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
            $table->dropColumn('due_date');
        });
    }
};
