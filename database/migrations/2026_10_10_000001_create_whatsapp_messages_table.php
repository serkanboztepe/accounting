<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp konuşma kaydı (firma veritabanı — firmanın kendi verisi). Asistanın nerede
 * takıldığını görmek için: gelen mesaj + verilen cevap + AI'ın anladığı tür. 90 gün sonra silinir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 32);              // Phone::normalize
            $table->string('direction', 3);           // in | out
            $table->text('body')->nullable();
            $table->boolean('has_media')->default(false);
            $table->string('kind', 30)->nullable();   // gelen: AI türü / quick / guide / error
            $table->timestamps();

            $table->index(['phone', 'created_at']);
            $table->index(['direction', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
