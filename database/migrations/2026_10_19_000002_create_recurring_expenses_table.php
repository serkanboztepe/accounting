<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Düzenli gider (Oktay 18.10: "çobanlar günde 2 ekmek, 17,30 TL — her gün gider olarak ekle"): her gün / hafta / ay
     * kendiliğinden gider kaydı. Yazılan her gider kaynağına bağlı (recurring_expense_id) — aynı gün iki kez yazılmaz.
     */
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency', 10); // daily | weekly | monthly
            $table->unsignedTinyInteger('weekday')->nullable(); // haftalık: 1 = pazartesi … 7 = pazar
            $table->unsignedTinyInteger('day_of_month')->nullable(); // aylık: ayın kaçı (ay kısaysa son gün)
            $table->date('start_date');
            $table->date('posted_through')->nullable(); // bu güne kadar yazıldı
            $table->boolean('paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('created_by_phone', 32)->nullable();
            $table->text('source')->nullable();
            $table->timestamps();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')->nullable()->after('expense_category_id')->constrained()->nullOnDelete();
            $table->unique(['recurring_expense_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['recurring_expense_id', 'expense_date']);
            $table->dropConstrainedForeignId('recurring_expense_id');
        });
        Schema::dropIfExists('recurring_expenses');
    }
};
