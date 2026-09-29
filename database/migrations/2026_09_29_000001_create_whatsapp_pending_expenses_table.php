<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp gider asistanı — onay bekleyen taslaklar (Faz 0).
 * Müteahhit fotoğraf/metin atar → AI çıkarır → burada tutulur → "evet" deyince
 * gerçek Expense yazılır. Telefon başına en son bekleyen kayıt kullanılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_pending_expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('phone')->index();      // whatsapp:+90...
            $table->json('extracted');              // AI'ın çıkardığı ham alanlar
            $table->text('summary');                // müteahhide gösterilen teyit metni
            $table->string('media_url')->nullable(); // kanıt görselin Twilio URL'i
            $table->string('status')->default('awaiting_confirmation');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_pending_expenses');
    }
};
