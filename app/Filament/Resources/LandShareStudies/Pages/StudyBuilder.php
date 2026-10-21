<?php

namespace App\Filament\Resources\LandShareStudies\Pages;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use App\Models\LandSection;
use App\Models\LandShareStudy;
use App\Services\LandShare\ShareCalculator;
use App\Services\LandShare\StudyData;
use App\Services\LandShare\StudyValidator;
use App\Support\Fraction;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class StudyBuilder extends Page implements HasSchemas
{
    use InteractsWithRecord;
    use InteractsWithSchemas;

    protected static string $resource = LandShareStudyResource::class;

    protected string $view = 'filament.land-share.builder';

    public array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->data = [
            'project_id'      => $this->record->project_id,
            'name'            => $this->record->name,
            'ada'             => $this->record->ada,
            'parsel'          => $this->record->parsel,
            'block_count'     => $this->record->block_count,
            'units_per_block' => $this->record->units_per_block,
            'block_units'     => $this->blockUnitsState(),
            'land_share_denominator' => $this->record->land_share_denominator,
            'shared_split'    => $this->splitMode(),
            'notes'           => $this->record->notes,
            'shareholders'    => $this->record->shareholders()->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($s) => [
                    'id'            => $s->id,
                    'name'          => $s->name,
                    'party_id'      => $s->party_id,
                    'current_pay'   => $s->current_pay,
                    'current_payda' => $s->current_payda,
                    'is_contractor' => (bool) $s->is_contractor,
                    'group_key'     => $s->group_key,
                ])->all(),
            'sections'        => $this->buildAssignState(),
        ];

        $this->form->fill($this->data);
    }

    public function getTitle(): string
    {
        return 'Hisse Dağılımı — ' . $this->record->name;
    }

    /** Breadcrumb'da kayıt adını tekrarlama — ortadaki kayıt breadcrumb'ı zaten gösteriyor. */
    public function getBreadcrumb(): string
    {
        return 'Veri Girişi';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Çıktı Al / Yazdır')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->url(fn () => route('land-share.print', ['study' => $this->record]))
                ->extraAttributes(fn () => \App\Support\Printing::iframeAttributes(route('land-share.print', ['study' => $this->record]))),

            Action::make('landShares')
                ->label('Arsa Payları')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->visible(fn () => ($this->data['sections'] ?? []) !== [])
                ->modalHeading('Arsa Payları')
                ->modalDescription('Mimari projedeki arsa payı cetvelinden her BB\'nin payını girin. Tüm BB\'ler aşağıdaki paydayla kaydedilir. Boş bırakılan BB\'nin arsa payı silinir.')
                ->modalSubmitActionLabel('Kaydet')
                ->modalWidth('4xl')
                ->fillForm(fn (): array => $this->landShareFormState())
                ->schema(fn (): array => $this->landShareFormSchema())
                ->action(fn (array $data) => $this->applyLandShares($data)),

            Action::make('fillRemainingLandShare')
                ->label('Kalan Arsa Payını Boşlara Dağıt')
                ->icon('heroicon-o-arrows-pointing-out')
                ->color('gray')
                ->visible(fn () => collect($this->data['sections'] ?? [])->contains(fn ($s) => filled($s['arsa_pay'] ?? null))
                    && collect($this->data['sections'] ?? [])->contains(fn ($s) => ! filled($s['arsa_pay'] ?? null)))
                ->requiresConfirmation()
                ->modalHeading('Kalan Arsa Payını Boşlara Dağıt')
                ->modalDescription(fn () => $this->remainingLandSharePreview())
                ->modalSubmitActionLabel('Dağıt')
                ->action(fn () => $this->fillRemainingLandShare()),

            Action::make('fillContractor')
                ->label('Boş BB\'leri Müteahhide Ver')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => ($name = $this->record->shareholders()->where('is_contractor', true)->value('name'))
                    ? "Hiç dağıtılmamış BB'ler {$name} adına 1/1 verilir. Dolu BB'lere dokunulmaz."
                    : 'Müteahhit seçili değil: ① adımda müteahhit olan hissedarın "Müteahhit" anahtarını açın.')
                ->action(fn () => $this->fillEmptyWithContractor()),
        ];
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
                    ->submitAction(new HtmlString(
                        '<button type="button" wire:click="finish" class="fi-btn fi-btn-size-md rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white">Kaydet & Bitir</button>',
                    ))
                    ->steps([
                        $this->stepOne(),
                        $this->stepTwo(),
                        $this->stepSections(),
                        $this->stepThree(),
                    ]),
            ]);
    }

    // ── ① Çalışma & Hissedarlar ───────────────────────────────────
    private function stepOne(): Step
    {
        return Step::make('Çalışma & Hissedarlar')
            ->icon('heroicon-o-users')
            ->description('Arsa bilgisi, blok/daire sayısı ve hissedarlar')
            ->afterValidation(fn () => $this->saveStep1())
            ->columns(2)
            ->schema([
                Select::make('project_id')->label('Proje')
                    ->options(fn () => \App\Models\Project::orderBy('name')->pluck('name', 'id'))
                    ->searchable()->required()
                    ->columnSpanFull(),
                TextInput::make('name')->label('Çalışma Adı')->required()->columnSpanFull(),
                TextInput::make('ada')->label('Ada'),
                TextInput::make('parsel')->label('Parsel'),
                TextInput::make('block_count')->label('Blok Sayısı')
                    ->numeric()->minValue(1)->maxValue(26)->required()
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn ($state, Get $get, Set $set) => $set('block_units', $this->extendBlockUnits((int) $state, $get('block_units') ?? [])))
                    ->helperText('Bloklar A, B, C… olarak oluşur; her bloğun BB sayısını aşağıya yazın.')
                    ->columnSpanFull(),

                Grid::make(['default' => 2, 'md' => 4])
                    ->columnSpanFull()
                    ->schema(fn (Get $get): array => array_map(
                        fn (string $name) => TextInput::make("block_units.{$name}")
                            ->label("{$name} Blok — BB Sayısı")
                            ->numeric()->minValue(1)->required(),
                        LandShareStudy::blockNames((int) $get('block_count')),
                    )),
                TextInput::make('land_share_denominator')->label('Arsa Payı Paydası (ops.)')
                    ->numeric()->minValue(1)
                    ->placeholder('ör. 1000')
                    ->helperText('Mimari projedeki arsa payı cetvelinin paydası. Girerseniz ② adımda her BB\'ye sadece payını yazarsınız; hisseler arsa payına göre hesaplanır. Boş bırakırsanız her BB eşit sayılır.')
                    ->columnSpanFull(),

                Placeholder::make('current_sum')
                    ->label('Mevcut hisse kontrolü')
                    ->content(fn (Get $get) => $this->currentShareBadge($get))
                    ->columnSpanFull(),

                Repeater::make('shareholders')->label('Hissedarlar')
                    ->columns(12)->addActionLabel('Hissedar ekle')->defaultItems(1)
                    ->collapsible()->columnSpanFull()
                    ->itemLabel(fn (array $state): string => $state['name'] ?? 'Yeni hissedar')
                    ->schema([
                        Hidden::make('id'),
                        TextInput::make('name')->label('Ad')->required()->columnSpan(4),
                        Select::make('party_id')->label('Cari (ops.)')
                            ->options(fn () => \App\Models\Party::orderBy('name')->pluck('name', 'id'))
                            ->searchable()->columnSpan(3),
                        TextInput::make('current_pay')->label('Mevcut Pay')
                            ->numeric()->default(0)->required()->live(debounce: 400)->columnSpan(2),
                        TextInput::make('current_payda')->label('Payda')
                            ->numeric()->default(1)->required()->minValue(1)->live(debounce: 400)->columnSpan(2),
                        Toggle::make('is_contractor')->label('Müteahhit')->inline(false)->columnSpan(1),
                    ]),
            ]);
    }

    // ── ② Daire Atama ─────────────────────────────────────────────
    private function stepTwo(): Step
    {
        return Step::make('Daire Atama')
            ->icon('heroicon-o-building-office-2')
            ->description('Kişilere tıklayıp dairelerini seçin')
            ->schema([
                Placeholder::make('assign_hint')
                    ->hiddenLabel()
                    ->content(fn () => count($this->data['sections'] ?? []) === 0
                        ? new HtmlString('<span class="text-warning-600">Önce ① adımda blok/daire sayısını girip "Tamam" deyin.</span>')
                        : new HtmlString('Her "Daire Seç" penceresi kaydedince hemen yazılır. Boş kalan BB\'leri üstteki düğmeyle müteahhide toplu verebilirsiniz.')),

                // Kişiden daireye (mimar isteği, Emlak Beyanı'ndaki mükellef "Daire Ata" gibi): kişiye tıkla,
                // dairelerini işaretle. Aynı BB'yi birden çok kişi seçerse hisse eşit bölünür.
                Placeholder::make('people_hint')
                    ->label('Hissedarlar — dairelerini seçin')
                    ->content(fn () => $this->shareholderOptions() === []
                        ? new HtmlString('<span class="text-warning-600">Önce ① adımda hissedar ekleyin.</span>')
                        : 'Kişinin yanındaki "Daire Seç" ile sahip olduğu bağımsız bölümleri işaretleyin.'),

                ToggleButtons::make('shared_split')
                    ->label('Ortak dairelerde hisse')
                    ->options([self::SPLIT_EQUAL => 'Eşit', self::SPLIT_EXISTING => 'Mevcut hisseye göre'])
                    ->inline()->grouped()->live()
                    ->helperText('Bir BB\'yi birden çok kişi seçerse hisse buna göre bölünür. Değiştirince otomatik bölünmüş ortak BB\'ler yeniden hesaplanıp kaydedilir; elle girilmiş özel hisseye (ör. 3/4 + 1/4) dokunulmaz.')
                    ->afterStateUpdated(fn ($state) => $this->setSplitMode((string) $state)),

                Grid::make(1)->schema(fn (): array => $this->personRows()),

            ]);
    }

    // ── ③ Bağımsız Bölümler (arsa payı + özel hisse + kontrol) ─────
    private function stepSections(): Step
    {
        return Step::make('Bağımsız Bölümler')
            ->icon('heroicon-o-squares-2x2')
            ->description('Arsa payı, özel hisse ve kontrol')
            ->afterValidation(fn () => $this->saveStep2())
            ->schema([
                Placeholder::make('arsa_sum')
                    ->label('Arsa payı kontrolü')
                    ->content(fn (Get $get) => $this->landShareBadge($get('sections') ?? [])),

                Placeholder::make('sections_hint')
                    ->label('Bağımsız bölümler')
                    ->content('Kimin hangi BB\'de olduğu başlıklarda görünür. Arsa payı ya da özel hisse (ör. 2/3) için BB\'yi açın.'),

                Repeater::make('sections')->hiddenLabel()
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->collapsible()->collapsed()
                    ->itemLabel(fn (array $state): string => ($state['label'] ?? 'BB')
                        . (filled($state['arsa_pay'] ?? null) ? ' — Arsa ' . $state['arsa_pay'] . '/' . ($state['arsa_payda'] ?? '?') : '')
                        . ' — ' . $this->itemAssignSummary($state))
                    ->schema([
                        Hidden::make('section_id'),
                        Hidden::make('label'),

                        Grid::make(12)->schema([
                            TextInput::make('arsa_pay')->label('Arsa Payı')
                                ->numeric()->minValue(1)->live(debounce: 400)
                                ->helperText('Mimari projedeki arsa payı cetvelinden (ops.)')
                                ->columnSpan(6),
                            TextInput::make('arsa_payda')->label('Arsa Payı Paydası')
                                ->numeric()->minValue(1)->live(debounce: 400)
                                ->required(fn (Get $get) => filled($get('arsa_pay')))
                                ->columnSpan(6),
                        ]),

                        // Sahipler: tüm hissedarlar tek listede — tikle (+ istersen pay/payda). Emlak Beyanı'ndaki
                        // "Sahibi" penceresi gibi; "Kişi ekle → seç" tek tek uğraşı yok.
                        Placeholder::make('owners_hint')
                            ->label('Sahipleri')
                            ->content(fn () => $this->shareholderOptions() === []
                                ? new HtmlString('<span class="text-warning-600">Önce ① adımda hissedar ekleyin.</span>')
                                : 'Sahip olanları tikle. Pay/payda boş = eşit (ya da yazılanlardan kalanı eşit) bölüşür.'),

                        Grid::make(1)->schema(fn (): array => $this->ownerRows()),

                        Placeholder::make('alloc_sum')
                            ->label('Dağılım kontrolü')
                            ->content(fn (Get $get) => $this->allocationBadge($get)),
                    ]),
            ]);
    }

    /** Her hissedar bir satır: ✓ ad + Pay + Payda (state: owners.sh{id}.inc/pay/payda). */
    private function ownerRows(): array
    {
        $rows = [];
        foreach ($this->shareholderOptions() as $id => $name) {
            $rows[] = Grid::make(12)->schema([
                Toggle::make("owners.sh{$id}.inc")->label($name)
                    ->inline()->live()->columnSpan(6),
                TextInput::make("owners.sh{$id}.pay")->hiddenLabel()->placeholder('pay')
                    ->numeric()->minValue(1)->live(debounce: 400)->columnSpan(3),
                TextInput::make("owners.sh{$id}.payda")->hiddenLabel()->prefix('/')->placeholder('payda')
                    ->numeric()->minValue(1)->live(debounce: 400)->columnSpan(3),
            ]);
        }

        return $rows;
    }

    /** Her hissedar bir satır: ad + sahip olduğu BB'ler + "Daire Seç" penceresi. */
    private function personRows(): array
    {
        $rows = [];
        foreach ($this->shareholderOptions() as $id => $name) {
            $rows[] = Grid::make(12)->schema([
                Placeholder::make("person_{$id}")->hiddenLabel()
                    ->content(fn () => new HtmlString('<strong>' . e($name) . '</strong> — ' . e($this->personSummary($id))))
                    ->columnSpan(9),
                Actions::make([
                    Action::make("pickUnits{$id}")
                        ->label('Daire Seç')
                        ->icon('heroicon-o-home-modern')
                        ->color('warning')
                        ->size('sm')
                        ->modalHeading($name . ' — Daire Seç')
                        ->modalDescription(fn () => 'Bu kişinin sahip olduğu bağımsız bölümleri işaretleyin. Başka kişiyle ortak BB\'lerde hisse '
                            . ($this->splitMode() === self::SPLIT_EXISTING ? 'mevcut hisse oranında' : 'eşit') . ' bölünür; işareti kaldırılan BB\'den çıkarılır.')
                        ->modalSubmitActionLabel('Kaydet')
                        ->fillForm(fn (): array => $this->personUnitsFill($id))
                        ->schema(fn (): array => $this->personUnitsSchema($id))
                        ->action(fn (array $data) => $this->assignPersonUnits($id, $data)),
                ])->alignEnd()->columnSpan(3),
            ]);
        }

        return $rows;
    }

    public const SPLIT_EQUAL = 'equal';

    public const SPLIT_EXISTING = 'existing';

    private function splitMode(): string
    {
        return $this->record->shared_split === self::SPLIT_EXISTING ? self::SPLIT_EXISTING : self::SPLIT_EQUAL;
    }

    /** @return array<int, Fraction> hissedar id → mevcut tapu hissesi */
    private function existingShares(): array
    {
        return $this->record->shareholders()->get()
            ->mapWithKeys(fn ($sh) => [$sh->id => Fraction::of((int) $sh->current_pay, max(1, (int) $sh->current_payda))])
            ->all();
    }

    /**
     * Sahip listesi → hisseler. Eşit: 1/N. Mevcut hisseye göre: kişinin tapu hissesi ÷ o BB'deki kişilerin toplamı
     * (Excel GRUP mantığı, BB düzeyinde); içlerinden birinin mevcut hissesi yoksa eşit.
     *
     * @return list<array{shareholder_id:int,pay:int,payda:int}>
     */
    public static function splitShares(array $ids, string $mode, array $existing = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        // Mevcut hissesi olmayan biri varsa (ör. müteahhit) o BB eşit bölünür — 0 pay alıp sessizce düşmesin.
        $sum = Fraction::zero();
        $allHave = true;
        foreach ($ids as $id) {
            $share = $existing[$id] ?? Fraction::zero();
            $allHave = $allHave && ! $share->isZero() && ! $share->isNegative();
            $sum = $sum->add($share);
        }
        $byExisting = $mode === self::SPLIT_EXISTING && $allHave;

        return array_map(function (int $id) use ($byExisting, $existing, $sum, $ids) {
            $share = $byExisting ? ($existing[$id] ?? Fraction::zero())->div($sum) : Fraction::of(1, count($ids));

            return ['shareholder_id' => $id, 'pay' => $share->num, 'payda' => $share->den];
        }, $ids);
    }

    /** BB'nin hissesi otomatik mi (eşit ya da mevcut-hisse bölmesine birebir uyuyor)? Uymuyorsa elle girilmiştir. */
    public static function isAutoSplit(array $allocations, array $existing): bool
    {
        $key = fn (array $rows) => collect($rows)->map(fn ($a) => (int) $a['shareholder_id'] . ':'
            . Fraction::of((int) $a['pay'], max(1, (int) $a['payda'])))->sort()->implode(' ');
        $ids = array_map(fn ($a) => (int) $a['shareholder_id'], $allocations);
        $current = $key($allocations);

        return $current === $key(self::splitShares($ids, self::SPLIT_EQUAL))
            || $current === $key(self::splitShares($ids, self::SPLIT_EXISTING, $existing));
    }

    /** Ayar değişti: kaydet + otomatik bölünmüş ortak BB'leri yeni kurala göre yeniden böl (elle girilene dokunma). */
    public function setSplitMode(string $mode): void
    {
        $mode = $mode === self::SPLIT_EXISTING ? self::SPLIT_EXISTING : self::SPLIT_EQUAL;
        $this->saveStep2(notify: false); // ③'te yazılmış arsa payı vb. kaybolmasın
        $this->record->update(['shared_split' => $mode]);

        $existing = $this->existingShares();
        $sections = $this->buildAssignState();
        $changed = 0;
        $kept = 0;
        foreach ($sections as $key => $item) {
            $alloc = self::itemAllocations($item);
            if (count($alloc) < 2) {
                continue;
            }
            if (! self::isAutoSplit($alloc, $existing)) {
                $kept++;
                continue;
            }
            $new = self::splitShares(array_column($alloc, 'shareholder_id'), $mode, $existing);
            if (self::allocationsToOwners($new) != self::allocationsToOwners($alloc)) {
                $sections[$key]['owners'] = self::allocationsToOwners($new);
                $changed++;
            }
        }

        $this->data['sections'] = $sections;
        $this->saveStep2(notify: false);
        $this->data['sections'] = $this->buildAssignState();
        $this->data['shared_split'] = $mode;
        $this->form->fill($this->data);

        Notification::make()
            ->title(($mode === self::SPLIT_EXISTING ? 'Mevcut hisseye göre' : 'Eşit') . ' bölme seçildi')
            ->body(($changed > 0 ? "{$changed} ortak BB yeniden bölündü." : 'Yeniden bölünecek ortak BB yok.')
                . ($kept > 0 ? " Elle girilmiş {$kept} BB'ye dokunulmadı." : ''))
            ->success()->send();
    }

    /** "3 BB: A1, A2 (ortak), A5" — formdaki (kaydedilmemiş dahil) duruma göre. */
    private function personSummary(int $shareholderId): string
    {
        $owned = [];
        foreach ($this->data['sections'] ?? [] as $item) {
            $alloc = collect(self::itemAllocations($item));
            if ($alloc->contains('shareholder_id', $shareholderId)) {
                $short = preg_replace('/^Blok (\S+) · BB (\S+)$/u', '$1$2', (string) ($item['label'] ?? 'BB'));
                $owned[] = $short . ($alloc->count() > 1 ? ' (ortak)' : '');
            }
        }

        return $owned === [] ? 'henüz daire yok' : count($owned) . ' BB: ' . implode(', ', $owned);
    }

    /** Pencere: her blok ayrı kutucuk listesi; başkasına ait BB'nin yanında sahibi yazar. */
    private function personUnitsSchema(int $shareholderId): array
    {
        $names = $this->shareholderOptions();
        $owners = [];
        foreach ($this->data['sections'] ?? [] as $item) {
            $owners[(int) ($item['section_id'] ?? 0)] = collect(self::itemAllocations($item))
                ->pluck('shareholder_id')->reject(fn ($id) => (int) $id === $shareholderId)
                ->map(fn ($id) => $names[$id] ?? '?')->implode(', ');
        }

        return $this->record->blocks()->with(['sections' => fn ($q) => $q->orderBy('sort')->orderBy('id')])
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn ($block) => $block->sections->isNotEmpty())
            ->map(fn ($block) => CheckboxList::make('block_' . $block->id)
                ->label('Blok ' . $block->name)
                ->options($block->sections->mapWithKeys(fn (LandSection $s) => [
                    $s->id => 'BB ' . $s->bb_no . (($owners[$s->id] ?? '') !== '' ? ' — ' . $owners[$s->id] : ''),
                ])->all())
                ->columns(3)
                ->bulkToggleable())
            ->values()->all();
    }

    private function personUnitsFill(int $shareholderId): array
    {
        $byBlock = LandSection::whereIn('block_id', $this->record->blocks()->pluck('id'))->pluck('block_id', 'id');
        $fill = [];
        foreach ($this->data['sections'] ?? [] as $item) {
            $sid = (int) ($item['section_id'] ?? 0);
            if (collect(self::itemAllocations($item))->contains('shareholder_id', $shareholderId) && isset($byBlock[$sid])) {
                $fill['block_' . $byBlock[$sid]][] = $sid;
            }
        }

        return $fill;
    }

    /** Pencereden kaydet: formdaki durum güncellenir + hemen kaydedilir (arsa payı gibi yazılmış alanlar da). */
    public function assignPersonUnits(int $shareholderId, array $data): void
    {
        $selected = [];
        foreach ($data as $key => $ids) {
            if (str_starts_with((string) $key, 'block_')) {
                $selected = array_merge($selected, array_map('intval', (array) $ids));
            }
        }

        $this->data['sections'] = self::applyPersonUnits($this->data['sections'] ?? [], $shareholderId, $selected,
            $this->splitMode(), $this->existingShares());
        $this->saveStep2(notify: false);
        $this->data['sections'] = $this->buildAssignState();
        $this->form->fill($this->data);

        $name = $this->shareholderOptions()[$shareholderId] ?? 'Kişi';
        Notification::make()->title($name . ': ' . count($selected) . ' bağımsız bölüm kaydedildi')->success()->send();
    }

    /**
     * Saf hesap: kişi seçilen BB'lere eklenir, seçilmeyenlerden çıkarılır. Sahip listesi DEĞİŞEN BB'lerde
     * hisse ayara göre yeniden bölünür (eşit / mevcut hisse); değişmeyen BB'nin (özel 2/3 gibi) hissesine dokunulmaz.
     *
     * @param  array<int, Fraction>  $existing  hissedar id → mevcut tapu hissesi (yalnız 'existing' modda)
     */
    public static function applyPersonUnits(array $sections, int $shareholderId, array $selectedSectionIds,
        string $mode = self::SPLIT_EQUAL, array $existing = []): array
    {
        $selected = array_flip(array_map('intval', $selectedSectionIds));
        foreach ($sections as $key => $item) {
            $ids = collect(self::itemAllocations($item))->pluck('shareholder_id')->map(fn ($id) => (int) $id)->values()->all();
            $has = in_array($shareholderId, $ids, true);
            $want = isset($selected[(int) ($item['section_id'] ?? 0)]);
            if ($has === $want) {
                continue;
            }
            $ids = $want ? [...$ids, $shareholderId] : array_values(array_diff($ids, [$shareholderId]));
            $sections[$key]['owners'] = self::allocationsToOwners(self::splitShares($ids, $mode, $existing));
            unset($sections[$key]['allocations']);
        }

        return $sections;
    }

    /**
     * Tik listesi → atama satırları. Pay+payda yazılan aynen; boş bırakılan tikliler kalanı eşit bölüşür
     * (hiç yazılmadıysa 1/N). Yazılanlar zaten 1/1'i doldurduysa boşlar 0 alamaz → atlanır, rozet uyarır.
     *
     * @return list<array{shareholder_id:int,pay:int,payda:int}>
     */
    public static function ownersToAllocations(array $owners): array
    {
        $custom = [];
        $blank = [];
        foreach ($owners as $key => $row) {
            if (! preg_match('/^sh(\d+)$/', (string) $key, $m) || empty($row['inc'])) {
                continue;
            }
            $pay = (int) ($row['pay'] ?? 0);
            $payda = (int) ($row['payda'] ?? 0);
            if ($pay > 0 && $payda > 0) {
                $custom[(int) $m[1]] = Fraction::of($pay, $payda);
            } else {
                $blank[] = (int) $m[1];
            }
        }

        $remaining = Fraction::of(1);
        foreach ($custom as $f) {
            $remaining = $remaining->sub($f);
        }
        $each = $blank !== [] && ! $remaining->isNegative() && ! $remaining->isZero()
            ? $remaining->div(Fraction::of(count($blank)))
            : null;

        $out = [];
        foreach ($owners as $key => $row) {
            if (! preg_match('/^sh(\d+)$/', (string) $key, $m)) {
                continue;
            }
            $id = (int) $m[1];
            $share = $custom[$id] ?? (in_array($id, $blank, true) ? $each : null);
            if ($share !== null) {
                $out[] = ['shareholder_id' => $id, 'pay' => $share->num, 'payda' => $share->den];
            }
        }

        return $out;
    }

    /** Atama satırları → tik listesi (kayıtlı hisse pay/payda olarak görünür). */
    private static function allocationsToOwners(array $allocations): array
    {
        $owners = [];
        foreach ($allocations as $a) {
            if (! empty($a['shareholder_id'])) {
                $owners['sh' . $a['shareholder_id']] = ['inc' => true, 'pay' => $a['pay'], 'payda' => $a['payda']];
            }
        }

        return $owners;
    }

    /** Bir BB'nin atamaları: tik listesi varsa ondan, yoksa (eski/iç çağrı) allocations'tan. */
    private static function itemAllocations(array $item): array
    {
        return array_key_exists('owners', $item)
            ? self::ownersToAllocations((array) $item['owners'])
            : (array) ($item['allocations'] ?? []);
    }

    // ── ③ Hisse Cetveli ───────────────────────────────────────────
    private function stepThree(): Step
    {
        return Step::make('Hisse Cetveli')
            ->icon('heroicon-o-table-cells')
            ->description('Sonuç')
            ->schema([
                Placeholder::make('cetvel')->hiddenLabel()
                    ->content(fn () => $this->cetvelHtml()),
            ]);
    }

    // ── Kaydetme adımları ─────────────────────────────────────────
    public function saveStep1(): void
    {
        $d = $this->data;

        $this->record->update([
            'project_id'      => $d['project_id'] ?? $this->record->project_id,
            'name'            => $d['name'] ?? $this->record->name,
            'ada'             => $d['ada'] ?? null,
            'parsel'          => $d['parsel'] ?? null,
            'block_count'     => $d['block_count'] ?? null,
            'units_per_block' => $d['units_per_block'] ?? null,
            'land_share_denominator' => filled($d['land_share_denominator'] ?? null) ? (int) $d['land_share_denominator'] : null,
            'notes'           => $d['notes'] ?? null,
        ]);

        $keep = [];
        foreach ($d['shareholders'] ?? [] as $row) {
            if (empty($row['name'])) {
                continue;
            }
            $attrs = [
                'name'          => $row['name'],
                'party_id'      => $row['party_id'] ?? null,
                'current_pay'   => (int) ($row['current_pay'] ?? 0),
                'current_payda' => (int) ($row['current_payda'] ?? 1),
                'is_contractor' => (bool) ($row['is_contractor'] ?? false),
                'group_key'     => $row['group_key'] ?? null,
            ];

            if (! empty($row['id']) && ($sh = $this->record->shareholders()->find($row['id']))) {
                $sh->update($attrs);
                $keep[] = $sh->id;
            } else {
                $keep[] = $this->record->shareholders()->create($attrs)->id;
            }
        }
        \App\Models\ActivityLog::touchParent($this->record); // işlem geçmişi: toplu alt satır değişikliği
        $this->record->shareholders()->whereNotIn('id', $keep ?: [0])->delete();

        $kept = $this->record->syncStructure($d['block_units'] ?? []);

        // Atama adımını taze yapıyla doldur.
        $this->data['sections'] = $this->buildAssignState();
        $this->data['shareholders'] = $this->record->shareholders()->orderBy('sort')->orderBy('id')->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'party_id' => $s->party_id,
                'current_pay' => $s->current_pay, 'current_payda' => $s->current_payda,
                'is_contractor' => (bool) $s->is_contractor, 'group_key' => $s->group_key,
            ])->all();

        // Enjekte edilen repeater state'i için Filament item container'larını
        // yeniden kur — aksi halde getItemLabel/getStateSnapshot null olur.
        $this->form->fill($this->data);

        $this->data['block_units'] = $this->blockUnitsState();

        Notification::make()->title('Bilgiler kaydedildi · bağımsız bölümler oluşturuldu')->success()->send();

        if ($kept !== []) {
            Notification::make()->title('Bazı BB\'ler silinmedi')
                ->body('Sayı azaltıldı ama şu BB\'lerde atama/arsa payı var, korundu: ' . implode(', ', $kept) . '. Silmek için önce atamalarını kaldırın.')
                ->warning()->persistent()->send();
        }
    }

    public function saveStep2(bool $notify = true): void
    {
        foreach ($this->data['sections'] ?? [] as $item) {
            $section = LandSection::find($item['section_id'] ?? null);
            if (! $section) {
                continue;
            }

            $section->update($this->landShareAttributes($item['arsa_pay'] ?? null, $item['arsa_payda'] ?? null));

            \App\Models\ActivityLog::touchParent($this->record); // işlem geçmişi: toplu alt satır değişikliği
            $section->allocations()->delete();
            foreach (self::itemAllocations($item) as $a) {
                if (empty($a['shareholder_id'])) {
                    continue;
                }
                $section->allocations()->create([
                    'shareholder_id' => $a['shareholder_id'],
                    'pay'            => (int) ($a['pay'] ?? 1),
                    'payda'          => (int) ($a['payda'] ?? 1),
                ]);
            }
        }

        if ($notify) {
            Notification::make()->title('Atamalar kaydedildi')->success()->send();
        }
    }

    public function finish(): void
    {
        $this->saveStep2();

        Notification::make()->title('Hisse cetveli kaydedildi')->success()->send();

        $this->redirect(LandShareStudyResource::getUrl('ledger', ['record' => $this->record]));
    }

    /** Boş kalan BB'leri müteahhide 1/1 verir (adım 2'deki toplu düğme). */
    public function fillEmptyWithContractor(): void
    {
        // Müteahhit işaretli değilse ilk kişiye VERME — "müteahhide verilir" denip boşlar Ahmet'e yazılmasın.
        $contractor = $this->record->shareholders()->where('is_contractor', true)->first();
        if (! $contractor) {
            Notification::make()->title('Müteahhit seçili değil')
                ->body('① adımda müteahhit olan hissedarın "Müteahhit" anahtarını açın.')
                ->warning()->send();

            return;
        }

        $this->saveStep2(notify: false);

        $count = 0;
        foreach ($this->record->blocks()->with('sections.allocations')->get() as $block) {
            foreach ($block->sections as $section) {
                if ($section->allocations->isEmpty()) {
                    $section->allocations()->create(['shareholder_id' => $contractor->id, 'pay' => 1, 'payda' => 1]);
                    $count++;
                }
            }
        }

        $this->data['sections'] = $this->buildAssignState();
        $this->form->fill($this->data);

        Notification::make()->title($count > 0 ? "{$count} boş BB {$contractor->name} adına atandı" : 'Boş BB kalmadı')->success()->send();
    }

    /** Saf hesap: her BB'nin paylarını mevcut hisse oranına göre yeniden yazar. */
    public function applyExistingShareSplit(array $sections): array
    {
        $existing = $this->existingShares();
        $touched = 0;
        foreach ($sections as $si => $section) {
            $ids = array_column(array_filter(self::itemAllocations($section), fn ($a) => ! empty($a['shareholder_id'])), 'shareholder_id');
            if ($ids === []) {
                continue;
            }
            $new = self::splitShares($ids, self::SPLIT_EXISTING, $existing);
            $sections[$si]['allocations'] = $new;
            $sections[$si]['owners'] = self::allocationsToOwners($new);
            $touched++;
        }

        return [$sections, $touched];
    }

    // ── Arsa payı ─────────────────────────────────────────────────
    /** Boş pay → arsa payı yok (ikisi de null); payda boşsa ortak payda. */
    private function landShareAttributes(mixed $pay, mixed $payda): array
    {
        $payda = filled($payda) ? (int) $payda : $this->record->land_share_denominator;

        if (! filled($pay) || ! $payda) {
            return ['arsa_pay' => null, 'arsa_payda' => null];
        }

        return ['arsa_pay' => (int) $pay, 'arsa_payda' => (int) $payda];
    }

    private function landShareFormState(): array
    {
        $state = [
            'denominator' => $this->record->land_share_denominator
                ?? collect($this->data['sections'] ?? [])->pluck('arsa_payda')->filter()->first(),
        ];

        foreach ($this->data['sections'] ?? [] as $item) {
            $state['bb_' . $item['section_id']] = $item['arsa_pay'] ?? null;
        }

        return $state;
    }

    private function landShareFormSchema(): array
    {
        $fields = [];
        foreach ($this->data['sections'] ?? [] as $item) {
            $fields[] = TextInput::make('bb_' . $item['section_id'])
                ->label($item['label'] ?? 'BB')
                ->numeric()->minValue(1);
        }

        return [
            TextInput::make('denominator')->label('Ortak Payda')
                ->numeric()->minValue(1)->required()->placeholder('ör. 1000'),
            Grid::make(['default' => 2, 'md' => 4])->schema($fields),
        ];
    }

    /** Toplu arsa payı girişini kaydeder; bekleyen (kaydedilmemiş) atamalara dokunmaz. */
    public function applyLandShares(array $input): void
    {
        $denominator = (int) $input['denominator'];
        $this->record->update(['land_share_denominator' => $denominator]);

        foreach ($this->data['sections'] ?? [] as $i => $item) {
            $attrs = $this->landShareAttributes($input['bb_' . $item['section_id']] ?? null, $denominator);

            \App\Models\ActivityLog::touchParent($this->record); // işlem geçmişi: toplu alt satır değişikliği
            LandSection::whereKey($item['section_id'])->update($attrs);
            $this->data['sections'][$i]['arsa_pay'] = $attrs['arsa_pay'];
            $this->data['sections'][$i]['arsa_payda'] = $attrs['arsa_payda'] ?? $denominator;
        }

        $this->data['land_share_denominator'] = $denominator;
        $this->form->fill($this->data);

        Notification::make()->title('Arsa payları kaydedildi')
            ->body(strip_tags((string) $this->landShareBadge($this->data['sections'])))
            ->success()->send();
    }

    /**
     * Saf hesap: girilmiş arsa paylarının 1/1'e kalanını arsa payı boş BB'lere
     * eşit böler. Ör. 2 BB × 2/18 girilmiş, 14 BB boş → her boşa 1/18.
     *
     * @return array{0: array, 1: int, 2: ?string}  [sections, doldurulan BB sayısı, BB başına pay "1/18"]
     */
    public function applyRemainingLandShare(array $sections): array
    {
        $sum = Fraction::zero();
        $den = $this->record->land_share_denominator ?? 1;
        $empty = [];

        foreach ($sections as $key => $item) {
            $attrs = $this->landShareAttributes($item['arsa_pay'] ?? null, $item['arsa_payda'] ?? null);
            if ($attrs['arsa_pay'] === null) {
                $empty[] = $key;

                continue;
            }
            $sum = $sum->add(Fraction::of($attrs['arsa_pay'], $attrs['arsa_payda']));
            $den = Fraction::lcm($den, $attrs['arsa_payda']);
        }

        $remaining = Fraction::of(1)->sub($sum);
        if ($empty === [] || $remaining->isZero() || $remaining->isNegative()) {
            return [$sections, 0, null];
        }

        $each = $remaining->div(Fraction::of(count($empty)));
        $den = Fraction::lcm($den, $each->den);   // mümkünse kullanıcının paydasıyla (1/18 → 1/18)

        foreach ($empty as $key) {
            $sections[$key]['arsa_pay'] = $each->numeratorOver($den);
            $sections[$key]['arsa_payda'] = $den;
        }

        return [$sections, count($empty), $each->numeratorOver($den) . '/' . $den];
    }

    private function remainingLandSharePreview(): string
    {
        [$sections, $count, $each] = $this->applyRemainingLandShare($this->data['sections'] ?? []);

        if ($count === 0) {
            return 'Dağıtılacak kalan pay yok (girilen arsa payları zaten 1/1 ya da aşıyor).';
        }

        return "Arsa payı boş {$count} BB'nin her birine {$each} yazılacak; toplam 1/1 olacak.";
    }

    public function fillRemainingLandShare(): void
    {
        [$sections, $count] = $this->applyRemainingLandShare($this->data['sections'] ?? []);

        if ($count === 0) {
            Notification::make()->title('Dağıtılacak kalan pay yok')->warning()->send();

            return;
        }

        foreach ($sections as $item) {
            LandSection::whereKey($item['section_id'])
                ->update($this->landShareAttributes($item['arsa_pay'] ?? null, $item['arsa_payda'] ?? null));
        }

        $this->data['sections'] = $sections;
        $this->form->fill($this->data);

        Notification::make()->title("{$count} BB'ye arsa payı dağıtıldı")
            ->body(strip_tags((string) $this->landShareBadge($sections)))
            ->success()->send();
    }

    /** Form state'inden canlı arsa payı rozeti (② adım üstü). */
    private function landShareBadge(array $sections): HtmlString
    {
        $data = new StudyData([], [], collect($sections)->mapWithKeys(fn ($s, $i) => [
            'sec' . $i => $this->landShareAttributes($s['arsa_pay'] ?? null, $s['arsa_payda'] ?? null),
        ])->all());

        ['state' => $state, 'sum' => $sum, 'missing' => $missing, 'denominator' => $den] = (new StudyValidator())->arsaSharesState($data);
        $total = $sum->toStringOver($this->record->land_share_denominator ?? $den);
        $hint = $missing > 0 ? ' Kalanların hepsi aynıysa sağ üstteki "Kalan Arsa Payını Boşlara Dağıt" düğmesini kullanın.' : '';

        return match ($state) {
            'none'     => new HtmlString('<span class="text-gray-500 dark:text-gray-400">Arsa payı girilmedi — her BB eşit sayılır.</span>'),
            'complete' => new HtmlString('<span class="text-success-600 dark:text-success-400">✓ Arsa payları tam — toplam ' . $total . ' (1/1). Hisseler arsa payına göre hesaplanacak.</span>'),
            default    => new HtmlString('<span class="text-warning-600 dark:text-warning-400">⚠ Arsa payları toplamı ' . $total
                . ($missing > 0 ? ' · ' . $missing . ' BB\'nin arsa payı boş' : '')
                . ' — 1/1 olmalı. Tamamlanana kadar her BB eşit sayılır.' . $hint . '</span>'),
        };
    }

    // ── Yardımcılar ───────────────────────────────────────────────
    /** Kayıtlı blokların BB sayıları: ['A' => 8, 'B' => 6]. */
    private function blockUnitsState(): array
    {
        $state = [];
        foreach (LandShareStudy::blockNames((int) $this->record->block_count) as $name) {
            $block = $this->record->blocks()->where('name', $name)->first();
            $state[$name] = $block?->planned_unit_count
                ?? ($block ? $block->sections()->count() : null)
                ?: $this->record->units_per_block;
        }

        return $state;
    }

    /** Blok sayısı artınca yeni bloklar bir öncekinin BB sayısıyla önerilir; azalınca fazlası düşer. */
    public function extendBlockUnits(int $count, array $units): array
    {
        $out = [];
        $last = null;
        foreach (LandShareStudy::blockNames($count) as $name) {
            $out[$name] = filled($units[$name] ?? null) ? $units[$name] : $last;
            $last = $out[$name];
        }

        return $out;
    }

    private function initialStep(): int
    {
        if (! $this->record->blocks()->exists()) {
            return 1;
        }

        $hasAlloc = LandSection::whereIn('block_id', $this->record->blocks()->pluck('id'))
            ->whereHas('allocations')->exists();

        return $hasAlloc ? 4 : 2;
    }

    private function buildAssignState(): array
    {
        $items = [];
        foreach ($this->record->blocks()->orderBy('sort')->orderBy('id')->get() as $block) {
            foreach ($block->sections()->orderBy('sort')->orderBy('id')->get() as $section) {
                $items[] = [
                    'section_id'  => $section->id,
                    'label'       => 'Blok ' . $block->name . ' · BB ' . $section->bb_no,
                    'arsa_pay'    => $section->arsa_pay,
                    // Payda boşsa çalışmanın ortak paydası önerilir.
                    'arsa_payda'  => $section->arsa_payda ?? $this->record->land_share_denominator,
                    'owners'      => self::allocationsToOwners($section->allocations->map(fn ($a) => [
                        'shareholder_id' => $a->shareholder_id,
                        'pay'            => $a->pay,
                        'payda'          => $a->payda,
                    ])->all()),
                ];
            }
        }

        return $items;
    }

    private function shareholderOptions(): array
    {
        return $this->record->shareholders()->orderBy('sort')->orderBy('id')->pluck('name', 'id')->toArray();
    }

    private function itemAssignSummary(array $state): string
    {
        $rows = self::itemAllocations($state);
        if ($rows === []) {
            return '⚠ boş';
        }

        $names = $this->shareholderOptions();
        $parts = [];
        foreach ($rows as $a) {
            $name = $names[$a['shareholder_id'] ?? null] ?? '?';
            $parts[] = $name . ' ' . ($a['pay'] ?? 1) . '/' . ($a['payda'] ?? 1);
        }

        return implode(', ', $parts);
    }

    private function currentShareBadge(Get $get): HtmlString
    {
        $sum = Fraction::zero();
        foreach ($get('shareholders') ?? [] as $row) {
            $payda = (int) ($row['current_payda'] ?? 0);
            if ($payda === 0) {
                continue;
            }
            $sum = $sum->add(Fraction::of((int) ($row['current_pay'] ?? 0), $payda));
        }

        return $sum->equals(Fraction::of(1))
            ? new HtmlString('<span class="text-success-600 dark:text-success-400">✓ Mevcut hisseler tam — toplam ' . $sum . ' (1/1)</span>')
            : new HtmlString('<span class="text-warning-600 dark:text-warning-400">⚠ Toplam ' . $sum . ' — 1/1 olmalı</span>');
    }

    private function allocationBadge(Get $get): HtmlString
    {
        $sum = Fraction::zero();
        $count = 0;
        foreach (self::ownersToAllocations((array) ($get('owners') ?? [])) as $row) {
            $payda = (int) ($row['payda'] ?? 0);
            if ($payda === 0) {
                continue;
            }
            $sum = $sum->add(Fraction::of((int) ($row['pay'] ?? 0), $payda));
            $count++;
        }

        if ($count === 0) {
            return new HtmlString('<span class="text-warning-600 dark:text-warning-400">⚠ Bu BB henüz dağıtılmadı</span>');
        }

        return $sum->equals(Fraction::of(1))
            ? new HtmlString('<span class="text-success-600 dark:text-success-400">✓ Dağılım tam — toplam ' . $sum . ' (1/1)</span>')
            : new HtmlString('<span class="text-warning-600 dark:text-warning-400">⚠ Toplam ' . $sum . ' — 1/1 olmalı</span>');
    }

    private function cetvelHtml(): HtmlString
    {
        $data = $this->record->toStudyData();
        $method = (new StudyValidator())->defaultMethod($data);

        if ($method === null) {
            return new HtmlString('<div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">Cetvel için kontroller tamam değil: mevcut hisseler 1/1 ve her BB dağılımı 1/1 olmalı.</div>');
        }

        $result = (new ShareCalculator())->calculate($data, $method);

        return new HtmlString(view('filament.land-share._cetvel', [
            'result'      => $result,
            'common'      => $result->commonDenominator(),
            'method'      => $method,
            'arsa'        => (new StudyValidator())->arsaSharesState($data),
            'denominator' => $this->record->land_share_denominator,
        ])->render());
    }
}
