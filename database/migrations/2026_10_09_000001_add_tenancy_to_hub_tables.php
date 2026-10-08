<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tek panel (TENANCY): hub firması = kiracı. Firmanın veritabanı + ayarları (modüller,
 * cari kilidi, çek hatırlatması) merkezde tutulur; kullanıcı e-postası → firma eşlemesi
 * girişte hangi veritabanına bakılacağını söyler. url/secret yalnız ayrı kurulumlu
 * (eski düzen / müşteri sunucusu) firmalar için kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_firms', function (Blueprint $table) {
            $table->string('url')->nullable()->change();
            $table->string('secret')->nullable()->change();
            $table->string('database')->nullable()->unique()->after('name');
            $table->string('db_username')->nullable()->after('database');
            $table->text('db_password')->nullable()->after('db_username'); // encrypted cast
            $table->json('settings')->nullable()->after('db_password');
        });

        Schema::table('hub_phones', function (Blueprint $table) {
            $table->boolean('receives_reminders')->default(false)->after('is_active');
        });

        Schema::create('firm_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_firm_id')->constrained('hub_firms')->cascadeOnDelete();
            $table->string('email')->index();
            $table->timestamps();
            $table->unique(['hub_firm_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_users');

        Schema::table('hub_phones', fn (Blueprint $table) => $table->dropColumn('receives_reminders'));

        Schema::table('hub_firms', function (Blueprint $table) {
            $table->dropUnique(['database']);
            $table->dropColumn(['database', 'db_username', 'db_password', 'settings']);
        });
    }
};
