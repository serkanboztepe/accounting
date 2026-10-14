<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Geliştirme/test AI maliyeti (yerelde gerçek AI denemeleri — aynı anahtar): hub'da toplu maliyete katılır. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_dev_usage', function (Blueprint $table) {
            $table->id();
            $table->string('source', 40)->default('local');
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_dev_usage');
    }
};
