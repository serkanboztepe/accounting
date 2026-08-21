<?php

namespace App\Filament\Resources\Parties\RelationManagers;

use App\Models\PartyLedgerEntry;
use App\Support\Forms\MoneyInput;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Manuel Cari Hareketleri';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('entry_date')
                ->label('Tarih')
                ->default(now())
                ->required(),

            Select::make('direction')
                ->label('Yön')
                ->options(PartyLedgerEntry::DIRECTIONS)
                ->required()
                ->native(false)
                ->helperText('Borç = cari bize borçlanır · Alacak = biz cariye borçlanırız'),

            TextInput::make('description')
                ->label('Açıklama')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            MoneyInput::make('amount', 'Tutar'),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Açılış bakiyesi / düzeltme / veresiye. Bu satırlar yalnız cari ekstresini etkiler — proje maliyet raporlarına GİRMEZ.')
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('description')
                    ->label('Açıklama')
                    ->searchable(),
                TextColumn::make('direction')
                    ->label('Yön')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PartyLedgerEntry::DIRECTIONS[$state] ?? $state)
                    ->color(fn (?string $state) => $state === PartyLedgerEntry::DIRECTION_DEBIT ? 'success' : 'danger'),
                TextColumn::make('amount')
                    ->label('Tutar')
                    ->formatStateUsing(fn ($state) => Money::format((float) $state) . ' ₺')
                    ->alignRight()
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Not')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('entry_date')
            ->headerActions([
                CreateAction::make()
                    ->label('Manuel Satır Ekle')
                    ->modalHeading('Manuel Cari Hareketi'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
