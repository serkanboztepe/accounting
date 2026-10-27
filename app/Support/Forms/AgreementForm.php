<?php

namespace App\Support\Forms;

use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Party;
use App\Models\Product;
use App\Models\Project;
use App\Models\Unit;
use App\Support\AgreementDesk;
use App\Support\Labels;
use App\Support\Money;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Anlaşma pencereleri (cari sayfası + Anlaşmalar menüsü aynı formu kullanır). Kullanıcı tür seçmez:
 * kalemde miktar yazarsa miktarlı, yalnız tutar götürü, yalnız fiyat fiyat listesi (AgreementDesk::normalizeLines).
 */
class AgreementForm
{
    /**
     * 🤝 Anlaşma yap. $withParty: menüden açılınca ilk alan cari. $fixedDirection: cari tipi müşteri / tedarikçiyse yön sabit, seçim
     * gösterilmez (27.10).
     */
    public static function createSchema(bool $withParty = false, ?string $fixedDirection = null): array
    {
        return array_values(array_filter([
            $fixedDirection ? Hidden::make('direction')->default($fixedDirection) : ToggleButtons::make('direction')
                ->hiddenLabel()
                ->options([Agreement::DIRECTION_PURCHASE => 'Biz alıyoruz', Agreement::DIRECTION_SALE => 'Biz satıyoruz'])
                ->icons([Agreement::DIRECTION_PURCHASE => 'heroicon-o-arrow-down-tray', Agreement::DIRECTION_SALE => 'heroicon-o-arrow-up-tray'])
                ->colors([Agreement::DIRECTION_PURCHASE => 'warning', Agreement::DIRECTION_SALE => 'info'])
                ->default(Agreement::DIRECTION_PURCHASE)
                ->inline()
                ->required()
                ->live(),

            $withParty ? Select::make('party_id')
                ->label('Kişi / Firma')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set, $state) => $set('direction', self::lastDirection($state ? (int) $state : null))) : null,

            DatePicker::make('agreement_date')->label('Tarih')->default(now())->required(),

            self::projectSelect('project_id')
                ->helperText('İsteğe bağlı. Teslimatlar bu projeye yazılır; teslimde değiştirebilirsin.'),

            self::linesRepeater(),

            Text::make(fn (Get $get) => self::totalText($get('lines')))
                ->weight('bold'),

            MoneyInput::make('budget', 'Bütçe (isteğe bağlı)')
                ->required(false)
                ->helperText('Miktarsız fiyat listesinde: en çok ne kadar tutar? Aşılınca kartta görünür.')
                ->visible(fn (Get $get) => self::hasListLine($get('lines'))),

            TextInput::make('title')
                ->label('Anlaşma adı')
                ->placeholder(fn (Get $get) => self::titlePreview($get('lines'), $get('project_id')))
                ->helperText('Boş bırakırsan yukarıdaki ad kullanılır.')
                ->maxLength(255),

            Toggle::make('advance')
                ->label(fn (Get $get) => $get('direction') === Agreement::DIRECTION_SALE ? 'Kapora / peşinat aldım' : 'Peşinat verdim')
                ->live(),
            MoneyInput::make('advance_amount', 'Peşinat tutarı')
                ->required(fn (Get $get) => (bool) $get('advance'))
                ->visible(fn (Get $get) => (bool) $get('advance')),
            Select::make('advance_payment_type')
                ->label('Ödeme yöntemi')
                ->options(array_filter([
                    'cash'          => 'Nakit',
                    'eft'           => 'EFT',
                    'bank_transfer' => 'Havale',
                    'check'         => \App\Support\Features::checks() ? 'Çek' : null,
                    'other'         => 'Diğer',
                ]))
                ->default('cash')
                ->live()
                ->visible(fn (Get $get) => (bool) $get('advance')),
            TextInput::make('check_number')->label('Çek no')
                ->visible(fn (Get $get) => $get('advance') && $get('advance_payment_type') === 'check'),
            TextInput::make('bank_name')->label('Banka')
                ->visible(fn (Get $get) => $get('advance') && $get('advance_payment_type') === 'check'),
            DatePicker::make('check_due_date')->label('Vade')
                ->required(fn (Get $get) => $get('advance') && $get('advance_payment_type') === 'check')
                ->visible(fn (Get $get) => $get('advance') && $get('advance_payment_type') === 'check'),

            Text::make('Anlaşma borç yazmaz. Mal ya da iş gelince kartındaki "Teslim aldım / Teslim ettim" ile girersin.')
                ->color('gray'),

            TextInput::make('notes')->label('Not')->maxLength(255),
        ]));
    }

    /** + Kalem ekle (anlaşmanın yönü bellidir). */
    public static function addItemsSchema(): array
    {
        return [self::linesRepeater(defaultItems: 1), Text::make(fn (Get $get) => self::totalText($get('lines')))->weight('bold')];
    }

    /**
     * Alım Yaptım / Satış Yaptım penceresi (kullanıcı 21.10): carinin o yönde açık anlaşma kalemi varsa pencere iki sekme olur —
     * "Anlaşmadan" (bekleyen kalemler, en eski önce: işaretle + miktar, götürüde tutar; fiyat anlaşmadan) ve "Anlaşma dışı"
     * (pencerenin eski alanları aynen). Sekme değiştirmek bir şey silmez: kaydedince işaretli kalemler de anlaşma dışı kısım da
     * yazılır ("anlaşmadan seçtiklerim kaydolmadı" — gizlenen alan kaydedilmiyordu, sekmede kalıyor).
     * Açık kalem yoksa bileşenler AYNEN döner (pencere bugünkü gibi). $keep: sekmelerin üstünde kalanlar (tarih, peşin).
     * fillForm değil default: pencerenin diğer alanlarının varsayılanları ezilmesin.
     */
    public static function withAgreementTabs(array $components, string $direction, ?Party $party, array $keep = [],
        ?\Illuminate\Support\Collection $items = null, bool $projectField = true): array
    {
        $items ??= $party && \App\Support\Features::agreements() ? AgreementDesk::openItems($party, $direction) : collect();
        if ($items->isEmpty()) {
            return $components;
        }
        $isSale = $direction === Agreement::DIRECTION_SALE;

        $above = $rest = [];
        foreach ($components as $component) {
            if (method_exists($component, 'getName') && in_array($component->getName(), $keep, true)) {
                $above[] = $component;
            } else {
                $rest[] = $component;
            }
        }

        return [
            ...$above,
            Tabs::make('agreement_tabs')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Anlaşmadan')
                        ->icon('heroicon-o-hand-raised')
                        // İşaretli kalem sayısı: öbür sekmeye geçince de kaydedileceği görünsün.
                        ->badge(fn (Get $get) => ($n = collect($get('agreement_lines') ?? [])->filter(fn ($l) => ! empty($l['take']))->count()) ?: null)
                        ->schema(self::pendingFields($items, $isSale, $projectField)),
                    Tab::make('Anlaşma dışı')
                        ->icon('heroicon-o-pencil-square')
                        ->schema($rest),
                ]),
        ];
    }

    private static function pendingFields($items, bool $isSale, bool $projectField = true): array
    {
        return array_values(array_filter([
            Repeater::make('agreement_lines')
                ->hiddenLabel()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->live()
                ->columns(['default' => 1, 'md' => 3])
                ->default($items->map(fn (AgreementItem $i) => [
                    'agreement_item_id' => $i->id,
                    'is_lump'           => $i->is_lump,
                    'take'              => false,
                    'quantity'          => null,
                    'unit_price'        => $i->is_lump || $i->unit_price === null ? null : Money::format((float) $i->unit_price),
                    'amount'            => null,
                ])->values()->all())
                ->schema([
                    Hidden::make('agreement_item_id'),
                    Hidden::make('is_lump'),
                    Checkbox::make('take')
                        ->label(fn (Get $get) => self::itemLabel($get('agreement_item_id'), true))
                        ->live()
                        ->columnSpanFull(),
                    TextInput::make('quantity')
                        ->label(fn (Get $get) => ($isSale ? 'Teslim edilen' : 'Gelen') . ' miktar'
                            . (($u = self::unitOf($get('agreement_item_id'))) ? ' (' . $u . ')' : ''))
                        ->numeric()
                        ->minValue(0.01)
                        ->required(fn (Get $get) => (bool) $get('take'))
                        ->visible(fn (Get $get) => $get('take') && ! $get('is_lump')),
                    MoneyInput::make('unit_price', 'Birim fiyat')
                        ->required(false)
                        ->visible(fn (Get $get) => $get('take') && ! $get('is_lump')),
                    MoneyInput::make('amount', 'Yapılan iş tutarı')
                        ->required(fn (Get $get) => (bool) $get('take'))
                        ->helperText('Götürü işin ne kadarı yapıldı? (ör. yarısıysa toplamın yarısı)')
                        ->visible(fn (Get $get) => $get('take') && $get('is_lump')),
                ]),

            // Projesiz (Genel) anlaşmadan gelen mal: proje SEÇİLMEDEN kaydedilmez — "Anlaşmadaki proje" sessizce Genel oluyordu
            // (firma 7 22.10: iki projelik seramik anlaşması, 85.710 ₺ teslimat Genel'e gitti). Genel de açık seçenek.
            $projectField && \App\Support\Features::places() ? Select::make('agreement_project')
                ->label(Labels::project())
                ->options(fn (Get $get) => (self::takenWithoutProject($get) ? [] : ['agreement' => 'Anlaşmadaki ' . mb_strtolower(Labels::project())])
                    + Project::whereNotIn('status', ['cancelled'])->orderBy('name')->pluck('name', 'id')->all()
                    + [self::GENERAL => 'Genel (projesiz)'])
                ->default($items->every(fn (AgreementItem $i) => $i->agreement->project_id !== null) ? 'agreement' : null)
                ->required(fn (Get $get) => self::takenWithoutProject($get))
                ->validationMessages(['required' => 'Bu anlaşmanın projesi yok — malın geldiği projeyi seç (ya da Genel).'])
                ->visible(fn (Get $get) => self::anyTaken($get)) : null,

            $projectField ? Toggle::make('agreement_price_to_rest')
                ->label('Fiyatı değiştirdiysem kalanlar da yeni fiyattan')
                ->helperText('Kapalıysa yeni fiyat yalnız bu teslimatta geçer.')
                ->visible(fn (Get $get) => self::anyTaken($get)) : null,
        ]));
    }

    /** Pencerede anlaşma kalemi işaretlendi mi? (alt kısmın tutar/ürün zorunluluğu o zaman düşer) */
    public const GENERAL = 'general';

    /**
     * Alış düzenleme (cari ekstresi, kullanıcı 22.10: "alım yapın aynısı olsun, anlaşmadan olanlar seçili gelsin"): bu alışa bağlı
     * kalemler + carinin açık kalemleri. Bağlı kalem kapanmış olsa da listede (işaretini kaldırınca anlaşmaya geri döner).
     */
    public static function purchaseEditItems(\App\Models\Purchase $purchase): \Illuminate\Support\Collection
    {
        $linked = AgreementItem::with(['agreement.project', 'product.unit', 'movements'])
            ->whereIn('id', $purchase->lines()->whereNotNull('agreement_item_id')->pluck('agreement_item_id'))->get();
        $open = $purchase->party ? AgreementDesk::openItems($purchase->party, Agreement::DIRECTION_PURCHASE) : collect();

        return $linked->concat($open)->unique('id')->sortBy('id')->values();
    }

    /** Sekmeli düzenlemenin dolu hâli: bağlı kalemler işaretli + bu alıştaki miktar/fiyat; anlaşma dışı satırlar "lines"ta. */
    public static function purchaseEditFill(\App\Models\Purchase $purchase, \Illuminate\Support\Collection $items): array
    {
        $moves = $purchase->lines()->orderBy('id')->get();
        $byItem = $moves->whereNotNull('agreement_item_id')->groupBy('agreement_item_id');

        return [
            'agreement_lines' => $items->map(function (AgreementItem $i) use ($byItem) {
                $rows = $byItem->get($i->id);
                $price = $rows?->first()?->unit_price ?? $i->unit_price;

                return [
                    'agreement_item_id' => $i->id,
                    'is_lump'           => $i->is_lump,
                    'take'              => $rows !== null,
                    'quantity'          => $rows && ! $i->is_lump ? number_format((float) $rows->sum('quantity'), 2, '.', '') : null,
                    'unit_price'        => $i->is_lump || $price === null ? null : Money::format((float) $price),
                    'amount'            => $rows && $i->is_lump ? Money::format((float) $rows->sum('amount')) : null,
                ];
            })->values()->all(),
            'lines' => $moves->whereNull('agreement_item_id')->map(fn ($m) => [
                'product_id' => $m->product_id,
                'quantity'   => number_format((float) $m->quantity, 2, '.', ''),
                'unit_price' => $m->unit_price !== null ? Money::format((float) $m->unit_price) : null,
                'amount'     => $m->amount !== null ? Money::format((float) $m->amount) : null,
            ])->values()->all(),
        ];
    }

    /** İşaretli anlaşma satırları → alış satırları (anlaşma bağıyla; götürüde miktar = tutar ÷ toplam, AgreementDesk::deliver gibi). */
    public static function agreementLinesToPurchaseLines(array $taken, int $partyId): array
    {
        $out = [];
        foreach ($taken as $l) {
            $item = AgreementItem::with('agreement')->find($l['agreement_item_id'] ?? null);
            if (! $item || $item->agreement->party_id !== $partyId || $item->agreement->direction !== Agreement::DIRECTION_PURCHASE) {
                continue;
            }
            if ($item->is_lump) {
                $amount = Money::parse($l['amount'] ?? null);
                $total = (float) $item->unit_price;
                if ($amount > 0) {
                    $out[] = ['product_id' => $item->product_id, 'quantity' => max($total > 0 ? round($amount / $total, 2) : 1.0, 0.01),
                        'unit_price' => null, 'amount' => $amount, 'agreement_item_id' => $item->id];
                }

                continue;
            }
            $qty = (float) str_replace(',', '.', (string) ($l['quantity'] ?? 0));
            if ($qty > 0) {
                $out[] = ['product_id' => $item->product_id, 'quantity' => $qty, 'agreement_item_id' => $item->id,
                    // rebuildFromLines Money::parse eder — ham "565.00" nokta binlik sanılmasın.
                    'unit_price' => blank($l['unit_price'] ?? null) ? Money::format((float) $item->unit_price) : $l['unit_price']];
            }
        }

        return $out;
    }

    /** Proje seçimi zorunlu, "Genel (projesiz)" açık seçenek (kullanıcı 22.10: "genel ise bile seçtirmek lazım, gözümden kaçabilir"). */
    public static function requiredProjectSelect(string $name): Select
    {
        return Select::make($name)
            ->label(fn () => Labels::project())
            ->options(fn () => Project::whereNotIn('status', ['cancelled'])->orderBy('name')->pluck('name', 'id')->all()
                + [self::GENERAL => 'Genel (projesiz)'])
            ->required(fn () => (bool) \App\Support\Features::places())
            ->validationMessages(['required' => 'Projeyi seç (ya da Genel).'])
            ->visible(fn () => (bool) \App\Support\Features::places());
    }

    /** İşaretli kalemlerden biri projesiz anlaşmada mı. */
    private static function takenWithoutProject(Get $get): bool
    {
        $ids = collect($get('agreement_lines') ?? [])->filter(fn ($l) => ! empty($l['take']))->pluck('agreement_item_id')->filter();

        return $ids->isNotEmpty() && AgreementItem::whereIn('id', $ids)->whereHas('agreement', fn ($q) => $q->whereNull('project_id'))->exists();
    }

    public static function anyTaken(Get $get, string $path = 'agreement_lines'): bool
    {
        return collect($get($path) ?? [])->contains(fn ($l) => ! empty($l['take']));
    }

    /**
     * Pencere verisinden anlaşma kısmını ayır: [işaretli satırlar, proje seçimi, kalanlara fiyat, geri kalan veri].
     *
     * @return array{0: array, 1: mixed, 2: bool, 3: array}
     */
    public static function extract(array $data): array
    {
        $lines = array_values(array_filter((array) ($data['agreement_lines'] ?? []), fn ($l) => ! empty($l['take'])));
        $project = \App\Support\Features::places() ? ($data['agreement_project'] ?? null) : 'agreement';
        $project = $project === self::GENERAL ? null : $project;
        $priceToRest = (bool) ($data['agreement_price_to_rest'] ?? false);
        unset($data['agreement_lines'], $data['agreement_project'], $data['agreement_price_to_rest']);

        return [$lines, $project, $priceToRest, $data];
    }

    public static function projectSelect(string $name): Select
    {
        return Select::make($name)
            ->label(fn () => Labels::project())
            ->options(fn () => Project::whereNotIn('status', ['cancelled'])->orderBy('name')->pluck('name', 'id'))
            ->placeholder('Genel')
            ->searchable()
            ->live()
            ->visible(fn () => (bool) \App\Support\Features::places());
    }

    /** Bu cariyle son anlaşmanın yönü (yoksa alış) — pencere o seçili açılır. */
    public static function lastDirection(?int $partyId): string
    {
        return ($partyId ? Agreement::where('party_id', $partyId)->latest('id')->value('direction') : null)
            ?? Agreement::DIRECTION_PURCHASE;
    }

    private static function linesRepeater(int $defaultItems = 1): Repeater
    {
        return Repeater::make('lines')
            ->label(fn (Get $get) => $get('direction') === Agreement::DIRECTION_SALE ? 'Ne satıyoruz?' : 'Ne için anlaştınız?')
            ->addActionLabel('Kalem ekle')
            ->defaultItems($defaultItems)
            ->columns(['default' => 1, 'md' => 6])
            ->columnSpanFull()
            ->live()
            ->helperText('Miktar yazmazsan: yalnız tutar = götürü iş, yalnız birim fiyat = fiyat listesi (metraja göre).')
            ->schema([
                Select::make('product_id')
                    ->label('Ürün / iş')
                    ->options(fn () => Product::active()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    // Aramada yoksa en altta "➕ Yeni: <yazılan>" — tıklayınca kart o an açılır (kullanıcı 21.10: "kolay oluşturma").
                    ->getSearchResultsUsing(fn (?string $search): array => self::productSearch($search))
                    ->getOptionLabelUsing(fn ($value): ?string => Product::find($value)?->name)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                        $buy = ($get('../../direction') ?? Agreement::DIRECTION_PURCHASE) !== Agreement::DIRECTION_SALE;
                        if (is_string($state) && str_starts_with($state, self::NEW_PREFIX)) {
                            $state = self::quickProduct(substr($state, strlen(self::NEW_PREFIX)), [], $buy);
                            $set('product_id', $state);
                        }
                        $product = $state ? Product::find($state) : null;
                        $price = $product ? ($buy ? $product->buyPrice() : $product->default_price) : null;
                        if ($price !== null && blank($get('unit_price'))) {
                            $set('unit_price', Money::format((float) $price));
                            self::recalc($get, $set);
                        }
                    })
                    // Birimiyle açmak için + penceresi (birim yazılır; yoksa kendiliğinden eklenir).
                    ->createOptionForm([
                        TextInput::make('name')->label('Ad')->required()->maxLength(255),
                        TextInput::make('unit_name')->label('Birim (isteğe bağlı)')
                            ->datalist(fn () => Unit::orderBy('name')->pluck('name')->all())
                            ->placeholder('ör. m³, ton, adet, gün')
                            ->maxLength(50),
                        Radio::make('type')->label('Tür')->options(Product::TYPE_LABELS)
                            ->default(Product::TYPE_PRODUCT)->inline()
                            ->visible(fn () => (bool) \App\Support\Features::stock()),
                    ])
                    ->createOptionModalHeading('Yeni ürün / iş')
                    ->createOptionUsing(fn (array $data, Get $get): int => self::quickProduct($data['name'], $data,
                        ($get('../../direction') ?? Agreement::DIRECTION_PURCHASE) !== Agreement::DIRECTION_SALE))
                    ->columnSpan(['default' => 1, 'md' => 2]),

                TextInput::make('quantity')
                    ->label(fn (Get $get) => 'Miktar' . (($u = Product::with('unit')->find($get('product_id'))?->unit?->name) ? ' (' . $u . ')' : ''))
                    ->numeric()
                    ->minValue(0.01)
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalc($get, $set)),

                MoneyInput::make('unit_price', 'Birim fiyat')
                    ->required(false)
                    ->live(debounce: 400)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::recalc($get, $set)),

                MoneyInput::make('amount', 'Tutar')
                    ->required(false)
                    ->live(debounce: 400)
                    ->helperText(fn (Get $get) => self::kindHint($get('quantity'), $get('unit_price'), $get('amount')))
                    ->columnSpan(['default' => 1, 'md' => 2]),
            ]);
    }

    private const NEW_PREFIX = 'new:';

    /** Ürün araması + bulunamazsa "➕ Yeni: …" seçeneği (aynı ad zaten varsa önerilmez). */
    public static function productSearch(?string $search): array
    {
        $search = trim((string) $search);
        $results = Product::active()
            ->when($search !== '', fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($search) . '%']))
            ->orderBy('name')->limit(50)->pluck('name', 'id')->all();
        $exact = collect($results)->contains(fn ($name) => mb_strtolower($name) === mb_strtolower($search));
        if ($search !== '' && ! $exact) {
            $results[self::NEW_PREFIX . $search] = '➕ Yeni: ' . $search;
        }

        return $results;
    }

    /**
     * Kart hızlı açılır (aynı adlı varsa o). Tür: stok modülü açıksa seçilen/ürün, değilse hizmet (stoksuz).
     * Birim yazıldıysa birim bulunur ya da eklenir (müteahhitte Birimler sayfası kapalı — burada çözülür).
     */
    public static function quickProduct(string $name, array $data, bool $buy): int
    {
        $unitId = null;
        if (filled($unitName = trim((string) ($data['unit_name'] ?? '')))) {
            $unit = Unit::whereRaw('LOWER(name) = ?', [mb_strtolower($unitName)])->first();
            $unitId = ($unit ?? Unit::create(['name' => $unitName, 'code' => self::unitCode($unitName)]))->id;
        }

        return TradeLines::createProduct([
            'name'    => $name,
            'type'    => \App\Support\Features::stock() ? ($data['type'] ?? Product::TYPE_PRODUCT) : Product::TYPE_SERVICE,
            'unit_id' => $unitId,
        ], $buy ? 'in' : 'out');
    }

    private static function unitCode(string $name): string
    {
        $base = str($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '')->limit(20, '')->value() ?: 'birim';
        $code = $base;
        for ($i = 2; Unit::where('code', $code)->exists(); $i++) {
            $code = $base . $i;
        }

        return $code;
    }

    /** Miktar + fiyat → tutar. Miktar silinirse karttan hesaplanan tutar da silinir (götürü sanılmasın). */
    private static function recalc(Get $get, Set $set): void
    {
        $price = Money::parse($get('unit_price'));
        $qty = (float) str_replace(',', '.', (string) $get('quantity'));
        if ($price && $qty) {
            $set('amount', Money::format($qty * $price));
        } elseif (blank($get('quantity')) && $price && Money::parse($get('amount')) && abs(Money::parse($get('amount')) - $price) > 0.001) {
            // Miktar silindi: önceki çarpım tutarı kalmasın.
            $set('amount', null);
        }
    }

    private static function kindHint($qty, $price, $amount): ?string
    {
        if (filled($qty) && (float) str_replace(',', '.', (string) $qty) > 0) {
            return null;
        }
        if (Money::parse($amount) > 0) {
            return 'Götürü iş';
        }

        return Money::parse($price) > 0 ? 'Fiyat listesi (miktar teslimde)' : null;
    }

    private static function totalText(?array $lines): string
    {
        try {
            $normalized = AgreementDesk::normalizeLines($lines ?? []);
        } catch (\Throwable) {
            $normalized = [];
        }
        $total = collect($normalized)->sum(fn ($l) => $l['quantity'] === null ? 0 : $l['quantity'] * $l['unit_price']);

        return 'Anlaşılan toplam: ' . Money::format($total) . ' ₺';
    }

    private static function hasListLine(?array $lines): bool
    {
        foreach ($lines ?? [] as $line) {
            if (blank($line['quantity'] ?? null) && Money::parse($line['amount'] ?? null) <= 0 && Money::parse($line['unit_price'] ?? null) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function titlePreview(?array $lines, $projectId): string
    {
        $valid = array_values(array_filter($lines ?? [], fn ($l) => ! empty($l['product_id'])));

        return $valid ? AgreementDesk::autoTitle($valid, $projectId ? (int) $projectId : null) : 'ör. Beton · Yeşilyurt';
    }

    public static function itemLabel($itemId, bool $withAgreement): string
    {
        $item = $itemId ? AgreementItem::with(['agreement', 'product.unit', 'movements'])->find($itemId) : null;
        if (! $item) {
            return '—';
        }
        $head = $item->name() . ($withAgreement ? ' · ' . $item->agreement->title : '');
        $rest = match (true) {
            $item->is_lump  => 'götürü ' . Money::format((float) $item->unit_price) . ' ₺ · yapılan %' . (int) round($item->deliveredQuantity() * 100),
            $item->isList() => 'liste · ' . Money::format((float) $item->unit_price) . ' ₺' . ($item->unitName() ? '/' . $item->unitName() : '')
                . ' · gelen ' . $item->quantityLabel($item->deliveredQuantity()),
            default         => 'kalan ' . $item->quantityLabel($item->remainingQuantity()) . ' × ' . Money::format((float) $item->unit_price) . ' ₺',
        };

        return $head . ' — ' . $rest;
    }

    private static function unitOf($itemId): ?string
    {
        return $itemId ? AgreementItem::with('product.unit')->find($itemId)?->unitName() ?: null : null;
    }
}
