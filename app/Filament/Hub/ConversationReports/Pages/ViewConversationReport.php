<?php

namespace App\Filament\Hub\ConversationReports\Pages;

use App\Filament\Hub\ConversationReports\ConversationReportResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewConversationReport extends ViewRecord
{
    protected static string $resource = ConversationReportResource::class;

    public function getTitle(): string
    {
        return "Rapor #{$this->record->id} — " . $this->record->report_date->locale('tr')->translatedFormat('j F Y');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.hub.conversation-report')->viewData(['report' => $this->record]),
        ]);
    }
}
