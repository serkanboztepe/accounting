<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('check_number')->nullable()->index();
            $table->string('bank_name')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->index();
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('portfolio')->index();
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checks');
    }
};
