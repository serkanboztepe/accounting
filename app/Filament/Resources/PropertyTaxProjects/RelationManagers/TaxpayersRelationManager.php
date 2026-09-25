<?php

namespace App\Filament\Resources\PropertyTaxProjects\RelationManagers;

use App\Models\PropertyTaxTaxpayer;
use App\Models\PropertyTaxUnit;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Proje mükellefleri (paylı mülkiyet). Her mükellef için ayrı beyanname üretilir.
 */
class TaxpayersRelationManager extends RelationManager
{
    protected static string $relationship = 'taxpayers';

    protected static ?string $title = 'Mükellefler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mükellef')
                ->columns(2)
                ->schema([
                    TextInput::make('surname')
                        ->label('Adı Soyadı / Ünvanı')
                        ->required()
                        ->placeholder('ABDULLAH UÇAR')
                        ->columnSpanFull(),
                    TextInput::make('tax_id')->label('T.C. / Vergi Kimlik No'),
                    TextInput::make('property_registry_no')->label('Emlak Vergisi Sicil No'),
                    TextInput::make('phone_area_code')->label('Telefon Alan Kodu')->placeholder('553'),
                    TextInput::make('phone')->label('Telefon'),
                    TextInput::make('email')->label('E-posta')->email(),
                    Select::make('filer_role')
                        ->label('Bildirimi Veren Sıfat')
                        ->options([
                            'taxpayer' => 'Mükellef',
                            'proxy'    => 'Kanuni Temsilci / Vekil',
                        ])
                        ->default('taxpayer')
                        ->required(),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('surname')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('surname')
                    ->label('Adı Soyadı / Ünvanı')
                    ->formatStateUsing(fn ($state, $record) => trim($state.' '.$record->first_name))
                    ->searchable(),
                TextColumn::make('tax_id')->label('T.C. / VKN'),
                TextColumn::make('units_count')
                    ->label('Daire')
                    ->counts('units')
                    ->badge()
                    ->color('info'),
                TextColumn::make('phone')->label('Telefon')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Mükellef Ekle'),
            ])
            ->recordActions([
                Action::make('assignUnits')
                    ->label('Daire Ata')
                    ->icon(Heroicon::OutlinedHomeModern)
                    ->color('warning')
                    ->modalHeading(fn (PropertyTaxTaxpayer $record) => $record->fullName().' — Daire Seç')
                    ->modalDescription('Bu mükellefin sahip olduğu daireleri işaretle. İşaretlenenler bu mükellefe (1/1) atanır; işareti kaldırılanlardan çıkarılır. Hisseli (ortak) için daire "Düzenle"sini kullan.')
                    ->modalSubmitActionLabel('Kaydet')
                    ->fillForm(fn (PropertyTaxTaxpayer $record) => $this->assignUnitsFill($record))
                    ->schema(fn () => $this->assignUnitsSchema())
                    ->action(function (array $data, PropertyTaxTaxpayer $record) {
                        $ids = $this->collectSelectedUnitIds($data);
                        $record->units()->sync(
                            collect($ids)->mapWithKeys(fn ($id) => [$id => ['pay' => 1, 'payda' => 1]])->all()
                        );
                        Notification::make()->title(count($ids).' daire atandı')->success()->send();
                    }),
                Action::make('formatliPdf')
                    ->label('Formatlı PDF')
                    ->icon(Heroicon::OutlinedDocumentCheck)
                    ->color('danger')
                    ->url(fn (PropertyTaxTaxpayer $record) => route('property-tax.taxpayer.formatli-pdf', $record), shouldOpenInNewTab: true),
                Action::make('pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('info')
                    ->url(fn (PropertyTaxTaxpayer $record) => route('property-tax.taxpayer.pdf', $record), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Projedeki bloklar (daireleri sıralı, boş bloklar hariç). */
    private function projectBlocksWithUnits()
    {
        return $this->getOwnerRecord()->blocks()
            ->with(['units' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->filter(fn ($b) => $b->units->isNotEmpty())
            ->values();
    }

    /** Her blok için ayrı CheckboxList (blok adı başlıklı, daireler sıralı). */
    private function assignUnitsSchema(): array
    {
        return $this->projectBlocksWithUnits()
            ->map(fn ($block) => CheckboxList::make('block_'.$block->id)
                ->label($block->name)
                ->options($block->units->mapWithKeys(fn (PropertyTaxUnit $u) => [
                    $u->id => 'Daire '.$u->unit_no.' — '.$u->floorLabel(),
                ]))
                ->columns(3)
                ->bulkToggleable())
            ->all();
    }

    /** Mükellefin mevcut dairelerini blok bazında işaretli getir. */
    private function assignUnitsFill(PropertyTaxTaxpayer $record): array
    {
        $owned = $record->units->pluck('id')->all();
        $data = [];
        foreach ($this->projectBlocksWithUnits() as $block) {
            $data['block_'.$block->id] = $block->units->pluck('id')->intersect($owned)->values()->all();
        }

        return $data;
    }

    /** Tüm blok CheckboxList seçimlerini tek listede topla. */
    private function collectSelectedUnitIds(array $data): array
    {
        $ids = [];
        foreach ($this->projectBlocksWithUnits() as $block) {
            $ids = array_merge($ids, $data['block_'.$block->id] ?? []);
        }

        return array_values(array_unique($ids));
    }
}
