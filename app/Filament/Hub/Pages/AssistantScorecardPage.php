<?php

namespace App\Filament\Hub\Pages;

use App\Support\AssistantScorecard;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Asistan karnesi: firma firma son 7 gün — kayıt, geri alma, anlaşılmayan, cevap süresi. */
class AssistantScorecardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Asistan Karnesi';

    protected static ?string $title = 'Asistan Karnesi';

    protected static ?string $slug = 'karne';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.hub.pages.assistant-scorecard';

    protected function getViewData(): array
    {
        return AssistantScorecard::overview();
    }
}
