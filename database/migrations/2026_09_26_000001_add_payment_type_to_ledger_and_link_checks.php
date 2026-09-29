<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_ledger_entries', function (Blueprint $table): void {
            // Tahsilat/ödeme yöntemi: nakit / havale / eft / çek / diğer (nullable — eski kayıtlar boş).
            $table->string('payment_type')->nullable()->after('type');
        });

        Schema::table('checks', function (Blueprint $table): void {
            // Çekin kaynağı: bu tahsilat satırı (çekle tahsilat yapılınca dolar).
            $table->foreignId('party_ledger_entry_id')->nullable()->after('contract_payment_id')
                ->constrained('party_ledger_entries')->nullOnDelete();
            // Karşılıksız işaretlenince oluşan ters borç satırı (idempotentlik + geri alma için).
            $table->foreignId('bounce_entry_id')->nullable()->after('party_ledger_entry_id')
                ->constrained('party_ledger_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bounce_entry_id');
            $table->dropConstrainedForeignId('party_ledger_entry_id');
        });

        Schema::table('party_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('payment_type');
        });
    }
};
