<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('products');
    }

    public function down(): void
    {
        // Intentionally empty — restoring these tables is not supported
    }
};
