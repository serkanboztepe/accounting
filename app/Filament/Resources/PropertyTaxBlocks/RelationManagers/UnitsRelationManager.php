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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class UnitsRelationManager extends RelationManager
{
    protected static string $relationship = 'units';

    protected static ?string $title = 'Daireler';

    /** Daire formu kaydedilirken seçilen mükellef id'leri (mutateFormDataUsing → after arası taşıma). */
    public array $pendingTaxpayerIds = [];

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

            Section::make('Mükellef Atamaları')
                ->description('Bu daireyi paylaşan mükellef(ler)i seç — hisse otomatik EŞİT bölünür (tek → 1/1, iki → 1/2…). Beyanname her mükellef için ayrı üretilir. (Toplu atama ile tutarlı.)')
                ->schema([
                    // Açılır dropdown/repeater yerine satır içi CheckboxList — toplu atama ile aynı UX.
                    // Kaydetmede hisse otomatik eşit bölünür (aşağıdaki mutate/after).
                    CheckboxList::make('taxpayer_ids')
                        ->hiddenLabel()
                        ->options(fn () => PropertyTaxTaxpayer::query()
                            ->where('property_tax_project_id', $this->getOwnerRecord()->property_tax_project_id)
                            ->orderBy('sort_order')->orderBy('id')
                            ->get()->mapWithKeys(fn ($t) => [$t->id => $t->fullName()]))
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        // Bir DB kolonu değil; mutateFormDataUsing id'leri alıp $data'dan çıkarır, after eşit böler.
                        ->afterStateHydrated(function (CheckboxList $component, $state, $record) {
                            if ($record && blank($state)) {
                                $component->state($record->allocations->pluck('property_tax_taxpayer_id')->all());
                            }
                        }),
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
            ->paginationPageOptions([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn ($query) => $query->with('allocations.taxpayer'))
            ->columns([
                TextColumn::make('unit_no')->label('Daire No')->sortable(),
                TextColumn::make('floor_no')->label('Kat')->sortable()
                    ->getStateUsing(fn (PropertyTaxUnit $record) => $record->floorLabel()),
                // Kattaki soldan-sağa konum — SADECE kroki çizimi için (beyanname numarası değil).
                // Kafa karıştırmasın diye listede varsayılan GİZLİ; kolon menüsünden açılabilir.
                TextColumn::make('floor_position')->label('Konum (kroki)')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('area')->label('Yüzölçümü')->numeric(2)->suffix(' m²')
                    ->summarize(Sum::make()->label('Toplam')->numeric(2)->suffix(' m²')),
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
                CreateAction::make()->label('Daire Ekle')
                    ->mutateFormDataUsing(fn (array $data): array => $this->stashTaxpayerIds($data))
                    ->after(fn ($record) => $this->syncAllocationsEqual($record)),

                Action::make('bulkCreateUnits')
                    ->label('Toplu Daire Oluştur')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->modalHeading('Toplu Daire Oluştur')
                    ->modalDescription('Mesken katları + (varsa) zemin dükkan girilir; daireler otomatik oluşturulur.')
                    ->modalSubmitActionLabel('Oluştur')
                    ->schema([
                        TextInput::make('residential_floors')->label('Mesken Kat Sayısı')
                            ->numeric()->minValue(0)->required()->default(4)
                            ->helperText('Zemin dükkan açıksa 1.KAT’tan yukarı; kapalıysa Zemin de mesken.'),
                        TextInput::make('per_floor')->label('Katta Kaç Mesken')
                            ->numeric()->minValue(1)->required()->default(2),
                        TextInput::make('area')->label('Standart Mesken Yüzölçümü (m²)')->numeric()
                            ->helperText('Boş bırakılabilir; sonra daire bazında girilir.'),
                        Toggle::make('ground_shops')->label('Zemin katı dükkan olsun')
                            ->live()->default(false),
                        TextInput::make('shop_count')->label('Kaç Dükkan')
                            ->numeric()->minValue(1)->default(2)
                            ->visible(fn ($get) => (bool) $get('ground_shops')),
                        TextInput::make('shop_area')->label('Standart Dükkan Yüzölçümü (m²)')->numeric()
                            ->visible(fn ($get) => (bool) $get('ground_shops')),

                        // Zemin DÜKKAN seçilince sorulur: numaralandırma bir üst kattan mı başlasın?
                        // (Zemin dükkan değilse soru YOK — standart alttan başlar.)
                        Toggle::make('start_above')->label('Numaralandırmayı bir üst kattan başlat')
                            ->default(true)
                            ->helperText('Evet: 1.KAT’tan 1, 2… başlar, DÜKKANLAR EN SON. Hayır: alttan (zemin/dükkan) başlar.')
                            ->visible(fn ($get) => (bool) $get('ground_shops')),

                        TextInput::make('start_no')->label('Başlangıç No')
                            ->numeric()->minValue(1)->required()->default(1),
                    ])
                    ->action(fn (array $data) => $this->bulkCreateUnits($data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => $this->stashTaxpayerIds($data))
                    ->after(fn ($record) => $this->syncAllocationsEqual($record)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('assignTaxpayer')
                    ->label('Mükellefe Ata')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->color('warning')
                    ->modalHeading('Seçili Daireleri Mükellef(ler)e Ata')
                    ->modalDescription('Bir mükellef seçersen tam sahiplik (1/1). Birden fazla seçersen daireler hisseli olur, hisse EŞİT bölünür (2 mükellef → her biri 1/2). Mevcut atamaların yerini alır.')
                    ->schema([
                        // Açılır dropdown yerine satır içi CheckboxList: liste kapanmaz/üste binmez,
                        // "Tamam" butonu hep erişilebilir. Tek/çoklu seçim aynı şekilde çalışır.
                        CheckboxList::make('taxpayer_ids')
                            ->label('Mükellef(ler)')
                            ->options(fn () => PropertyTaxTaxpayer::query()
                                ->where('property_tax_project_id', $this->getOwnerRecord()->property_tax_project_id)
                                ->orderBy('sort_order')->orderBy('id')
                                ->get()->mapWithKeys(fn ($t) => [$t->id => $t->fullName()]))
                            ->columns(2)
                            ->searchable()
                            ->bulkToggleable()
                            ->required(),
                    ])
                    ->action(function (array $data, Collection $records) {
                        $ids = array_values($data['taxpayer_ids'] ?? []);
                        $n = count($ids);
                        if ($n === 0) {
                            return;
                        }
                        foreach ($records as $unit) {
                            $unit->allocations()->delete();
                            foreach ($ids as $tid) {
                                $unit->allocations()->create([
                                    'property_tax_taxpayer_id' => $tid, 'pay' => 1, 'payda' => $n,
                                ]);
                            }
                        }
                        Notification::make()
                            ->title($records->count().' daire, '.$n.' mükellefe atandı')
                            ->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Daire formundaki seçili mükellef id'lerini sakla ve $data'dan çıkar (DB kolonu değil). */
    private function stashTaxpayerIds(array $data): array
    {
        $this->pendingTaxpayerIds = array_values(array_filter(array_map('intval', $data['taxpayer_ids'] ?? [])));
        unset($data['taxpayer_ids']);

        return $data;
    }

    /** Daire kaydedildikten sonra seçili mükellefler arasında hisseyi EŞİT böl (toplu atama ile tutarlı). */
    private function syncAllocationsEqual($unit): void
    {
        $ids = $this->pendingTaxpayerIds;
        $unit->allocations()->delete();
        $n = count($ids);
        foreach ($ids as $tid) {
            $unit->allocations()->create([
                'property_tax_taxpayer_id' => $tid,
                'pay'   => 1,
                'payda' => $n,
            ]);
        }
        $this->pendingTaxpayerIds = [];
    }

    private function bulkCreateUnits(array $data): void
    {
        $block = $this->getOwnerRecord();
        $residentialFloors = max(0, (int) ($data['residential_floors'] ?? 0));
        $perFloor = max(1, (int) ($data['per_floor'] ?? 1));
        $groundShops = (bool) ($data['ground_shops'] ?? false);
        $shopCount = $groundShops ? max(0, (int) ($data['shop_count'] ?? 0)) : 0;
        $no = (int) ($data['start_no'] ?? 1);
        $area = ($data['area'] ?? '') !== '' ? (float) $data['area'] : null;
        $shopArea = ($data['shop_area'] ?? '') !== '' ? (float) $data['shop_area'] : null;
        $sort = (int) ($block->units()->max('sort_order') ?? 0);

        $created = 0;
        // Ortak üretici — sıra: unit_no + sort_order artan.
        $makeUnits = function (int $floor, int $count, ?string $usage, ?float $unitArea) use (&$no, &$sort, &$created, $block): void {
            for ($pos = 1; $pos <= $count; $pos++) {
                $block->units()->create([
                    'unit_no'        => (string) $no,
                    'floor_no'       => $floor,
                    'floor_position' => $pos,
                    'area'           => $unitArea,
                    'usage_type'     => $usage,
                    'sort_order'     => ++$sort,
                ]);
                $no++;
                $created++;
            }
        };

        if ($groundShops) {
            // Meskenler 1.KAT'tan yukarı (floor 1..N), dükkanlar zemin (floor 0).
            $meskenFloors = $residentialFloors > 0 ? range(1, $residentialFloors) : [];
            $startAbove = (bool) ($data['start_above'] ?? true);

            if ($startAbove) {
                // Evet: meskenler önce (1.KAT'tan yukarı 1,2…), DÜKKANLAR EN SON.
                foreach ($meskenFloors as $floor) {
                    $makeUnits($floor, $perFloor, null, $area);
                }
                $makeUnits(0, $shopCount, 'DÜKKAN', $shopArea);
            } else {
                // Hayır: alttan başla → zemin (dükkan) önce, sonra meskenler yukarı.
                $makeUnits(0, $shopCount, 'DÜKKAN', $shopArea);
                foreach ($meskenFloors as $floor) {
                    $makeUnits($floor, $perFloor, null, $area);
                }
            }
        } else {
            // Zemin dükkan değil → zemin de mesken (floor 0..N-1), standart ALTTAN başlar.
            $meskenFloors = $residentialFloors > 0 ? range(0, $residentialFloors - 1) : [];
            foreach ($meskenFloors as $floor) {
                $makeUnits($floor, $perFloor, null, $area);
            }
        }

        Notification::make()->title($created.' daire/dükkan oluşturuldu')->success()->send();
    }

}
