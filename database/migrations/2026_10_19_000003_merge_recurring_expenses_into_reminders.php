<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Düzenli gider ayrı tablo değil, hatırlatmanın bir türü (kullanıcı kararı 19.10: "sürekli yeni tablo yeni menü canımı sıkıyor").
 * Tutarı olan hatırlatma o gün gider yazar: hatırlatmaya tutar + kategori + proje. recurring_expenses kaldırılır — yalnız boşsa
 * (canlıda 19.10 her firmada 0 satır); doluysa migration durur, veri kaybolmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recurring_expenses') && DB::table('recurring_expenses')->exists()) {
            throw new RuntimeException('recurring_expenses boş değil — önce hatırlatmalara taşınmalı.');
        }

        // Yarıda kalmış bir denemeden sonra tekrar çalışabilsin (MySQL'de DDL geri alınmaz) — her adım kendi kontrolüyle.
        if (! Schema::hasColumn('reminders', 'amount')) {
            Schema::table('reminders', fn (Blueprint $table) => $table->decimal('amount', 15, 2)->nullable()->after('repeat'));
        }
        if (! Schema::hasColumn('reminders', 'expense_category_id')) {
            Schema::table('reminders', fn (Blueprint $table) => $table->foreignId('expense_category_id')->nullable()->after('amount')->constrained()->nullOnDelete());
        }
        if (! Schema::hasColumn('reminders', 'project_id')) {
            Schema::table('reminders', fn (Blueprint $table) => $table->foreignId('project_id')->nullable()->after('expense_category_id')->constrained()->nullOnDelete());
        }

        // MySQL: yabancı anahtar tekil indeksi kullanıyor → önce anahtar, sonra indeks, sonra kolon (19.10 canlıda 1553).
        if (Schema::hasColumn('expenses', 'recurring_expense_id')) {
            Schema::table('expenses', fn (Blueprint $table) => $table->dropForeign(['recurring_expense_id']));
            Schema::table('expenses', fn (Blueprint $table) => $table->dropUnique(['recurring_expense_id', 'expense_date']));
            Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('recurring_expense_id'));
        }
        Schema::dropIfExists('recurring_expenses');
    }

    public function down(): void
    {
        Schema::table('reminders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
            $table->dropConstrainedForeignId('expense_category_id');
            $table->dropColumn('amount');
        });
    }
};
