<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hub sadeleşmesi (29.10, kullanıcı: "işe yaramıyorsa kaldıralım, tabloları da silelim; sabah ben döküm yap dediğimde yaparsın"):
 * Günlük Analiz / Dersler / Kayıt Kontrolleri / Cari Tanımları tabloları kalkar. Önce sabah analizlerinin maliyeti
 * hub_dev_usage'a (source morning_analysis) taşınır — Toplu Maliyet ve kalan kredi tahmini aynı kalsın.
 * Yedek: sunucuda /root/manual-backups/hub-tables-20261029/*.json + deploy öncesi merkez yedeği. Firma veritabanlarındaki kopyalar boştu.
 * Tekrar çalışabilir: maliyet satırları bir kez yazılır, tablolar varsa silinir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('conversation_reports') && Schema::hasTable('hub_dev_usage')
            && ! DB::table('hub_dev_usage')->where('source', 'morning_analysis')->exists()) {
            foreach (DB::table('conversation_reports')->where('cost_usd', '>', 0)->orderBy('id')->get() as $r) {
                DB::table('hub_dev_usage')->insert(['source' => 'morning_analysis', 'model' => $r->model, 'cost_usd' => $r->cost_usd,
                    'created_at' => $r->created_at, 'updated_at' => $r->updated_at]);
            }
        }
        foreach (['record_reviews', 'assistant_lessons', 'party_profile_suggestions', 'conversation_reports'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Geri dönüş yok: tablolar JSON yedekten elle kurulur.
    }
};
