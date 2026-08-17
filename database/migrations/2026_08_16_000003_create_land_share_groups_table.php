<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Yalnız GRUP yöntemi için: grup → daire hakkı tablosu (Excel E5:F14).
        Schema::create('land_share_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_id')->constrained('land_share_studies')->cascadeOnDelete();
            $table->string('group_key');
            $table->integer('unit_credits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('land_share_groups');
    }
};
