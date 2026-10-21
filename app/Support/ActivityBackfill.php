<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * İşlem geçmişi ilk kurulumda (migration): o an var olan kayıtlar kendi oluşturulma tarihleriyle bir kez "önceden vardı"
 * yazılır — cari sayfasındaki geçmiş boş başlamasın (kullanıcı kararı 21.10). Kanal/kişi bilinmez (boş). Daha önce silinmiş
 * kayıtlar geri getirilemez. Ayarlar (kullanıcı, birim, kategori, firma bilgisi) yazılmaz — iş kaydı değil.
 */
class ActivityBackfill
{
    private const MODELS = [
        \App\Models\Party::class,
        \App\Models\PartyLedgerEntry::class,
        \App\Models\Sale::class,
        \App\Models\Purchase::class,
        \App\Models\SaleReturn::class,
        \App\Models\PurchaseReturn::class,
        \App\Models\Expense::class,
        \App\Models\Check::class,
        \App\Models\StockMovement::class,
        \App\Models\Product::class,
        \App\Models\Project::class,
        \App\Models\Reminder::class,
        \App\Models\AssistantMemory::class,
        \App\Models\Contract::class,
        \App\Models\ContractPayment::class,
        \App\Models\ContractDelivery::class,
        \App\Models\Invoice::class,
        \App\Models\Quote::class,
        \App\Models\LandShareStudy::class,
        \App\Models\PropertyTaxProject::class,
    ];

    public static function run(): int
    {
        $written = 0;
        foreach (self::MODELS as $class) {
            $class::withoutGlobalScopes()->orderBy('id')->chunkById(200, function ($rows) use (&$written) {
                $insert = [];
                foreach ($rows as $model) {
                    if ($model->activitySkipped()) {
                        continue;
                    }
                    $insert[] = self::row($model);
                }
                if ($insert) {
                    ActivityLog::insert($insert);
                    $written += count($insert);
                }
            });
        }

        return $written;
    }

    private static function row(Model $model): array
    {
        $partyId = $model->activityPartyId();
        $when = $model->created_at ?? now();

        return [
            'happened_at'  => $when,
            'event'        => 'existing',
            'action'       => $model->activityAction(),
            'subject_type' => $model->activityType(),
            'subject_id'   => $model->getKey(),
            'party_id'     => $partyId,
            'party_name'   => $partyId ? $model->activityPartyName() : null,
            'amount'       => $model->activityAmount(),
            'summary'      => mb_substr((string) $model->activitySummary(), 0, 255) ?: null,
            'details'      => json_encode($model->activityDetails(), JSON_UNESCAPED_UNICODE),
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }
}
