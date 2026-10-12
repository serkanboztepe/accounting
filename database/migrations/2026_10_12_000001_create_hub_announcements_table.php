<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub: bir numaraya bırakılan duyuru ("yeni özellikler") — kişi bir sonraki yazdığında, işi bitince
 * (onay bekleyen kayıt yokken) cevabın arkasından BİR KEZ gider. 24 saat penceresi onun mesajıyla açılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_firm_id')->nullable()->index();
            $table->string('phone', 32)->index();
            $table->text('body');
            $table->string('status', 16)->default('pending'); // pending | sent | cancelled
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_announcements');
    }
};
