<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ödeme / tahsilat hangi anlaşma için (kullanıcı 26.10): anlaşma özeti "ne ödendi, ne kaldı" gösterir.
 * Boş = anlaşma dışı ("Diğer alışlar / satışlar"). Eski kayıtlar boş kalır; anlaşma silinirse boşalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('party_ledger_entries', 'agreement_id')) {
            return;
        }
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->foreignId('agreement_id')->nullable()->after('purchase_return_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreement_id');
        });
    }
};
