<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İki adımlı giriş (doğrulama uygulaması, Google Authenticator): gizli anahtar + kurtarma kodları,
 * ikisi de modelde 'encrypted'. Şimdilik yalnız /hub (HubUser, merkez users) zorunlu kullanır;
 * firma veritabanlarında da oluşur (ileride müşteri paneli için), boş kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
