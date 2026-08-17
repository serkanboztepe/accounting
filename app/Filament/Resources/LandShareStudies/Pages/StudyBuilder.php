<?php

namespace App\Filament\Resources\LandShareStudies\Pages;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use App\Models\LandSection;
use App\Services\LandShare\ShareCalculator;
use App\Services\LandShare\StudyValidator;
use App\Support\Fraction;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
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
        return 'Hisse Dağıtımı — ' . $this->record->name;
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
                ->url(fn () => route('land-share.print', ['study' => $this->record, 'auto' => 1]))
                ->openUrlInNewTab(),

            Action::make('distributeExisting')
                ->label('Mevcut Hisseye Göre Paylaştır')
                ->icon('heroicon-o-scale')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Mevcut Hisseye Göre Paylaştır')
                ->modalDescription('Paylaşımlı her bağımsız bölümdeki paylar, o BB\'ye eklenen kişilerin mevcut hisse oranına göre otomatik hesaplanır ve 1/1\'e tamamlanır. Tek kişilik BB\'ler 1/1 kalır.')
                ->modalSubmitActionLabel('Paylaştır')
                ->action(fn () => $this->distributeByExistingShares()),

            Action::make('fillContractor')
                ->label('Boş BB\'leri Müteahhide Ver')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Hiç dağıtılmamış BB\'ler, müteahhit hissedara 1/1 verilir.')
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
                    ->numeric()->minValue(0)->maxValue(26)->required()
                    ->helperText('Bloklar A, B, C… olarak oluşur.'),
                TextInput::make('units_per_block')->label('Blok Başına Daire Sayısı')
                    ->numeric()->minValue(1)->required()
                    ->helperText('"Tamam" deyince BB\'ler otomatik oluşur.'),

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
            ->description('Her bağımsız bölümü kime vereceğinizi seçin')
            ->afterValidation(fn () => $this->saveStep2())
            ->schema([
                Placeholder::make('assign_hint')
                    ->label(false)
                    ->content(fn () => count($this->data['sections'] ?? []) === 0
                        ? new HtmlString('<span class="text-warning-600">Önce ① adımda blok/daire sayısını girip "Tamam" deyin.</span>')
                        : new HtmlString('Boş bırakılan BB\'leri sonradan "Kaydet & Bitir" öncesi müteahhide toplu verebilirsiniz.')),

                Repeater::make('sections')->label(false)
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->collapsible()->collapsed()
                    ->itemLabel(fn (array $state): string => ($state['label'] ?? 'BB')
                        . ' — ' . $this->itemAssignSummary($state))
                    ->schema([
                        Hidden::make('section_id'),
                        Hidden::make('label'),

                        Placeholder::make('alloc_sum')
                            ->label('Dağılım kontrolü')
                            ->content(fn (Get $get) => $this->allocationBadge($get)),

                        Repeater::make('allocations')->label('Kime')
                            ->addActionLabel('Kişi ekle')->defaultItems(0)->columns(12)
                            ->schema([
                                Select::make('shareholder_id')->label('Hissedar')
                                    ->options(fn () => $this->shareholderOptions())
                                    ->searchable()->required()->columnSpan(6),
                                TextInput::make('pay')->label('Pay')
                                    ->numeric()->default(1)->required()->live(debounce: 400)->columnSpan(3),
                                TextInput::make('payda')->label('Payda')
                                    ->numeric()->default(1)->required()->minValue(1)->live(debounce: 400)->columnSpan(3),
                            ]),
                    ]),
            ]);
    }

    // ── ③ Hisse Cetveli ───────────────────────────────────────────
    private function stepThree(): Step
    {
        return Step::make('Hisse Cetveli')
            ->icon('heroicon-o-table-cells')
            ->description('Sonuç')
            ->schema([
                Placeholder::make('cetvel')->label(false)
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
        $this->record->shareholders()->whereNotIn('id', $keep ?: [0])->delete();

        $this->record->syncStructure();

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

        Notification::make()->title('Bilgiler kaydedildi · bağımsız bölümler oluşturuldu')->success()->send();
    }

    public function saveStep2(): void
    {
        foreach ($this->data['sections'] ?? [] as $item) {
            $section = LandSection::find($item['section_id'] ?? null);
            if (! $section) {
                continue;
            }

            $section->allocations()->delete();
            foreach ($item['allocations'] ?? [] as $a) {
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

        Notification::make()->title('Atamalar kaydedildi')->success()->send();
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
        $this->saveStep2();

        $contractor = $this->record->shareholders()->where('is_contractor', true)->first()
            ?? $this->record->shareholders()->first();

        if (! $contractor) {
            Notification::make()->title('Önce hissedar/müteahhit ekleyin')->warning()->send();

            return;
        }

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

    /**
     * Her paylaşımlı BB'deki payları, o BB'ye eklenen kişilerin mevcut hisse
     * oranına göre hesaplar (Excel GRUP mantığı, ama BB düzeyinde otomatik).
     * Kişi payı = kişinin mevcut hissesi ÷ o BB'deki kişilerin mevcut toplamı.
     */
    public function distributeByExistingShares(): void
    {
        [$sections, $touched] = $this->applyExistingShareSplit($this->data['sections'] ?? []);
        $this->data['sections'] = $sections;
        $this->form->fill($this->data);

        Notification::make()
            ->title($touched > 0 ? "{$touched} bağımsız bölüm mevcut hisseye göre paylaştırıldı" : 'Paylaştırılacak dolu BB yok')
            ->success()
            ->send();
    }

    /** Saf hesap: her BB'nin paylarını mevcut hisse oranına göre yeniden yazar. */
    public function applyExistingShareSplit(array $sections): array
    {
        $existing = [];
        foreach ($this->record->shareholders as $sh) {
            $existing[$sh->id] = Fraction::of((int) $sh->current_pay, max(1, (int) $sh->current_payda));
        }

        $touched = 0;
        foreach ($sections as $si => $section) {
            $valid = array_values(array_filter(
                $section['allocations'] ?? [],
                fn ($a) => ! empty($a['shareholder_id']),
            ));
            if ($valid === []) {
                continue;
            }

            $sum = Fraction::zero();
            foreach ($valid as $a) {
                $sum = $sum->add($existing[$a['shareholder_id']] ?? Fraction::zero());
            }

            $new = [];
            foreach ($valid as $a) {
                $share = $sum->isZero()
                    ? Fraction::of(1, count($valid))                       // hepsi 0 mevcutsa eşit böl
                    : ($existing[$a['shareholder_id']] ?? Fraction::zero())->div($sum);

                $new[] = [
                    'shareholder_id' => $a['shareholder_id'],
                    'pay'            => $share->num,
                    'payda'          => $share->den,
                ];
            }

            $sections[$si]['allocations'] = $new;
            $touched++;
        }

        return [$sections, $touched];
    }

    // ── Yardımcılar ───────────────────────────────────────────────
    private function initialStep(): int
    {
        if (! $this->record->blocks()->exists()) {
            return 1;
        }

        $hasAlloc = LandSection::whereIn('block_id', $this->record->blocks()->pluck('id'))
            ->whereHas('allocations')->exists();

        return $hasAlloc ? 3 : 2;
    }

    private function buildAssignState(): array
    {
        $items = [];
        foreach ($this->record->blocks()->orderBy('sort')->orderBy('id')->get() as $block) {
            foreach ($block->sections()->orderBy('sort')->orderBy('id')->get() as $section) {
                $items[] = [
                    'section_id'  => $section->id,
                    'label'       => 'Blok ' . $block->name . ' · BB ' . $section->bb_no,
                    'allocations' => $section->allocations->map(fn ($a) => [
                        'shareholder_id' => $a->shareholder_id,
                        'pay'            => $a->pay,
                        'payda'          => $a->payda,
                    ])->all(),
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
        $rows = $state['allocations'] ?? [];
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
        foreach ($get('allocations') ?? [] as $row) {
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
            'result' => $result,
            'common' => $result->commonDenominator(),
            'method' => $method,
        ])->render());
    }
}
