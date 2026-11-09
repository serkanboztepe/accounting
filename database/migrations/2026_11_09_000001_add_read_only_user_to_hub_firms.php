<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajan asistanın serbest okuması (plan v5 §3): firma başına SALT-OKUR veritabanı kullanıcısı (`<db>_ro`, yalnız izinli tablolarda
 * SELECT). Kimliği merkezde, firmanın asıl kullanıcısının yanında tutulur (yeni tablo yok). Boşsa o firmada serbest okuma yoktur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hub_firms') || Schema::hasColumn('hub_firms', 'db_ro_username')) {
            return;
        }
        Schema::table('hub_firms', function (Blueprint $table) {
            $table->string('db_ro_username')->nullable()->after('db_password');
            $table->text('db_ro_password')->nullable()->after('db_ro_username'); // encrypted cast
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('hub_firms') && Schema::hasColumn('hub_firms', 'db_ro_username')) {
            Schema::table('hub_firms', fn (Blueprint $table) => $table->dropColumn(['db_ro_username', 'db_ro_password']));
        }
    }
};
