<?php

namespace App\Filament\Hub\RecordReviews\Pages;

use App\Filament\Hub\RecordReviews\RecordReviewResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRecordReviews extends ManageRecords
{
    protected static string $resource = RecordReviewResource::class;

    public function getSubheading(): ?string
    {
        return 'Her sabah 08:30 analizi, kayda geçmiş ama yanlış anlaşılmış olabilecek kayıtları buraya düşürür. "Müşteriye sor": soru '
            . 'müşteriye gider, cevabı burada görünür; kayıt yanlışsa asistan düzeltmeyi müşterinin onayına sunar. "Düzeltildi" / '
            . '"Gerek yok": kapatır. Siz sormadan müşteriye hiçbir şey gitmez, veriye dokunulmaz.';
    }
}
