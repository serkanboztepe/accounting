<?php

namespace App\Filament\Hub\AssistantLessons\Pages;

use App\Filament\Hub\AssistantLessons\AssistantLessonResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAssistantLessons extends ManageRecords
{
    protected static string $resource = AssistantLessonResource::class;

    public function getSubheading(): ?string
    {
        return 'Her sabah 08:30 analizi asistanın dünkü hatalarından kural önerir. Onayladığın kural asistanın talimatına girer '
            . '(firma dersi yalnız o firmaya). Onaylamadan hiçbir şey değişmez. Deneme satırı: bu hata bir daha olursa yakalayacak örnek.';
    }
}
