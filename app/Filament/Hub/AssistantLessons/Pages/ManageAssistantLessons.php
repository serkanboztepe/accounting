<?php

namespace App\Filament\Hub\AssistantLessons\Pages;

use App\Filament\Hub\AssistantLessons\AssistantLessonResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAssistantLessons extends ManageRecords
{
    protected static string $resource = AssistantLessonResource::class;

    public function getSubheading(): ?string
    {
        return 'Her sabah 08:30 analizi asistanın dünkü hatalarından kural önerir. "Kural doğru, uygula": asistan bundan sonra buna uyar. '
            . '"Kuralı ben yazayım": ne yapması gerektiğini kendi sözlerinle yazarsın. "Gerek yok": asistan haklıydı ya da kural zararlı — '
            . 'hiçbir şey değişmez. Emin değilsen "Müşteriye sor". Onaylamadan hiçbir şey değişmez.';
    }
}
