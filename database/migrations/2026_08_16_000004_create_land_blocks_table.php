<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('land_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_id')->constrained('land_share_studies')->cascadeOnDelete();
            $table->string('name'); // A, B, ...
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_blocks');
    }
};
