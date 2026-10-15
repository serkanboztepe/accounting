<?php

namespace App\Filament\Resources\PropertyTaxProjects\Pages;

use App\Filament\Resources\LandShareStudies\Pages\StudyBuilder;
use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use App\Filament\Resources\PropertyTaxProjects\Schemas\PropertyTaxProjectForm;
use App\Models\LandShareStudy;
use App\Models\PropertyTaxBlock;
use App\Models\PropertyTaxTaxpayer;
use App\Models\PropertyTaxUnit;
use App\Services\PropertyTax\LandShareImporter;
use App\Services\PropertyTax\UnitGenerator;
use App\Support\Fraction;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Emlak Beyanı v2 (deneme): tek sayfada adım adım — ① proje & konum ② mükellefler ③ bloklar ④ daireler
 * ⑤ sahipler ⑥ kontrol & çıktı. v1 ekranlarıyla AYNI tabloları kullanır; her adımın "Tamam"ı o adımı kaydeder.
 */
class DeclarationBuilder extends Page implements HasSchemas
{
    use InteractsWithRecord;
    use InteractsWithSchemas;

    protected static string $resource = PropertyTaxProjectResource::class;

    protected string $view = 'filament.property-tax.builder';

    public array $data = [];

    /** Proje formundaki alanlar (① adım) — kayıtta yalnız bunlar yazılır. */
    private const PROJECT_FIELDS = ['name', 'building_owner', 'land_area', 'declaration_year', 'declaration_date', 'filing_reason',
        'city', 'district', 'municipality', 'neighborhood', 'cadastral_parcel', 'street'];

    private const TAXPAYER_FIELDS = ['surname', 'tax_id', 'property_registry_no', 'phone_area_code', 'phone', 'email', 'filer_role'];

    private const BLOCK_FIELDS = ['name', 'building_door_no', 'land_share_numerator', 'land_share_denominator', 'construction_type',
        'construction_class', 'usage_type', 'share_ratio', 'construction_completion_date', 'acquisition_date',
        'restriction_status', 'exemption_status', 'has_heating', 'has_elevator'];

    private const UNIT_FIELDS = ['unit_no', 'floor_no', 'floor_position', 'area', 'usage_type', 'land_share_numerator', 'land_share_denominator'];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->loadState();
    }

    public function getTitle(): string
    {
        return 'Emlak Beyanı — ' . $this->record->name;
    }

    public function getBreadcrumb(): string
    {
        return 'Adım Adım (v2)';
    }

    private function loadState(): void
    {
        $this->record->refresh();
        $this->data = array_intersect_key($this->record->attributesToArray(), array_flip(self::PROJECT_FIELDS));
        $this->data['declaration_date'] = $this->record->declaration_date?->format('Y-m-d') ?? $this->data['declaration_date'] ?? null;
        $this->data['taxpayers'] = $this->record->taxpayers()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($t) => ['id' => $t->id] + $t->only(self::TAXPAYER_FIELDS))->all();
        $this->data['blocks'] = $this->blocks()->map(fn ($b) => ['id' => $b->id] + [
            'construction_completion_date' => $b->construction_completion_date?->format('Y-m-d'),
            'acquisition_date' => $b->acquisition_date?->format('Y-m-d'),
        ] + $b->only(self::BLOCK_FIELDS))->all();
        $this->data['units'] = [];
        foreach ($this->blocks() as $b) {
            $this->data['units']['b' . $b->id] = $b->units->map(fn ($u) => ['id' => $u->id] + $u->only(self::UNIT_FIELDS))->all();
        }
        $this->form->fill($this->data);
    }

    /** @return Collection<int, PropertyTaxBlock> daireleri sıralı */
    private function blocks(): Collection
    {
        return $this->record->blocks()
            ->with(['units' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'), 'units.allocations'])
            ->orderBy('id')->get();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Wizard::make()
                    ->startOnStep($this->initialStep())
                    ->nextAction(fn ($action) => $action->label('Tamam →'))
                    ->previousAction(fn ($action) => $action->label('← Geri'))
                    ->submitAction(new HtmlString('<a href="' . e(PropertyTaxProjectResource::getUrl('index'))
                        . '" class="fi-btn fi-btn-size-md rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white">Bitir</a>'))
                    ->steps([
                        $this->stepProject(),
                        $this->stepTaxpayers(),
                        $this->stepBlocks(),
                        $this->stepUnits(),
                        $this->stepOwners(),
                        $this->stepOutput(),
                    ]),
            ]);
    }

    /** Kaldığı yerden: eksik olan ilk adım; hepsi tamsa çıktı. */
    private function initialStep(): int
    {
        $blocks = $this->blocks();
        $units = $blocks->flatMap->units;

        return match (true) {
            // Yeni proje (henüz mükellef/blok yok): ① konum ve beyan bilgisi gözden geçirilsin.
            blank($this->record->name) || (! $this->record->taxpayers()->exists() && $blocks->isEmpty()) => 1,
            ! $this->record->taxpayers()->exists() => 2,
            $blocks->isEmpty() => 3,
            $units->isEmpty() => 4,
            $units->contains(fn ($u) => $u->allocations->isEmpty()) => 5,
            default => 6,
        };
    }

    // ── ① Proje & Konum ───────────────────────────────────────────
    private function stepProject(): Step
    {
        return Step::make('Proje & Konum')
            ->icon('heroicon-o-map-pin')
            ->description('Bina, arsa ve beyan bilgisi')
            ->afterValidation(fn () => $this->saveProject())
            ->schema(PropertyTaxProjectForm::components());
    }

    public function saveProject(): void
    {
        $this->record->update(array_intersect_key($this->data, array_flip(self::PROJECT_FIELDS)));
    }

    // ── ② Mükellefler ─────────────────────────────────────────────
    private function stepTaxpayers(): Step
    {
        return Step::make('Mükellefler')
            ->icon('heroicon-o-users')
            ->description('Beyanname verecek kişiler')
            ->afterValidation(fn () => $this->saveTaxpayers())
            ->schema([
                Actions::make([
                    Action::make('importLandShare')
                        ->label('Hisse Dağılımı\'ndan Al')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->color('info')
                        ->visible(fn () => LandShareStudy::query()->exists())
                        ->modalHeading('Hisse Dağılımı\'ndan Al')
                        ->modalDescription('Seçilen çalışmanın hissedarları mükellef, blokları blok, bağımsız bölümleri daire, atamaları daire sahibi olarak gelir. Aynı adlı mükellef/blok ve aynı numaralı daire tekrar açılmaz; gelen dairelerin sahipleri çalışmadakiyle değiştirilir. Sonra adımlarda kontrol edip eksikleri (TC, m², bina bilgisi) tamamlarsınız.')
                        ->modalSubmitActionLabel('Aktar')
                        ->schema([
                            Select::make('study_id')->label('Hisse Dağılımı çalışması')
                                ->options(fn () => LandShareStudy::query()->with('project:id,name')->latest('id')->get()
                                    ->mapWithKeys(fn ($s) => [$s->id => $s->name . ($s->project ? ' — ' . $s->project->name : '')]))
                                ->searchable()->required(),
                        ])
                        ->action(fn (array $data) => $this->importLandShare((int) $data['study_id'])),
                ]),

                Repeater::make('taxpayers')->hiddenLabel()
                    ->addActionLabel('Mükellef ekle')->defaultItems(0)
                    ->table([
                        TableColumn::make('Adı Soyadı / Ünvanı')->markAsRequired(),
                        TableColumn::make('T.C. / VKN'),
                        TableColumn::make('Emlak Sicil No'),
                        TableColumn::make('Tel. Alan'),
                        TableColumn::make('Telefon'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('surname')->required()->placeholder('ABDULLAH UÇAR'),
                        TextInput::make('tax_id'),
                        TextInput::make('property_registry_no'),
                        TextInput::make('phone_area_code')->placeholder('553'),
                        TextInput::make('phone'),
                    ]),
            ]);
    }

    public function saveTaxpayers(): void
    {
        $keep = [];
        $sort = 0;
        foreach (array_values($this->data['taxpayers'] ?? []) as $row) {
            if (blank($row['surname'] ?? null)) {
                continue;
            }
            $attrs = array_intersect_key($row, array_flip(self::TAXPAYER_FIELDS)) + ['filer_role' => 'taxpayer'];
            $attrs['sort_order'] = ++$sort;
            $t = ! empty($row['id']) ? $this->record->taxpayers()->find($row['id']) : null;
            $t ? $t->update($attrs) : $t = $this->record->taxpayers()->create($attrs);
            $keep[] = $t->id;
        }
        $this->record->taxpayers()->whereNotIn('id', $keep)->delete(); // listeden silinen (daire sahipliği de gider)
        $this->loadState();
    }

    public function importLandShare(int $studyId): void
    {
        $this->saveTaxpayers(); // ekranda yazılmış mükellefler kaybolmasın
        $study = LandShareStudy::findOrFail($studyId);
        $c = LandShareImporter::import($this->record, $study);
        $this->loadState();

        Notification::make()->title('Hisse Dağılımı aktarıldı')
            ->body("{$c['taxpayers']} yeni mükellef, {$c['blocks']} yeni blok, {$c['units']} yeni daire, {$c['owners']} sahiplik satırı.")
            ->success()->send();
    }

    // ── ③ Bina / Bloklar ──────────────────────────────────────────
    private function stepBlocks(): Step
    {
        return Step::make('Bloklar')
            ->icon('heroicon-o-building-office')
            ->description('Bina ortak özellikleri')
            ->afterValidation(fn () => $this->saveBlocks())
            ->schema([
                Placeholder::make('blocks_hint')->hiddenLabel()
                    ->content('Her bina/blok için bir kart. Buradaki özellikler tüm dairelere geçer; daire farklıysa ④ adımda yazılır.'),
                Repeater::make('blocks')->hiddenLabel()
                    ->addActionLabel('Blok ekle')->defaultItems(1)
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => filled($state['name'] ?? null) ? $state['name'] : 'Blok')
                    ->columns(4)
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('name')->label('Blok Adı')->placeholder('tek apartmansa boş bırakın'),
                        TextInput::make('building_door_no')->label('Bina / Kapı No')->placeholder('3'),
                        TextInput::make('land_share_numerator')->label('Varsayılan Arsa Payı')->numeric()
                            ->helperText('Dairelerin hepsi aynıysa (1/120 için 1).'),
                        TextInput::make('land_share_denominator')->label('Arsa Payı Paydası')->numeric()->placeholder('120'),
                        TextInput::make('construction_type')->label('İnşaat Türü')->default('B.ARME'),
                        TextInput::make('construction_class')->label('İnşaat Sınıfı')->default('3.SINIF'),
                        TextInput::make('usage_type')->label('Kullanış Şekli')->default('MESKEN'),
                        TextInput::make('share_ratio')->label('Hisse Oranı')->default('TAM'),
                        DatePicker::make('construction_completion_date')->label('İnşaatın Bitim Tarihi'),
                        DatePicker::make('acquisition_date')->label('İktisap Tarihi'),
                        TextInput::make('restriction_status')->label('Kısıtlılık Hali')->default('YOK'),
                        TextInput::make('exemption_status')->label('Muafiyet')->default('YOK'),
                        Toggle::make('has_heating')->label('Kaloriferli')->inline(false),
                        Toggle::make('has_elevator')->label('Asansörlü')->inline(false),
                    ]),
            ]);
    }

    public function saveBlocks(): void
    {
        $keep = [];
        foreach (array_values($this->data['blocks'] ?? []) as $row) {
            $attrs = array_intersect_key($row, array_flip(self::BLOCK_FIELDS));
            $attrs['name'] = filled($attrs['name'] ?? null) ? trim($attrs['name']) : null; // tek apartman: adsız blok
            $attrs['has_heating'] = (bool) ($attrs['has_heating'] ?? false);
            $attrs['has_elevator'] = (bool) ($attrs['has_elevator'] ?? false);
            foreach (['land_share_numerator', 'land_share_denominator'] as $k) {
                $attrs[$k] = filled($attrs[$k] ?? null) ? (int) $attrs[$k] : null;
            }
            $b = ! empty($row['id']) ? $this->record->blocks()->find($row['id']) : null;
            $b ? $b->update($attrs) : $b = $this->record->blocks()->create($attrs);
            $keep[] = $b->id;
        }
        $this->record->blocks()->whereNotIn('id', $keep)->delete(); // listeden silinen blok (daireleriyle)
        $this->loadState();
    }

    // ── ④ Daireler ────────────────────────────────────────────────
    private function stepUnits(): Step
    {
        return Step::make('Daireler')
            ->icon('heroicon-o-home-modern')
            ->description('Blok blok daire listesi')
            ->afterValidation(fn () => $this->saveUnits())
            ->schema(fn (): array => $this->unitSections());
    }

    private function unitSections(): array
    {
        $blocks = $this->record->blocks()->orderBy('id')->get();
        if ($blocks->isEmpty()) {
            return [Placeholder::make('no_blocks')->hiddenLabel()
                ->content(new HtmlString('<span class="text-warning-600">Önce ③ adımda en az bir blok ekleyin.</span>'))];
        }

        return $blocks->map(fn (PropertyTaxBlock $b) => Section::make($b->name ?: 'Blok')
            ->description('Daire No beyannamedeki numaradır; Kattaki Sıra yalnız kroki içindir (1 = sol).')
            ->headerActions([
                Action::make("bulkUnits{$b->id}")
                    ->label('Toplu Daire Oluştur')
                    ->icon('heroicon-o-squares-2x2')
                    ->color('warning')
                    ->modalHeading(($b->name ?: 'Blok') . ' — Toplu Daire Oluştur')
                    ->modalDescription('Mesken katları + (varsa) zemin dükkan girilir; daireler bu bloğun listesine eklenir.')
                    ->modalSubmitActionLabel('Oluştur')
                    ->schema(self::bulkUnitSchema())
                    ->action(fn (array $data) => $this->bulkCreateUnits($b->id, $data)),
            ])
            ->schema([
                Repeater::make("units.b{$b->id}")->hiddenLabel()
                    ->addActionLabel('Daire ekle')->defaultItems(0)
                    ->table([
                        TableColumn::make('Daire No')->markAsRequired(),
                        TableColumn::make('Kat')->width('9rem'),
                        TableColumn::make('Kattaki Sıra'),
                        TableColumn::make('m²'),
                        TableColumn::make('Kullanış'),
                        TableColumn::make('Arsa Payı'),
                        TableColumn::make('Payda'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('unit_no')->required(),
                        Select::make('floor_no')->options(PropertyTaxUnit::floorOptions())->default(0)->placeholder('—'),
                        TextInput::make('floor_position')->numeric(),
                        TextInput::make('area')->numeric(),
                        TextInput::make('usage_type')->placeholder('bloktan'),
                        TextInput::make('land_share_numerator')->numeric()->placeholder('bloktan'),
                        TextInput::make('land_share_denominator')->numeric()->placeholder('bloktan'),
                    ]),
            ]))->all();
    }

    /** v1 "Toplu Daire Oluştur" penceresiyle aynı alanlar. */
    private static function bulkUnitSchema(): array
    {
        return [
            TextInput::make('residential_floors')->label('Mesken Kat Sayısı')->numeric()->minValue(0)->required()->default(4)
                ->helperText('Zemin dükkan açıksa 1.KAT’tan yukarı; kapalıysa Zemin de mesken.'),
            TextInput::make('per_floor')->label('Katta Kaç Mesken')->numeric()->minValue(1)->required()->default(2),
            TextInput::make('area')->label('Standart Mesken Yüzölçümü (m²)')->numeric(),
            Toggle::make('ground_shops')->label('Zemin katı dükkan olsun')->live()->default(false),
            TextInput::make('shop_count')->label('Kaç Dükkan')->numeric()->minValue(1)->default(2)
                ->visible(fn (Get $get) => (bool) $get('ground_shops')),
            TextInput::make('shop_area')->label('Standart Dükkan Yüzölçümü (m²)')->numeric()
                ->visible(fn (Get $get) => (bool) $get('ground_shops')),
            Toggle::make('start_above')->label('Numaralandırmayı bir üst kattan başlat')->default(true)
                ->helperText('Evet: 1.KAT’tan 1, 2… başlar, DÜKKANLAR EN SON. Hayır: alttan (zemin/dükkan) başlar.')
                ->visible(fn (Get $get) => (bool) $get('ground_shops')),
            TextInput::make('start_no')->label('Başlangıç No')->numeric()->minValue(1)->required()->default(1),
        ];
    }

    public function bulkCreateUnits(int $blockId, array $data): void
    {
        $this->saveUnits(); // ekranda yazılmış daireler kaybolmasın
        $block = $this->record->blocks()->findOrFail($blockId);
        $created = UnitGenerator::create($block, $data);
        $this->loadState();

        Notification::make()->title($created . ' daire/dükkan oluşturuldu — ' . ($block->name ?: 'blok'))->success()->send();
    }

    public function saveUnits(): void
    {
        foreach ($this->record->blocks()->get() as $block) {
            if (! array_key_exists('b' . $block->id, $this->data['units'] ?? [])) {
                continue; // bu blok ekranda yok (yeni eklendi) — dokunma
            }
            $keep = [];
            $sort = 0;
            foreach (array_values($this->data['units']['b' . $block->id] ?? []) as $row) {
                if (blank($row['unit_no'] ?? null)) {
                    continue;
                }
                $attrs = array_intersect_key($row, array_flip(self::UNIT_FIELDS));
                foreach (['floor_no', 'floor_position', 'land_share_numerator', 'land_share_denominator'] as $k) {
                    $attrs[$k] = filled($attrs[$k] ?? null) ? (int) $attrs[$k] : null;
                }
                $attrs['area'] = filled($attrs['area'] ?? null) ? (float) $attrs['area'] : null;
                $attrs['usage_type'] = filled($attrs['usage_type'] ?? null) ? $attrs['usage_type'] : null;
                $attrs['sort_order'] = ++$sort;
                $u = ! empty($row['id']) ? $block->units()->find($row['id']) : null;
                $u ? $u->update($attrs) : $u = $block->units()->create($attrs);
                $keep[] = $u->id;
            }
            $block->units()->whereNotIn('id', $keep)->delete();
        }
        $this->loadState();
    }

    // ── ⑤ Sahipler ────────────────────────────────────────────────
    private function stepOwners(): Step
    {
        return Step::make('Sahipler')
            ->icon('heroicon-o-key')
            ->description('Kişiye tıkla, dairelerini seç')
            ->schema(fn (): array => [
                Placeholder::make('owners_hint')->hiddenLabel()->content(
                    'Kişinin yanındaki "Daire Seç" ile sahip olduğu daireleri işaretleyin; kaydedince hemen yazılır. '
                    . 'Aynı daireyi birden çok kişi seçerse hisse eşit bölünür; farklı oran için "Özel Hisse".'),
                Actions::make([
                    Action::make('customShare')
                        ->label('Özel Hisse')
                        ->icon('heroicon-o-scale')
                        ->color('gray')
                        ->modalHeading('Özel Hisse (ör. 2/3 + 1/3)')
                        ->modalDescription('Daireyi seçin, sahiplerinin hissesini yazın. Toplam 1/1 olmalı.')
                        ->modalSubmitActionLabel('Kaydet')
                        ->schema([
                            Select::make('unit_id')->label('Daire')
                                ->options(fn () => $this->unitOptions(onlyShared: false))
                                ->searchable()->required()->live()
                                ->afterStateUpdated(function ($state, Set $set) {
                                    foreach (PropertyTaxUnit::find($state)?->allocations ?? [] as $a) {
                                        $set("pay_{$a->property_tax_taxpayer_id}", $a->pay);
                                        $set("payda_{$a->property_tax_taxpayer_id}", $a->payda);
                                    }
                                }),
                            Grid::make(1)->schema(fn (Get $get): array => $this->customShareRows((int) $get('unit_id'))),
                        ])
                        ->action(fn (array $data) => $this->saveCustomShare($data)),
                ]),
                Grid::make(1)->schema($this->personRows()),
                Placeholder::make('units_summary')->label('Daireler')->content(fn () => $this->unitsSummary()),
            ]);
    }

    private function personRows(): array
    {
        $rows = [];
        foreach ($this->record->taxpayers()->orderBy('sort_order')->orderBy('id')->get() as $t) {
            $rows[] = Grid::make(12)->schema([
                Placeholder::make("tp_{$t->id}")->hiddenLabel()
                    ->content(fn () => new HtmlString('<strong>' . e($t->fullName()) . '</strong> — ' . e($this->personSummary($t->id))))
                    ->columnSpan(9),
                Actions::make([
                    Action::make("pickUnits{$t->id}")
                        ->label('Daire Seç')
                        ->icon('heroicon-o-home-modern')
                        ->color('warning')
                        ->size('sm')
                        ->modalHeading($t->fullName() . ' — Daire Seç')
                        ->modalDescription('Bu mükellefin sahip olduğu daireleri işaretleyin. Başkasıyla ortak dairede hisse eşit bölünür; işareti kaldırılan daireden çıkarılır.')
                        ->modalSubmitActionLabel('Kaydet')
                        ->fillForm(fn (): array => $this->personUnitsFill($t->id))
                        ->schema(fn (): array => $this->personUnitsSchema($t->id))
                        ->action(fn (array $data) => $this->assignTaxpayerUnits($t->id, $data)),
                ])->alignEnd()->columnSpan(3),
            ]);
        }

        return $rows === [] ? [Placeholder::make('no_taxpayers')->hiddenLabel()
            ->content(new HtmlString('<span class="text-warning-600">Önce ② adımda mükellef ekleyin.</span>'))] : $rows;
    }

    private function unitLabel(PropertyTaxUnit $u, ?PropertyTaxBlock $b = null): string
    {
        $b ??= $u->block;

        return trim(($b?->name ? $b->name . ' · ' : '') . 'Daire ' . $u->unit_no);
    }

    private function personSummary(int $taxpayerId): string
    {
        $owned = [];
        foreach ($this->blocks() as $b) {
            foreach ($b->units as $u) {
                if ($u->allocations->contains('property_tax_taxpayer_id', $taxpayerId)) {
                    $owned[] = ($b->name ? $b->name . ' ' : '') . $u->unit_no . ($u->allocations->count() > 1 ? ' (ortak)' : '');
                }
            }
        }

        return $owned === [] ? 'henüz daire yok' : count($owned) . ' daire: ' . implode(', ', $owned);
    }

    private function personUnitsSchema(int $taxpayerId): array
    {
        $names = $this->record->taxpayers()->get()->mapWithKeys(fn ($t) => [$t->id => $t->fullName()]);

        return $this->blocks()->filter(fn ($b) => $b->units->isNotEmpty())
            ->map(fn (PropertyTaxBlock $b) => CheckboxList::make('block_' . $b->id)
                ->label($b->name ?: 'Blok')
                ->options($b->units->mapWithKeys(function (PropertyTaxUnit $u) use ($names, $taxpayerId) {
                    $others = $u->allocations->pluck('property_tax_taxpayer_id')->reject(fn ($id) => (int) $id === $taxpayerId)
                        ->map(fn ($id) => $names[$id] ?? '?')->implode(', ');

                    return [$u->id => 'Daire ' . $u->unit_no . ' — ' . $u->floorLabel() . ($others !== '' ? ' · ' . $others : '')];
                })->all())
                ->columns(3)
                ->bulkToggleable())
            ->values()->all();
    }

    private function personUnitsFill(int $taxpayerId): array
    {
        $fill = [];
        foreach ($this->blocks() as $b) {
            $fill['block_' . $b->id] = $b->units->filter(fn ($u) => $u->allocations->contains('property_tax_taxpayer_id', $taxpayerId))
                ->pluck('id')->values()->all();
        }

        return $fill;
    }

    /** Hisse Dağılımı'ndaki kuralın aynısı (StudyBuilder::applyPersonUnits): değişen dairede eşit bölme. */
    public function assignTaxpayerUnits(int $taxpayerId, array $data): void
    {
        $selected = [];
        foreach ($data as $key => $ids) {
            if (str_starts_with((string) $key, 'block_')) {
                $selected = array_merge($selected, array_map('intval', (array) $ids));
            }
        }

        $units = $this->blocks()->flatMap->units;
        $sections = $units->map(fn (PropertyTaxUnit $u) => [
            'section_id' => $u->id,
            'allocations' => $u->allocations->map(fn ($a) => ['shareholder_id' => $a->property_tax_taxpayer_id, 'pay' => $a->pay, 'payda' => $a->payda])->all(),
        ])->all();
        $after = StudyBuilder::applyPersonUnits($sections, $taxpayerId, $selected);

        foreach ($after as $i => $item) {
            if (! array_key_exists('owners', $item)) {
                continue; // değişmedi
            }
            $unit = $units[$i];
            $unit->allocations()->delete();
            foreach (StudyBuilder::ownersToAllocations($item['owners']) as $a) {
                $unit->allocations()->create(['property_tax_taxpayer_id' => $a['shareholder_id'], 'pay' => $a['pay'], 'payda' => $a['payda']]);
            }
        }

        $name = PropertyTaxTaxpayer::find($taxpayerId)?->fullName() ?? 'Mükellef';
        Notification::make()->title($name . ': ' . count($selected) . ' daire kaydedildi')->success()->send();
    }

    private function unitOptions(bool $onlyShared): array
    {
        $out = [];
        foreach ($this->blocks() as $b) {
            foreach ($b->units as $u) {
                if ($u->allocations->isNotEmpty() && (! $onlyShared || $u->allocations->count() > 1)) {
                    $out[$u->id] = $this->unitLabel($u, $b);
                }
            }
        }

        return $out;
    }

    private function customShareRows(int $unitId): array
    {
        $unit = $unitId ? PropertyTaxUnit::with('allocations.taxpayer')->find($unitId) : null;
        if (! $unit) {
            return [];
        }

        return $unit->allocations->map(fn ($a) => Grid::make(12)->schema([
            Placeholder::make("n_{$a->property_tax_taxpayer_id}")->hiddenLabel()->content($a->taxpayer?->fullName())->columnSpan(6),
            TextInput::make("pay_{$a->property_tax_taxpayer_id}")->hiddenLabel()->placeholder('pay')->numeric()->minValue(1)->required()->columnSpan(3),
            TextInput::make("payda_{$a->property_tax_taxpayer_id}")->hiddenLabel()->prefix('/')->placeholder('payda')->numeric()->minValue(1)->required()->columnSpan(3),
        ]))->all();
    }

    public function saveCustomShare(array $data): void
    {
        $unit = PropertyTaxUnit::with('allocations')->findOrFail($data['unit_id']);
        $sum = Fraction::zero();
        foreach ($unit->allocations as $a) {
            $sum = $sum->add(Fraction::of((int) ($data["pay_{$a->property_tax_taxpayer_id}"] ?? 0), max(1, (int) ($data["payda_{$a->property_tax_taxpayer_id}"] ?? 1))));
        }
        if (! $sum->equals(Fraction::of(1))) {
            Notification::make()->title('Toplam ' . $sum . ' — 1/1 olmalı')->body('Kaydedilmedi; hisseleri düzeltip tekrar deneyin.')->danger()->send();

            throw new Halt;
        }
        foreach ($unit->allocations as $a) {
            $a->update(['pay' => (int) $data["pay_{$a->property_tax_taxpayer_id}"], 'payda' => (int) $data["payda_{$a->property_tax_taxpayer_id}"]]);
        }

        Notification::make()->title($this->unitLabel($unit) . ' hisseleri kaydedildi')->success()->send();
    }

    private function unitsSummary(): HtmlString
    {
        $lines = [];
        foreach ($this->blocks() as $b) {
            $parts = $b->units->map(function (PropertyTaxUnit $u) {
                $owners = $u->allocations->map(fn ($a) => e($a->taxpayer?->fullName()) . ($u->allocations->count() > 1 ? ' ' . $a->shareText() : ''))->implode(', ');

                return '<span class="whitespace-nowrap">' . e($u->unit_no) . ': '
                    . ($owners !== '' ? $owners : '<span class="text-warning-600">⚠ sahipsiz</span>') . '</span>';
            })->implode(' · ');
            $lines[] = '<strong>' . e($b->name ?: 'Blok') . '</strong> — ' . ($parts !== '' ? $parts : 'daire yok');
        }

        return new HtmlString(implode('<br>', $lines) ?: 'Daire yok.');
    }

    // ── ⑥ Kontrol & Çıktı ─────────────────────────────────────────
    private function stepOutput(): Step
    {
        return Step::make('Kontrol & Çıktı')
            ->icon('heroicon-o-document-check')
            ->description('Eksikler ve beyannameler')
            ->schema([
                Placeholder::make('checks')->label('Kontrol')->content(fn () => $this->checksHtml()),
                Placeholder::make('outputs')->label('Beyannameler')->content(fn () => $this->outputsHtml()),
            ]);
    }

    /** @return list<array{0:string,1:string}> [seviye (error|warn), metin] */
    public function checks(): array
    {
        $issues = [];
        $units = $this->blocks()->flatMap(fn ($b) => $b->units->map(fn ($u) => [$b, $u]));
        $label = fn ($pair) => $this->unitLabel($pair[1], $pair[0]);

        $noOwner = $units->filter(fn ($p) => $p[1]->allocations->isEmpty())->map($label);
        if ($noOwner->isNotEmpty()) {
            $issues[] = ['error', 'Sahibi seçilmemiş daire: ' . $noOwner->implode(', ') . ' (⑤ Sahipler)'];
        }
        $badShare = $units->filter(function ($p) {
            if ($p[1]->allocations->isEmpty()) {
                return false;
            }
            $sum = $p[1]->allocations->reduce(fn ($s, $a) => $s->add(Fraction::of((int) $a->pay, max(1, (int) $a->payda))), Fraction::zero());

            return ! $sum->equals(Fraction::of(1));
        })->map($label);
        if ($badShare->isNotEmpty()) {
            $issues[] = ['error', 'Hisse toplamı 1/1 olmayan daire: ' . $badShare->implode(', ') . ' (⑤ Özel Hisse)'];
        }
        $noFloor = $units->filter(fn ($p) => $p[1]->floor_no === null)->map($label);
        if ($noFloor->isNotEmpty()) {
            $issues[] = ['warn', 'Katı seçilmemiş daire (krokide yeri belli olmaz): ' . $noFloor->implode(', ') . ' (④ Daireler)'];
        }
        $noArea = $units->filter(fn ($p) => blank($p[1]->area))->map($label);
        if ($noArea->isNotEmpty()) {
            $issues[] = ['warn', 'Yüzölçümü (m²) boş daire: ' . $noArea->implode(', ') . ' (④ Daireler)'];
        }
        $taxpayers = $this->record->taxpayers()->withCount('allocations')->get();
        $idle = $taxpayers->where('allocations_count', 0)->map->fullName();
        if ($idle->isNotEmpty()) {
            $issues[] = ['warn', 'Dairesi olmayan mükellef (beyannamesi boş çıkar): ' . $idle->implode(', ')];
        }
        $noTc = $taxpayers->filter(fn ($t) => blank($t->tax_id))->map->fullName();
        if ($noTc->isNotEmpty()) {
            $issues[] = ['warn', 'T.C. / VKN boş: ' . $noTc->implode(', ') . ' (② Mükellefler)'];
        }
        if (blank($this->record->land_area)) {
            $issues[] = ['warn', 'Arsa alanı boş (① Proje & Konum)'];
        }

        return $issues;
    }

    private function checksHtml(): HtmlString
    {
        $issues = $this->checks();
        if ($issues === []) {
            return new HtmlString('<span class="text-success-600 dark:text-success-400">✓ Eksik yok — beyannameler hazır.</span>');
        }

        return new HtmlString(implode('<br>', array_map(fn ($i) => $i[0] === 'error'
            ? '<span class="text-danger-600 dark:text-danger-400">✖ ' . e($i[1]) . '</span>'
            : '<span class="text-warning-600 dark:text-warning-400">⚠ ' . e($i[1]) . '</span>', $issues)));
    }

    private function outputsHtml(): HtmlString
    {
        // Panel teması özel HTML'deki Tailwind sınıflarını derlemez → renk Filament CSS değişkeninden, satır içi.
        $btn = fn (string $url, string $text, string $color) => '<a href="' . e($url) . '" target="_blank" rel="noopener" '
            . 'style="display:inline-block;margin:0 .25rem .25rem 0;padding:.375rem .75rem;border-radius:.5rem;font-size:.875rem;'
            . 'font-weight:600;color:#fff;background:var(--' . $color . '-600)">' . e($text) . '</a>';

        $rows = $this->record->taxpayers()->withCount('allocations')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (PropertyTaxTaxpayer $t) => '<tr class="border-t border-gray-200 dark:border-white/10">'
                . '<td class="py-2 pe-4">' . e($t->fullName()) . '</td>'
                . '<td class="py-2 pe-4 text-gray-500">' . $t->allocations_count . ' daire</td>'
                . '<td class="py-2" style="text-align:end">'
                . $btn(route('property-tax.taxpayer.formatli-pdf', $t), 'Formatlı PDF', 'danger')
                . ' ' . $btn(route('property-tax.taxpayer.pdf', $t), 'PDF', 'info')
                . '</td></tr>')->implode('');

        return new HtmlString(
            '<div style="margin-bottom:.75rem">'
            . $btn(route('property-tax.project.formatli-pdf', $this->record), 'Tümü — Formatlı PDF', 'danger') . ' '
            . $btn(route('property-tax.project.pdf', $this->record), 'Tümü — PDF', 'info')
            . '</div><table class="w-full text-sm">' . ($rows ?: '<tr><td>Mükellef yok.</td></tr>') . '</table>');
    }
}
