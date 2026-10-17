<?php

namespace App\Filament\Hub\PartyProfiles\Pages;

use App\Filament\Hub\PartyProfiles\PartyProfileSuggestionResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePartyProfileSuggestions extends ManageRecords
{
    protected static string $resource = PartyProfileSuggestionResource::class;

    public function getSubheading(): ?string
    {
        return 'Her sabah asistan, hareketi olan carilere hareketlerinden tek satır tanım önerir ("malzemeci, çekle ödeniyor"). '
            . '"Onayla": o firmanın asistanı bu kişiyi tanır, gereksiz soru azalır. "Düzelt": kendi sözünle yaz. Onaylamadan kullanılmaz.';
    }
}
