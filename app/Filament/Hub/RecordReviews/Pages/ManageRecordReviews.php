<?php

namespace App\Filament\Hub\RecordReviews\Pages;

use App\Filament\Hub\RecordReviews\RecordReviewResource;
use App\Models\RecordReview;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ManageRecordReviews extends ManageRecords
{
    protected static string $resource = RecordReviewResource::class;

    public function getSubheading(): ?string
    {
        return 'Her sabah 08:30 analizi, kayda geçmiş ama yanlış anlaşılmış olabilecek kayıtları buraya düşürür. "Müşteriye sor": soru '
            . 'müşteriye gider, cevabı "Cevap geldi"de görünür; kayıt yanlışsa asistan düzeltmeyi müşterinin onayına sunar. "Kapat": sonuç '
            . 've notla arşive. Siz sormadan müşteriye hiçbir şey gitmez, veriye dokunulmaz.';
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Açık')->badge(fn () => RecordReview::open()->count() ?: null)
                ->modifyQueryUsing(fn ($query) => $query->open()),
            'answered' => Tab::make('Cevap geldi')->badge(fn () => RecordReview::answered()->count() ?: null)->badgeColor('warning')
                ->modifyQueryUsing(fn ($query) => $query->answered()),
            'closed' => Tab::make('Kapandı')->modifyQueryUsing(fn ($query) => $query->closed()),
        ];
    }
}
