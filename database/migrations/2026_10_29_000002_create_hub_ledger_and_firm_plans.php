<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hub Rapor (29.10, kullanıcı: "firmaların karlılığını görmek için abonelik sisteminin küçük versiyonu, gerçekler ortaya çıksın"):
 *  - hub_ledger: hub'ın para satırları — sabit gider (sunucu, numara…), kredi yüklemesi (Anthropic / Twilio; yalnız bilgi,
 *    gidere tekrar eklenmez), firma abonelik ödemesi (kapsadığı dönemle).
 *  - hub_firms: abonelik planı (deneme / aylık / yıllık / ücretsiz) + ödeme yok diye durdurma anı.
 * Merkezde anlamlı; firma veritabanlarında da oluşur, boş kalır (kod tabanı tek). Tekrar çalışabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hub_ledger')) {
            Schema::create('hub_ledger', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 20);                              // fixed_cost | credit_topup | payment
                $table->string('provider', 20)->nullable();              // credit_topup: anthropic | twilio
                $table->unsignedBigInteger('hub_firm_id')->nullable()->index(); // payment
                $table->string('title')->nullable();                     // fixed_cost: "Sunucu (Hetzner)"
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('TRY');           // TRY | USD | EUR
                $table->string('period', 10)->default('once');           // fixed_cost: monthly | yearly; diğerleri once
                $table->date('starts_on');                               // sabit gider başlangıcı / yükleme günü / ödemenin kapsadığı dönem başı
                $table->date('ends_on')->nullable();                     // sabit gider bitişi / ödemenin kapsadığı dönem sonu
                $table->date('paid_on')->nullable();                     // payment: ödendiği gün
                $table->string('note')->nullable();
                $table->timestamps();
                $table->index(['kind', 'starts_on']);
            });
        }

        if (Schema::hasTable('hub_firms') && ! Schema::hasColumn('hub_firms', 'plan')) {
            Schema::table('hub_firms', function (Blueprint $table) {
                $table->string('plan', 10)->nullable();                  // trial | monthly | yearly | free (boş = girilmedi)
                $table->decimal('plan_amount', 15, 2)->nullable();       // TL: aylık ya da yıllık ücret
                $table->date('plan_started_on')->nullable();             // ilk ücretli dönem başı (yıllıkta yenileme günü)
                $table->unsignedTinyInteger('plan_day')->nullable();     // aylık: ayın kaçında ödenir
                $table->date('trial_ends_on')->nullable();
                $table->timestamp('suspended_at')->nullable();           // ödeme yok diye durduruldu (is_active=false ile birlikte)
            });
        }

        // Numara kirası koddan (config/costs.php) sabit gider listesine — yalnız merkezde (firma veritabanlarında hub_firms boş).
        if (Schema::hasTable('hub_firms') && DB::table('hub_firms')->exists()
            && ! DB::table('hub_ledger')->where('kind', 'fixed_cost')->exists()) {
            DB::table('hub_ledger')->insert(['kind' => 'fixed_cost', 'title' => 'WhatsApp numarası (Twilio)',
                'amount' => (float) config('costs.twilio_number_monthly'), 'currency' => 'USD', 'period' => 'monthly',
                'starts_on' => '2026-11-01', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_ledger');
        if (Schema::hasTable('hub_firms') && Schema::hasColumn('hub_firms', 'plan')) {
            Schema::table('hub_firms', fn (Blueprint $t) => $t->dropColumn(['plan', 'plan_amount', 'plan_started_on', 'plan_day', 'trial_ends_on', 'suspended_at']));
        }
    }
};
