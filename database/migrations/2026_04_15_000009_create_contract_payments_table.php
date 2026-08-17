<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contract_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->date('payment_date')->nullable()->index();
            $table->date('due_date')->nullable()->index();
            $table->string('payment_type')->default('bank_transfer')->index();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending')->index();
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_payments');
    }
};
