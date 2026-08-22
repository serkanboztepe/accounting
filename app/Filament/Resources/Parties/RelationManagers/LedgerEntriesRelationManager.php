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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LedgerEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'ledgerEntries';

    protected static ?string $title = 'Cari Hareketleri';

    /**
     * Ortak alanlar — yön/tip yok; hangi butona basıldığı tipi (ve yönü) belirler.
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('entry_date')
                ->label('Tarih')
                ->default(now())
                ->required(),

            MoneyInput::make('amount', 'Tutar'),

            Select::make('project_id')
                ->label('Proje (opsiyonel)')
                ->relationship('project', 'name')
                ->searchable()
                ->preload()
                ->helperText('Etiket/çıktı içindir — proje maliyet raporuna girmez.'),

            TextInput::make('description')
                ->label('Açıklama')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Çift yönlü cari hareketleri. Cari ekstresinde görünür; proje maliyet raporlarına GİRMEZ.')
            ->columns([
                TextColumn::make('entry_date')
                    ->label('Tarih')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tür')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PartyLedgerEntry::TYPES[$state]['label'] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        PartyLedgerEntry::TYPE_SALE       => 'info',
                        PartyLedgerEntry::TYPE_COLLECTION => 'success',
                        PartyLedgerEntry::TYPE_PURCHASE   => 'warning',
                        PartyLedgerEntry::TYPE_PAYMENT    => 'danger',
                        default                           => 'gray',
                    }),
                TextColumn::make('description')
                    ->label('Açıklama')
                    ->searchable(),
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
                // Bize doğru (cari müşteri): Satış + Tahsilat
                $this->entryAction('satis', 'Satış', Heroicon::OutlinedShoppingCart, 'info', 'Satış — Cariyi Borçlandır'),
                $this->entryAction('tahsilat', 'Tahsilat', Heroicon::OutlinedArrowDownCircle, 'success', 'Tahsilat — Para Girişi'),
                // Bizden doğru (cari tedarikçi): Alış + Ödeme
                $this->entryAction('alis', 'Alış / Hizmet', Heroicon::OutlinedShoppingBag, 'warning', 'Alış / Hizmet — Cariye Borçlan'),
                $this->entryAction('odeme', 'Ödeme', Heroicon::OutlinedArrowUpCircle, 'danger', 'Ödeme — Para Çıkışı'),
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

    protected function entryAction(string $type, string $label, Heroicon $icon, string $color, string $heading): CreateAction
    {
        return CreateAction::make($type)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->modalHeading($heading)
            ->modalSubmitActionLabel('Kaydet')
            ->mutateDataUsing(fn (array $data): array => [...$data, 'type' => $type]);
    }
}
