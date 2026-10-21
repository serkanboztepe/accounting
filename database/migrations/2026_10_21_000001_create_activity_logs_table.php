<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşlem geçmişi (kullanıcı 21.10: "aktivite log gibi çalışsın, silinse de güncellense de görünsün; kullanıcının yaptığı her hareket,
 * hangi kanaldan yapıldığı da"). Kullanıcı işi başına bir satır (cari ekledi, para aldı, alım yaptı, iade…); belgelerin arka plandaki
 * parçaları (cari satırı, stok hareketi) ayrı yazılmaz. Silinen kaydın tüm ayrıntısı details'te kalır. Satırlar hiç silinmez/değişmez.
 * party_id yabancı anahtar DEĞİL — cari silinse de geçmişi durur (party_name kopyası). Mevcut kayıtlar bir kez "önceden vardı" yazılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('happened_at')->index();
            $table->string('event', 12);                       // created | updated | deleted | existing (önceden vardı)
            $table->string('action', 60);                      // "Para aldı", "Alış", "Cari", "Ürün kartı"…
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('party_id')->nullable()->index();
            $table->string('party_name')->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('old_amount', 15, 2)->nullable();
            $table->string('summary', 255)->nullable();
            $table->json('details')->nullable();               // {etiket: değer} — son hal; silinende silinmeden önceki hal
            $table->json('changes')->nullable();               // {etiket: [eski, yeni]} — düzenlemede
            $table->string('channel', 12)->nullable();         // panel | whatsapp | support | system
            $table->string('actor')->nullable();
            $table->string('note')->nullable();                // "Cari silindiği için" gibi
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });

        \App\Support\ActivityBackfill::run();
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
