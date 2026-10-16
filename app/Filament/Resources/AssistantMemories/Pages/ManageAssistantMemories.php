<?php

namespace App\Filament\Resources\AssistantMemories\Pages;

use App\Filament\Resources\AssistantMemories\AssistantMemoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAssistantMemories extends ManageRecords
{
    protected static string $resource = AssistantMemoryResource::class;

    public function getSubheading(): ?string
    {
        return "WhatsApp asistanına öğrettiklerin. Asistan bunlara uyar (ör. \"kalfa\" deyince Mehmet Kaya'yı anlar). "
            . 'WhatsApp\'tan "neleri hatırlıyorsun" ya da "X\'i unut" da yazabilirsin.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Bilgi ekle')->mutateDataUsing(fn (array $data) => $data + ['status' => 'active'])];
    }
}
