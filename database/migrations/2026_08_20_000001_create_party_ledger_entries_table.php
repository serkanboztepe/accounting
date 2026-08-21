<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manuel cari hareketleri (açılış bakiyesi / düzeltme / veresiye).
     * SADECE cari ekstresini etkiler — hiçbir proje maliyet/rapor sorgusunda
     * kullanılmaz (yapısal olarak dışlı: bu tabloya kimse join atmaz).
     */
    public function up(): void
    {
        Schema::create('party_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('description');
            $table->string('direction'); // borc | alacak
            $table->decimal('amount', 15, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_ledger_entries');
    }
};
