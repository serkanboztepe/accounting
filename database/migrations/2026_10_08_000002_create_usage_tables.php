<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maliyet takibi:
 *  - usage_logs (firma kurulumu): AI çağrıları (token + $) ve bizim gönderdiğimiz şablon mesajlar.
 *  - hub_message_logs (hub): firma başına gelen/giden WhatsApp mesajları.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);          // ai | wa_template
            $table->string('model')->nullable(); // claude-sonnet-5 / şablon adı
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();
            $table->index(['type', 'created_at']);
        });

        Schema::create('hub_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_firm_id')->nullable()->constrained('hub_firms')->nullOnDelete(); // null = kayıtsız numara
            $table->string('phone', 32);
            $table->string('direction', 3);      // in | out
            $table->timestamps();
            $table->index(['hub_firm_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_message_logs');
        Schema::dropIfExists('usage_logs');
    }
};
