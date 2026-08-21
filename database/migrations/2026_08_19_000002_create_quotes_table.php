<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->index();
            $table->date('quote_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            // taslak | gonderildi | kabul | red | suresi_doldu
            $table->string('status')->default('taslak')->index();
            // Dönüştürülünce hangi sözleşmeye bağlandı (geri izleme)
            $table->foreignId('converted_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
