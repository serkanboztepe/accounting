<?php

namespace App\Filament\Resources\Checks;

use App\Filament\Resources\Checks\Pages\CreateCheck;
use App\Filament\Resources\Checks\Pages\EditCheck;
use App\Filament\Resources\Checks\Pages\ListChecks;
use App\Filament\Resources\Checks\Schemas\CheckForm;
use App\Filament\Resources\Checks\Tables\ChecksTable;
use App\Models\Check;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

class CheckResource extends Resource
{
    protected static ?string $model = Check::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'İşlemler';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Çekler';

    protected static ?string $modelLabel = 'Çek';

    protected static ?string $pluralModelLabel = 'Çekler';

    public static function getNavigationBadge(): ?string
    {
        $today = Carbon::today();

        $overdue  = Check::query()
            ->whereIn('status', ['portfolio', 'issued'])
            ->whereDate('due_date', '<', $today)
            ->count();
        $upcoming = Check::query()
            ->whereIn('status', ['portfolio', 'issued'])
            ->whereDate('due_date', '>=', $today)
            ->whereDate('due_date', '<=', $today->copy()->addDays(7))
            ->count();

        $total = $overdue + $upcoming;

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $today = Carbon::today();

        $hasOverdue = Check::query()
            ->whereIn('status', ['portfolio', 'issued'])
            ->whereDate('due_date', '<', $today)
            ->exists();

        return $hasOverdue ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Vadesi geçmiş + 7 gün içinde gelecek çekler';
    }

    public static function canAccess(): bool
    {
        return config('modules.checks');
    }

    public static function form(Schema $schema): Schema
    {
        return CheckForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChecksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChecks::route('/'),
            'create' => CreateCheck::route('/create'),
            'edit' => EditCheck::route('/{record}/edit'),
        ];
    }
}
