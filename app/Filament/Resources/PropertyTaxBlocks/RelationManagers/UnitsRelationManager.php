<?php

namespace App\Filament\Resources\PropertyTaxBlocks\RelationManagers;

use App\Models\PropertyTaxTaxpayer;
use App\Models\PropertyTaxUnit;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    protected static ?string $title = 'Daireler';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Daire')
                ->columns(2)
                ->schema([
                    TextInput::make('unit_no')
                        ->label('Daire No')
                        ->required(),
                    TextInput::make('area')
                        ->label('Dıştan Dışa Yüzölçümü (m²)')
                        ->numeric(),
                    Select::make('floor_no')
                        ->label('Kat')
                        ->options(PropertyTaxUnit::floorOptions())
                        ->default(0)
                        ->helperText('Zemin en alt kat.'),
                    TextInput::make('floor_position')
                        ->label('Kattaki Sıra')
                        ->numeric()
                        ->helperText('1 = sol, 2 = sağ…'),
                    TextInput::make('land_share_numerator')
                        ->label('Arsa Payı — Pay')
                        ->numeric()
                        ->placeholder('boş = bloktan devralır')
                        ->helperText('Ör. 5/120 için buraya 5.'),
                    TextInput::make('land_share_denominator')
                        ->label('Arsa Payı — Payda')
                        ->numeric()
                        ->placeholder('boş = bloktan devralır')
                        ->helperText('Ör. 5/120 için buraya 120.'),
                ]),

            Section::make('Mükellef Atamaları (Hisse)')
                ->description('Bu daireyi paylaşan mükellef(ler) ve hisseleri. Tam sahiplik için pay=payda (ör. 1/1). Beyanname her mükellef için ayrı üretilir.')
                ->schema([
                    Repeater::make('allocations')
                        ->hiddenLabel()
                        ->relationship()
                        ->addActionLabel('Mükellef Ata')
                        ->columns(3)
                        ->schema([
                            Select::make('property_tax_taxpayer_id')
                                ->label('Mükellef')
                                ->options(fn () => PropertyTaxTaxpayer::query()
                                    ->where('property_tax_project_id', $this->getOwnerRecord()->property_tax_project_id)
                                    ->orderBy('sort_order')->orderBy('id')
                                    ->get()->mapWithKeys(fn ($t) => [$t->id => $t->fullName()]))
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('pay')->label('Hisse Pay')->numeric()->default(1)->required(),
                            TextInput::make('payda')->label('Hisse Payda')->numeric()->default(1)->required(),
                        ]),
                ]),

            Section::make('Bloktan Farklıysa (İsteğe Bağlı)')
                ->description('Boş bırakılırsa blok değerleri kullanılır. Sadece bu daire farklıysa doldur (ör. zemin dükkan).')
                ->collapsed()
                ->columns(2)
                ->schema([
                    TextInput::make('usage_type')->label('Kullanış Şekli')->placeholder('bloktan devralır'),
                    TextInput::make('construction_class')->label('İnşaat Sınıfı')->placeholder('bloktan devralır'),
                    TextInput::make('share_ratio')->label('Hisse Oranı')->placeholder('bloktan devralır'),
                    TextInput::make('neighborhood')->label('Mahalle')->placeholder('projeden devralır'),
                    TextInput::make('street')->label('Cadde/Sokak')->placeholder('projeden devralır'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('unit_no')
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('allocations.taxpayer'))
            ->columns([
                TextColumn::make('unit_no')->label('Daire')->sortable(),
                TextColumn::make('floor_no')->label('Kat')->sortable()
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->floorLabel()),
                TextColumn::make('floor_position')->label('Sıra'),
                TextColumn::make('area')->label('Yüzölçümü')->numeric(2)->suffix(' m²'),
                TextColumn::make('land_share')
                    ->label('Arsa Payı')
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->landShareRatioText() ?? '—'),
                TextColumn::make('owners')
                    ->label('Sahibi (Mükellef)')
                    ->badge(fn (PropertyTaxUnit $record) => $record->allocations->count() > 1)
                    ->color(fn (PropertyTaxUnit $record) => match (true) {
                        $record->allocations->count() > 1  => 'info',
                        $record->allocations->count() === 1 => 'success',
                        default                             => 'gray',
                    })
                    ->getStateUsing(function (PropertyTaxUnit $record) {
                        $n = $record->allocations->count();
                        if ($n === 0) {
                            return '— atanmadı —';
                        }
                        if ($n === 1) {
                            return $record->allocations->first()->taxpayer?->fullName();
                        }

                        return $n.' sahip';
                    })
                    ->tooltip(fn (PropertyTaxUnit $record) => $record->allocations->count() > 1
                        ? $record->allocations
                            ->map(fn ($a) => $a->taxpayer?->fullName().' ('.$a->shareText().')')
                            ->filter()->implode(', ')
                        : null),
                TextColumn::make('usage_type')
                    ->label('Kullanış')
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->effectiveUsageType())
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('Daire Ekle'),

                Action::make('bulkCreateUnits')
                    ->label('Toplu Daire Oluştur')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->modalHeading('Toplu Daire Oluştur')
                    ->modalDescription('Kat ve daire sayısını gir; daireler otomatik oluşturulur (numaralandırma + kat/sıra).')
                    ->modalSubmitActionLabel('Oluştur')
                    ->schema([
                        TextInput::make('floors')->label('Kat Sayısı')
                            ->numeric()->minValue(1)->required()->default(4)
                            ->helperText('Zemin dahil ise Zemin de bu sayıya dahildir.'),
                        Toggle::make('ground_floor')->label('Zemin katı olsun')->default(true),
                        TextInput::make('per_floor')->label('Katta Kaç Daire')
                            ->numeric()->minValue(1)->required()->default(2),
                        TextInput::make('start_no')->label('Başlangıç Daire No')
                            ->numeric()->minValue(1)->required()->default(1),
                        Toggle::make('number_from_bottom')->label('Numaralandırma Zemin’den (alttan) başlasın')
                            ->default(true)->helperText('Kapalı ise en üst kattan aşağı numaralandırır.'),
                        TextInput::make('area')->label('Standart Yüzölçümü (m²)')->numeric()
                            ->helperText('Boş bırakılabilir; sonra daire bazında girilir.'),
                    ])
                    ->action(fn (array $data) => $this->bulkCreateUnits($data)),

                Action::make('distributeShares')
                    ->label('Hisseleri Eşit Böl')
                    ->icon(Heroicon::OutlinedScale)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Hisseleri Eşit Böl')
                    ->modalDescription('Bu bloktaki her dairenin hissesi, atanmış mükellefler arasında EŞİT bölünür (N mükellef → 1/N; tek mükellef → TAM).')
                    ->modalSubmitActionLabel('Eşit Böl')
                    ->action(fn () => $this->distributeSharesEqually()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('assignTaxpayer')
                    ->label('Mükellefe Ata')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('warning')
                    ->modalHeading('Seçili Daireleri Mükellefe Ata')
                    ->modalDescription('Seçili dairelerin sahibi bu mükellef olur (tam sahiplik). Mevcut atamaların yerini alır.')
                    ->schema([
                        Select::make('taxpayer_id')
                            ->label('Mükellef')
                            ->options(fn () => PropertyTaxTaxpayer::query()
                                ->where('property_tax_project_id', $this->getOwnerRecord()->property_tax_project_id)
                                ->orderBy('sort_order')->orderBy('id')
                                ->get()->mapWithKeys(fn ($t) => [$t->id => $t->fullName()]))
                            ->required(),
                    ])
                    ->action(function (array $data, Collection $records) {
                        foreach ($records as $unit) {
                            $unit->allocations()->delete();
                            $unit->allocations()->create([
                                'property_tax_taxpayer_id' => $data['taxpayer_id'], 'pay' => 1, 'payda' => 1,
                            ]);
                        }
                        Notification::make()->title($records->count().' daire atandı')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function bulkCreateUnits(array $data): void
    {
        $block = $this->getOwnerRecord();
        $floorCount = (int) $data['floors'];
        $hasGround = (bool) ($data['ground_floor'] ?? true);
        $fromBottom = (bool) ($data['number_from_bottom'] ?? true);
        $perFloor = (int) $data['per_floor'];
        $no = (int) $data['start_no'];
        $area = $data['area'] !== null && $data['area'] !== '' ? (float) $data['area'] : null;
        $sort = (int) ($block->units()->max('sort_order') ?? 0);

        $floors = $hasGround ? range(0, $floorCount - 1) : range(1, $floorCount);
        if (! $fromBottom) {
            $floors = array_reverse($floors);
        }

        $created = 0;
        foreach ($floors as $floor) {
            for ($pos = 1; $pos <= $perFloor; $pos++) {
                $block->units()->create([
                    'unit_no'        => (string) $no,
                    'floor_no'       => $floor,
                    'floor_position' => $pos,
                    'area'           => $area,
                    'sort_order'     => ++$sort,
                    // arsa payı pay/payda boş → bloğun varsayılanını devralır
                ]);
                $no++;
                $created++;
            }
        }

        Notification::make()->title($created.' daire oluşturuldu')->success()->send();
    }

    private function distributeSharesEqually(): void
    {
        $units = $this->getOwnerRecord()->units()->with('allocations')->get();

        $count = 0;
        foreach ($units as $unit) {
            $n = $unit->allocations->count();
            if ($n === 0) {
                continue;
            }
            foreach ($unit->allocations as $alloc) {
                $alloc->update(['pay' => 1, 'payda' => $n]);
            }
            $count++;
        }

        Notification::make()->title($count.' dairenin hissesi eşit bölündü')->success()->send();
    }
}
