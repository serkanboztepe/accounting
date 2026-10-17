<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cari tanımı önerileri (Faz 3.2, merkez / hub): asistan carinin hareketlerinden tek satır tanım önerir ("Sinan Sele: malzemeci,
 * bağlantı, çekle ödeniyor"); yönetici hub'da onaylar → firmanın asistan hafızasına yazılır. Onaysız kullanılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('party_profile_suggestions')) {
            return;
        }
        Schema::create('party_profile_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_firm_id')->nullable();
            $table->string('firm_name')->nullable();
            $table->unsignedBigInteger('party_id');               // firma veritabanındaki cari
            $table->string('party_name');
            $table->text('profile');                               // önerilen tek satır
            $table->string('status', 12)->default('pending');      // pending | approved | rejected
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'hub_firm_id']);
            $table->index(['hub_firm_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_profile_suggestions');
    }
};
