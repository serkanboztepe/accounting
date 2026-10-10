<?php

namespace App\Filament\Hub\ConversationReports\Pages;

use App\Filament\Hub\ConversationReports\ConversationReportResource;
use Filament\Resources\Pages\ListRecords;

class ListConversationReports extends ListRecords
{
    protected static string $resource = ConversationReportResource::class;

    public function getSubheading(): ?string
    {
        return 'Her sabah 08:30\'da dünün WhatsApp konuşmaları okunur. Yorumların için Claude\'a "Rapor 4: 1\'i şöyle yapalım…" yaz.';
    }
}
