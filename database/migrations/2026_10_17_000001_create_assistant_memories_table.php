<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistan hafızası (Faz 2, firma DB'si): kullanıcının öğrettiği kalıcı bilgiler — takma ad, anlaşma, fiyat, tercih.
 * Firmanın ortak hafızası; tercih (preference) yalnız o numaraya. Değişen bilgi eskisini "replaced" yapar (geçmiş kalır).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assistant_memories')) {
            return;
        }
        Schema::create('assistant_memories', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);                       // alias | agreement | price | preference | note
            $table->string('subject', 120);                  // kısa konu: "kalfa", "Recep peynir fiyatı"
            $table->text('fact');                            // "kalfa = Mehmet Kaya"
            $table->foreignId('party_id')->nullable()->constrained('parties')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('phone', 32)->nullable();          // yalnız tercih: o numaraya özel
            $table->string('taught_by', 32)->nullable();      // öğreten numara
            $table->text('source')->nullable();               // öğretildiği mesaj
            $table->string('status', 12)->default('active');  // active | replaced | deleted
            $table->foreignId('replaced_by_id')->nullable()->constrained('assistant_memories')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_memories');
    }
};
