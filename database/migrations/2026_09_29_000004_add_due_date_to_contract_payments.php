<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ödenmemiş (çek dışı) sözleşme ödemelerine opsiyonel vade. Çek vadesi zaten
// Check.due_date'te tutulur; bu alan nakit/havale gibi ödenmemiş taahhütler içindir.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_payments', function (Blueprint $table) {
            $table->date('due_date')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('contract_payments', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
