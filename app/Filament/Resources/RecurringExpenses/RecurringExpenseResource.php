<?php

namespace App\Filament\Resources\RecurringExpenses;

use App\Filament\Resources\RecurringExpenses\Pages\CreateRecurringExpense;
use App\Filament\Resources\RecurringExpenses\Pages\EditRecurringExpense;
use App\Filament\Resources\RecurringExpenses\Pages\ListRecurringExpenses;
use App\Support\Forms\MoneyInput;
use App\Models\RecurringExpense;
use App\Support\Labels;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Düzenli gider (ekmek her gün, kira her ay) — WhatsApp'tan kurulur, burada görülür / düzeltilir / durdurulur. */
class RecurringExpenseResource extends Resource
{
    protected static ?string $model = RecurringExpense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = 'Gider & Çek';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Düzenli Giderler';

    protected static ?string $modelLabel = 'Düzenli Gider';

    protected static ?string $pluralModelLabel = 'Düzenli Giderler';

    public static function canAccess(): bool
    {
        return config('modules.expenses');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('description')->label('Ne gideri?')->required()->maxLength(255),
            MoneyInput::make('amount')->label('Her seferinde tutar'),
            Select::make('frequency')->label('Ne sıklıkla?')->options(RecurringExpense::FREQUENCIES)->default(RecurringExpense::DAILY)
                ->required()->live(),
            Select::make('weekday')->label('Haftanın günü')->options(RecurringExpense::WEEKDAYS)
                ->visible(fn (Get $get) => $get('frequency') === RecurringExpense::WEEKLY)
                ->required(fn (Get $get) => $get('frequency') === RecurringExpense::WEEKLY),
            TextInput::make('day_of_month')->label('Ayın kaçı?')->numeric()->integer()->minValue(1)->maxValue(31)
                ->helperText('Ay kısaysa ayın son günü yazılır.')
                ->visible(fn (Get $get) => $get('frequency') === RecurringExpense::MONTHLY)
                ->required(fn (Get $get) => $get('frequency') === RecurringExpense::MONTHLY),
            Select::make('expense_category_id')->label('Kategori')->relationship('category', 'name')->searchable()->preload(),
            Select::make('project_id')->label(Labels::project())->relationship('project', 'name')->searchable()->preload()
                ->visible(fn () => config('modules.projects')),
            DatePicker::make('start_date')->label('İlk kayıt günü')->default(now()->addDay())->required(),
            Toggle::make('paid')->label('Ödendi olarak yazılsın')->default(true),
            Toggle::make('is_active')->label('Açık (her gün/ay yazılsın)')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Gider')->searchable(),
                TextColumn::make('amount')->label('Tutar')->formatStateUsing(fn ($state) => Money::format((float) $state) . ' ₺'),
                TextColumn::make('frequency')->label('Sıklık')->formatStateUsing(fn ($state, RecurringExpense $record) => ucfirst(
                    RecurringExpense::scheduleText($record->frequency, $record->weekday, $record->day_of_month))),
                TextColumn::make('category.name')->label('Kategori'),
                TextColumn::make('posted_through')->label('Son yazılan')->date('d.m.Y')->placeholder('—'),
                ToggleColumn::make('is_active')->label('Açık'),
            ])
            ->defaultSort('description')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecurringExpenses::route('/'),
            'create' => CreateRecurringExpense::route('/create'),
            'edit' => EditRecurringExpense::route('/{record}/edit'),
        ];
    }
}
