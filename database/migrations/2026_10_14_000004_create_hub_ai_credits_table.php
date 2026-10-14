<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Anthropic bakiyesi (API vermiyor): yükleme/okuma anındaki bakiye elle girilir, sonrası harcamadan düşülür. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_ai_credits', function (Blueprint $table) {
            $table->id();
            $table->decimal('balance_usd', 10, 2);
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_ai_credits');
    }
};
