<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Resources\Parties\PartyResource;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Resources\Sales\Schemas\SaleReturnForm;
use App\Support\Money;
use App\Support\PartyStatement;
use Filament\Notifications\Notification;
use App\Support\Forms\MoneyInput;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use App\Models\Party;
use App\Support\PartyDeletion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EditParty extends EditRecord
{
    protected static string $resource = PartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Cari bilgileri (isim/telefon/not) artık üstteki ⚙ Ayarlar modalından düzenlenir —
            // sayfa gövdesi tamamen Cari Ekstresi'ne ayrıldı.
            Action::make('settings')
                ->label('Ayarlar')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->modalHeading('Cari Bilgileri')
                ->modalSubmitActionLabel('Kaydet')
                ->fillForm(fn (): array => [
                    'name'  => $this->record->name,
                    'phone' => $this->record->phone,
                    'notes' => $this->record->notes,
                ])
                ->schema([
                    TextInput::make('name')->label('İsim')->required()->maxLength(255),
                    TextInput::make('phone')->label('Telefon')->maxLength(255),
                    Textarea::make('notes')->label('Notlar')->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    $this->record->update($data);
                }),

            DeleteAction::make()
                ->modalDescription(fn (Party $record) => PartyDeletion::description($record))
                ->before(function (Party $record, DeleteAction $action) {
                    if (PartyDeletion::blockers($record)) {
                        Notification::make()->danger()->title('Cari silinemedi')->body(PartyDeletion::description($record))->send();
                        $action->halt();
                    }
                }),
        ];
    }

    /**
     * Sayfa gövdesinden inline form + alttaki Kaydet bar'ı kaldır — düzenleme ⚙ Ayarlar modalından.
     * Gövde sadece ilişki yöneticileri (yok) kalır; Cari Ekstresi getFooter()'dan gelir.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }

    /**
     * Cari Ekstresi'ndeki tek liste bunları kullanır (grid kaldırıldı):
     *   - Üstteki butonlar → newLedgerEntry (tip argümanla)
     *   - Satıra tıkla → editLedgerEntry (Sil pencere içinde)
     * Aksiyonlar bu sayfada olduğu için, çalıştıktan sonra sayfa otomatik
     * yeniden render olur → getFooter() tekrar hesaplanır → ekstre canlı güncellenir.
     */

    /** Ödeme tarihi alanı yalnız satış ve alış satırlarında (tahsilat/ödeme paranın kendisi). */
    private const DUE_DATE_TYPES = [PartyLedgerEntry::TYPE_SALE, PartyLedgerEntry::TYPE_PURCHASE];

    /**
     * Elle cari hareketi için ortak form alanları (yön/tip yok — tip butondan gelir).
     * $type null → düzenleme: alan hep var, satırın tipine göre (gizli 'type' alanı) görünür; yoksa
     * form tip bilinmeden kurulup mevcut ödeme tarihi doldurulmuyor, kaydedince siliniyordu.
     */
    protected function ledgerFormSchema(?string $type = null): array
    {
        $dueDate = DatePicker::make('due_date')
            ->label('Ödeme tarihi (opsiyonel)')
            ->helperText('Ne zaman ödenecek? O sabah WhatsApp\'tan hatırlatılır; ödendiyse hatırlatılmaz.')
            ->afterOrEqual('entry_date');

        return array_values(array_filter([
            $type === null ? Hidden::make('type')->dehydrated(false) : null,

            DatePicker::make('entry_date')
                ->label('Tarih')
                ->default(now())
                ->required(),

            match (true) {
                $type === null => $dueDate->visible(fn (Get $get) => in_array($get('type'), self::DUE_DATE_TYPES, true)),
                in_array($type, self::DUE_DATE_TYPES, true) => $dueDate,
                default => null,
            },

            MoneyInput::make('amount', 'Tutar'),

            Select::make('project_id')
                ->label('Proje (opsiyonel)')
                ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->helperText('Etiket/çıktı içindir — proje maliyet raporuna girmez.')
                ->visible(fn () => config('modules.projects')),

            TextInput::make('description')
                ->label('Açıklama')
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('notes')
                ->label('Not')
                ->rows(2)
                ->columnSpanFull(),
        ]));
    }

    /** Üstteki Satış/Tahsilat/Alış/Ödeme butonları bu aksiyonu tip argümanıyla mount eder. */
    public function newLedgerEntryAction(): Action
    {
        return Action::make('newLedgerEntry')
            // Esnafın ağzından: ne yaptığını ve bakiyeye etkisini söyler ("Cariyi borçlandır" yerine).
            ->modalHeading(fn (array $arguments): string => match ($arguments['type'] ?? null) {
                'satis'    => 'Satış Yaptım · ' . $this->record->name . ' borçlanır',
                'tahsilat' => 'Ödeme Aldım · ' . $this->record->name . ' borcu azalır',
                'alis'     => 'Alım Yaptım · sen borçlanırsın',
                'odeme'    => 'Ödeme Yaptım · borcun azalır',
                default    => 'Yeni Hareket',
            })
            ->modalSubmitActionLabel('Kaydet')
            ->schema(fn (array $arguments): array => $this->ledgerFormSchema($arguments['type'] ?? null))
            ->action(function (array $data, array $arguments): void {
                $this->record->ledgerEntries()->create([
                    ...$data,
                    'type' => $arguments['type'] ?? null,
                ]);
            });
    }

    /**
     * Kompleks (kalemli) satış — Cari Ekstresi içinden. direct_sales açıkken "Satış" butonu buna bağlanır.
     * Sale::rebuildFromLines() stok çıkışı + cari borç satırını (tek doğruluk noktası) kendisi yazar.
     */
    public function newSaleAction(): Action
    {
        return Action::make('newSale')
            ->modalHeading(fn (): string => 'Satış Yaptım · ' . $this->record->name . ' borçlanır')
            ->modalSubmitActionLabel('Kaydet')
            ->schema(array_merge(
                SaleForm::components(withParty: false, dehydrateLines: true),
                [
                    // Faz 2 — peşin: işaretlenirse satış toplamı kadar tahsilat (alacak) aynı anda düşer.
                    Toggle::make('collected_now')->label('Tahsil edildi (peşin)')->default(false)->live(),
                    Select::make('payment_type')->label('Ödeme Yöntemi')
                        ->options(array_filter([
                            'cash'          => 'Nakit',
                            'bank_transfer' => 'Havale',
                            'eft'           => 'EFT',
                            'check'         => config('modules.checks') ? 'Çek' : null,
                            'other'         => 'Diğer',
                        ]))
                        ->default('cash')
                        ->live()
                        ->visible(fn (Get $get) => (bool) $get('collected_now')),
                    TextInput::make('check_number')->label('Çek No')
                        ->visible(fn (Get $get) => $get('collected_now') && $get('payment_type') === 'check'),
                    TextInput::make('bank_name')->label('Banka')
                        ->visible(fn (Get $get) => $get('collected_now') && $get('payment_type') === 'check'),
                    DatePicker::make('due_date')->label('Vade')
                        ->visible(fn (Get $get) => $get('collected_now') && $get('payment_type') === 'check')
                        ->required(fn (Get $get) => $get('collected_now') && $get('payment_type') === 'check'),
                ]
            ))
            ->action(function (array $data): void {
                $sale = Sale::create([
                    'party_id'   => $this->record->id,
                    'project_id' => $data['project_id'] ?? null,
                    'sale_date'  => $data['sale_date'],
                    'notes'      => $data['notes'] ?? null,
                ]);

                $sale->rebuildFromLines($data['lines'] ?? []);

                // Peşin: satış toplamı kadar tahsilat (net bakiye 0). Çekse portföye ekle.
                if (! empty($data['collected_now']) && (float) $sale->total_amount > 0) {
                    $entry = $this->record->ledgerEntries()->create([
                        'entry_date'   => $data['sale_date'],
                        'amount'       => $sale->total_amount,
                        'project_id'   => $data['project_id'] ?? null,
                        'description'  => 'Peşin tahsilat — Satış #' . $sale->id,
                        'type'         => PartyLedgerEntry::TYPE_COLLECTION,
                        'payment_type' => $data['payment_type'] ?? 'cash',
                    ]);

                    if (($data['payment_type'] ?? null) === 'check') {
                        Check::create([
                            'party_id'              => $this->record->id,
                            'project_id'            => $data['project_id'] ?? null,
                            'party_ledger_entry_id' => $entry->id,
                            'check_number'          => $data['check_number'] ?? null,
                            'bank_name'             => $data['bank_name'] ?? null,
                            'due_date'              => $data['due_date'] ?? $data['sale_date'],
                            'amount'                => $sale->total_amount,
                            'status'                => 'portfolio',
                        ]);
                    }
                }
            });
    }

    /**
     * Tahsilat — ödeme yöntemi seçilir; "Çek" ise çek portföye eklenir (party_ledger_entry_id ile bağlı).
     * Model A: çek alınınca ekstre HEMEN düşer (tahsilat=alacak). Karşılıksız/tahsil Çekler ekranından yönetilir.
     */
    public function newCollectionAction(): Action
    {
        return Action::make('newCollection')
            ->modalHeading(fn (): string => 'Ödeme Aldım · ' . $this->record->name . ' borcu azalır')
            ->modalSubmitActionLabel('Kaydet')
            ->schema([
                DatePicker::make('entry_date')->label('Tarih')->default(now())->required(),
                MoneyInput::make('amount', 'Tutar'),
                Select::make('payment_type')
                    ->label('Ödeme Yöntemi')
                    ->options(array_filter([
                        'cash'          => 'Nakit',
                        'bank_transfer' => 'Havale',
                        'eft'           => 'EFT',
                        'check'         => config('modules.checks') ? 'Çek' : null,
                        'other'         => 'Diğer',
                    ]))
                    ->default('cash')
                    ->required()
                    ->live(),
                // Çek alanları — yalnız "Çek" seçilince.
                TextInput::make('check_number')->label('Çek No')
                    ->visible(fn (Get $get) => $get('payment_type') === 'check'),
                TextInput::make('bank_name')->label('Banka')
                    ->visible(fn (Get $get) => $get('payment_type') === 'check'),
                DatePicker::make('due_date')->label('Vade')
                    ->visible(fn (Get $get) => $get('payment_type') === 'check')
                    ->required(fn (Get $get) => $get('payment_type') === 'check'),
                Select::make('project_id')
                    ->label('Proje (opsiyonel)')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->helperText('Etiket/çıktı içindir — proje maliyet raporuna girmez.')
                    ->visible(fn () => config('modules.projects')),
                TextInput::make('description')->label('Açıklama')->maxLength(255)->columnSpanFull(),
                Textarea::make('notes')->label('Not')->rows(2)->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                $entry = $this->record->ledgerEntries()->create([
                    'entry_date'   => $data['entry_date'],
                    'amount'       => $data['amount'],
                    'project_id'   => $data['project_id'] ?? null,
                    'description'  => $data['description'] ?? null,
                    'notes'        => $data['notes'] ?? null,
                    'type'         => PartyLedgerEntry::TYPE_COLLECTION,
                    'payment_type' => $data['payment_type'] ?? null,
                ]);

                if (($data['payment_type'] ?? null) === 'check') {
                    Check::create([
                        'party_id'              => $this->record->id,
                        'project_id'            => $data['project_id'] ?? null,
                        'party_ledger_entry_id' => $entry->id,
                        'check_number'          => $data['check_number'] ?? null,
                        'bank_name'             => $data['bank_name'] ?? null,
                        'due_date'              => $data['due_date'] ?? $data['entry_date'],
                        'amount'                => $data['amount'],
                        'status'                => 'portfolio',
                    ]);
                }
            });
    }

    /**
     * İade Al (ÜST buton) — müşteri-merkezli. Tüm satışlarının iade edilebilir kalemleri tek liste;
     * her satır kendi satışına + fiyatına bağlı (LIFO/tahmin yok). Kaydederken satışa göre gruplanıp
     * her satış için processReturn çağrılır → stok geri + satis_iade (alacak) → ekstrede belirir.
     */
    public function returnEntryAction(): Action
    {
        return Action::make('returnEntry')
            ->modalHeading('İade Al')
            ->modalSubmitActionLabel('İadeyi Kaydet')
            ->fillForm(fn (): array => [
                'return_date' => now()->toDateString(),
                'lines'       => $this->record->returnableLines(),
            ])
            ->schema(SaleReturnForm::partyComponents())
            ->action(function (array $data): void {
                // Adet > 0 satırları satışa göre grupla → her satışa kendi iadesi.
                $bySale = [];
                foreach ($data['lines'] ?? [] as $line) {
                    if ((float) ($line['return_qty'] ?? 0) <= 0) {
                        continue;
                    }
                    $saleId = (int) ($line['sale_id'] ?? 0);
                    if ($saleId) {
                        $bySale[$saleId][] = $line;
                    }
                }

                foreach ($bySale as $saleId => $lines) {
                    Sale::find($saleId)?->processReturn($lines, $data['return_date'], $data['return_notes'] ?? null);
                }
            });
    }

    /**
     * İadeyi Geri Al — ekstredeki iade (satis_iade) satırından. SaleReturn silinir;
     * cascadeOnDelete ile stok girişi + satis_iade alacağı geri alınır (stok düşer, borç geri gelir).
     */
    public function cancelReturnAction(): Action
    {
        return Action::make('cancelReturn')
            ->requiresConfirmation()
            ->modalHeading('İadeyi Geri Al')
            ->modalDescription('Bu iadenin stok girişi ve cari alacağı geri alınacak (stok tekrar düşer, cari borç geri gelir). Emin misiniz?')
            ->modalSubmitActionLabel('Evet, geri al')
            ->action(function (array $arguments): void {
                SaleReturn::find($arguments['sale_return'])?->delete();
            });
    }

    /**
     * Satışı Düzenle — ekstre içinden (kalem/adet/fiyat). SaleForm dolu gelir, rebuildFromLines yeniden kurar.
     * İadesi olan satış düzenlenemez (stok/bakiye bozulmasın) — önce iade "Satışı Aç"tan geri alınmalı.
     */
    public function editSaleAction(): Action
    {
        return Action::make('editSale')
            ->modalHeading('Satışı Düzenle')
            ->modalSubmitActionLabel('Kaydet')
            ->fillForm(function (array $arguments): array {
                $sale = Sale::findOrFail($arguments['sale']);

                return [
                    'project_id' => $sale->project_id,
                    'sale_date'  => $sale->sale_date,
                    'notes'      => $sale->notes,
                    'lines'      => $sale->lines()->orderBy('id')->get()->map(fn ($m) => [
                        'product_id' => $m->product_id,
                        'quantity'   => number_format((float) $m->quantity, 2, '.', ''),
                        'unit_price' => $m->unit_price !== null ? Money::format((float) $m->unit_price) : null,
                        'amount'     => $m->amount !== null ? Money::format((float) $m->amount) : null,
                    ])->toArray(),
                ];
            })
            ->schema(SaleForm::components(withParty: false, dehydrateLines: true))
            ->action(function (array $data, array $arguments): void {
                $sale = Sale::findOrFail($arguments['sale']);

                // Güvenlik ağı: iadesi olan satış düzenlenemez (buton zaten gizli ama sunucu tarafı da korur).
                if ($sale->saleReturns()->exists()) {
                    Notification::make()
                        ->danger()
                        ->title('Bu satışın iadesi var')
                        ->body('Önce iadeyi geri alın (Satışı Aç → geçmiş iadeler), sonra düzenleyin.')
                        ->send();

                    return;
                }

                $sale->update([
                    'project_id' => $data['project_id'] ?? null,
                    'sale_date'  => $data['sale_date'],
                    'notes'      => $data['notes'] ?? null,
                ]);

                $sale->rebuildFromLines($data['lines'] ?? []);
            });
    }

    /** Ekstre satırına tıklayınca: düzenle (Sil butonu pencere içinde). */
    public function editLedgerEntryAction(): Action
    {
        return Action::make('editLedgerEntry')
            ->modalHeading('Hareketi Düzenle')
            ->modalSubmitActionLabel('Kaydet')
            ->fillForm(function (array $arguments): array {
                $entry = PartyLedgerEntry::findOrFail($arguments['entry']);

                return [
                    'entry_date'  => $entry->entry_date,
                    'amount'      => $entry->amount,
                    'project_id'  => $entry->project_id,
                    'description' => $entry->description,
                    'notes'       => $entry->notes,
                    'due_date'    => $entry->due_date,
                    'type'        => $entry->type,
                ];
            })
            ->schema($this->ledgerFormSchema())
            ->action(function (array $data, array $arguments): void {
                $entry = PartyLedgerEntry::findOrFail($arguments['entry']);
                // Satışa/iadeye bağlı satırlar buradan değiştirilemez.
                abort_if($entry->sale_id !== null || $entry->sale_return_id !== null, 403);

                if ($arguments['delete'] ?? false) {
                    $entry->delete();

                    return;
                }

                $entry->update($data);
            })
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('deleteLedgerEntry', arguments: ['delete' => true])
                    ->label('Sil')
                    ->color('danger'),
            ]);
    }

    public ?string $statementDateFrom = null;

    public ?string $statementDateTo = null;

    public ?string $statementProjectId = null;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Ekstre sabit: bu yılın 01.01'inden bugüne (bitiş açık). Önceki yıllar Açılış/Devir olur.
        $this->statementDateFrom = now()->startOfYear()->toDateString();
    }

    // Başlıkta "… düzenle" yerine sadece cari adı.
    public function getTitle(): string
    {
        return $this->record->name;
    }

    // Breadcrumb: "Cariler › {ad}" — sondaki "Düzenle" kaldırıldı.
    public function getBreadcrumbs(): array
    {
        return [
            PartyResource::getUrl('index') => 'Cariler',
            $this->record->name,
        ];
    }

    /**
     * Filtreli ekstre yazdırma URL'i (footer'daki butona verilir).
     */
    public function statementPrintUrl(): string
    {
        return route('party.statement.print', array_merge(
            ['party' => $this->record],
            $this->statementFilters(),
        ));
    }

    /**
     * Ekstre filtreleri (boş olanlar elenir) — hem getFooter hem yazdır URL'i kullanır.
     */
    private function statementFilters(): array
    {
        return array_filter([
            'date_from'  => $this->statementDateFrom,
            'date_to'    => $this->statementDateTo,
            'project_id' => $this->statementProjectId,
        ], fn ($v) => filled($v));
    }

    /** "Standart" — filtreyi varsayılana döndür: bu yılın 01.01'i → açık, proje yok. */
    public function clearStatementFilters(): void
    {
        $this->statementDateFrom = now()->startOfYear()->toDateString();
        $this->statementDateTo = null;
        $this->statementProjectId = null;
    }

    public function getFooter(): ?\Illuminate\Contracts\View\View
    {
        if (! $this->record) {
            return null;
        }

        return view('filament.resources.parties.edit-footer', [
            'party'            => $this->record,
            'statement'        => PartyStatement::build($this->record, $this->statementFilters()),
            'statementProjects' => $this->getStatementProjects(),
            'statementDateFrom' => $this->statementDateFrom,
            'statementDateTo'   => $this->statementDateTo,
            'statementProjectId' => $this->statementProjectId,
            'timeline'         => $this->getTimelineEvents(),
            'contracts'        => $this->getContractRows(),
            'invoices'         => $this->getInvoiceRows(),
            'expenses'         => $this->getExpenseRows(),
            'checks'           => $this->getCheckRows(),
        ]);
    }

    /**
     * Bu carinin ekstresinde geçen projeler (sözleşme + gider + elle hareket) → [id => ad].
     */
    private function getStatementProjects(): array
    {
        if (! config('modules.projects')) {
            return []; // projesiz kurulum: ekstrede proje filtresi yok
        }

        $party = $this->record;

        $ids = collect()
            ->merge(Contract::withoutGlobalScope('purchase')->where('party_id', $party->id)->pluck('project_id'))
            ->merge(Expense::where('party_id', $party->id)->pluck('project_id'))
            ->merge(PartyLedgerEntry::where('party_id', $party->id)->pluck('project_id'))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Project::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function getTimelineEvents(): array
    {
        $party = $this->record;
        $events = [];

        foreach (Contract::query()->where('party_id', $party->id)->with(['project', 'items'])->get() as $contract) {
            $date = $contract->contract_date ?? $contract->created_at;
            $events[] = [
                'sort'     => $date,
                'date'     => optional($date)->format('d.m.Y') ?? '-',
                'type'     => 'contract',
                'label'    => 'Sözleşme',
                'color'    => 'gray',
                'title'    => $contract->title,
                'subtitle' => $contract->project?->name,
                'amount'   => $contract->reportableTotal(),
            ];
        }

        foreach (ContractDelivery::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->with(['contract', 'project'])
            ->get() as $delivery) {
            $isSub = $delivery->contract?->isSubcontract();
            $events[] = [
                'sort'     => $delivery->delivery_date,
                'date'     => optional($delivery->delivery_date)->format('d.m.Y') ?? '-',
                'type'     => $isSub ? 'progress' : 'delivery',
                'label'    => $isSub ? 'Hakediş' : 'Teslimat',
                'color'    => 'info',
                'title'    => $delivery->contract?->title,
                'subtitle' => $delivery->project?->name,
                'amount'   => (float) $delivery->amount,
            ];
        }

        foreach (Invoice::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->with('contract')
            ->get() as $invoice) {
            $events[] = [
                'sort'     => $invoice->invoice_date,
                'date'     => optional($invoice->invoice_date)->format('d.m.Y') ?? '-',
                'type'     => 'invoice',
                'label'    => 'Fatura',
                'color'    => 'warning',
                'title'    => $invoice->invoice_number ? 'No: ' . $invoice->invoice_number : 'Fatura',
                'subtitle' => $invoice->contract?->title,
                'amount'   => (float) $invoice->total_amount,
            ];
        }

        foreach (ContractPayment::query()
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->with('contract')
            ->get() as $payment) {
            $date = $payment->payment_date ?? $payment->created_at;
            $events[] = [
                'sort'     => $date,
                'date'     => optional($date)->format('d.m.Y') ?? '-',
                'type'     => 'payment',
                'label'    => 'Ödeme',
                'color'    => 'success',
                'title'    => $payment->description ?: 'Sözleşme ödemesi',
                'subtitle' => $payment->contract?->title,
                'amount'   => (float) $payment->amount,
            ];
        }

        foreach (Check::query()->where('party_id', $party->id)->get() as $check) {
            $date = $check->issue_date ?? $check->due_date;
            $events[] = [
                'sort'     => $date,
                'date'     => optional($date)->format('d.m.Y') ?? '-',
                'type'     => 'check',
                'label'    => 'Çek',
                'color'    => 'amber',
                'title'    => $check->check_number ? 'Çek No: ' . $check->check_number : 'Çek',
                'subtitle' => 'Vade: ' . (optional($check->due_date)->format('d.m.Y') ?? '-'),
                'amount'   => (float) $check->amount,
            ];
        }

        foreach (Expense::query()->where('party_id', $party->id)->with(['project', 'category'])->get() as $expense) {
            $events[] = [
                'sort'     => $expense->expense_date,
                'date'     => optional($expense->expense_date)->format('d.m.Y') ?? '-',
                'type'     => 'expense',
                'label'    => 'Direkt Gider',
                'color'    => 'rose',
                'title'    => $expense->description ?: ($expense->category?->name ?? 'Gider'),
                'subtitle' => $expense->project?->name,
                'amount'   => (float) $expense->amount,
            ];
        }

        usort($events, function ($a, $b) {
            $ax = $a['sort']?->timestamp ?? 0;
            $bx = $b['sort']?->timestamp ?? 0;

            return $bx <=> $ax;
        });

        return array_map(function ($event) {
            unset($event['sort']);

            return $event;
        }, $events);
    }

    private function getContractRows(): array
    {
        $party = $this->record;

        return Contract::query()
            ->with(['project', 'payments', 'invoices', 'items', 'deliveries.project', 'deliveries.unit', 'deliveries.contractItem.unit'])
            ->where('party_id', $party->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Contract $contract) {
                $paid     = (float) $contract->payments->sum('amount');
                $invoiced = (float) $contract->invoices->sum('total_amount');
                $total    = $contract->reportableTotal();

                if ($contract->invoices->count() === 0) {
                    $invoiceStatus = 'none';
                } elseif ($invoiced >= $total - 0.01) {
                    $invoiceStatus = 'complete';
                } else {
                    $invoiceStatus = 'partial';
                }

                $deliveryByProject = [];
                foreach ($contract->deliveries as $delivery) {
                    $projectId   = $delivery->project_id ?? 0;
                    $projectName = $delivery->project?->name ?? 'Projesiz';

                    if (! isset($deliveryByProject[$projectId])) {
                        $deliveryByProject[$projectId] = [
                            'project'  => $projectName,
                            'quantity' => 0,
                            'amount'   => 0,
                            'unit'     => $delivery->unit?->code ?? $delivery->contractItem?->unit?->code,
                        ];
                    }

                    $deliveryByProject[$projectId]['quantity'] += (float) $delivery->quantity;
                    $deliveryByProject[$projectId]['amount']   += (float) $delivery->amount;
                }

                return [
                    'title'               => $contract->title,
                    'project'             => $contract->project?->name,
                    'status'              => $contract->status,
                    'total'               => $total,
                    'paid'                => $paid,
                    'remaining'           => max(0, $total - $paid),
                    'invoiced'            => $invoiced,
                    'uninvoiced'          => max(0, $total - $invoiced),
                    'invoice_status'      => $invoiceStatus,
                    'delivery_by_project' => array_values($deliveryByProject),
                    'has_deliveries'      => count($deliveryByProject) > 0,
                ];
            })
            ->all();
    }

    private function getInvoiceRows(): array
    {
        $party = $this->record;

        return Invoice::query()
            ->with('contract')
            ->whereHas('contract', fn ($q) => $q->where('party_id', $party->id))
            ->orderBy('invoice_date', 'desc')
            ->get()
            ->map(fn (Invoice $invoice) => [
                'date'           => optional($invoice->invoice_date)->format('d.m.Y'),
                'invoice_number' => $invoice->invoice_number,
                'contract_title' => $invoice->contract?->title ?? '—',
                'amount'         => (float) $invoice->total_amount,
                'has_pdf'        => filled($invoice->invoice_path),
                'pdf_url'        => filled($invoice->invoice_path)
                    ? route('invoice.file', ['filename' => basename($invoice->invoice_path)])
                    : null,
            ])
            ->all();
    }

    private function getExpenseRows(): array
    {
        $party = $this->record;

        $expenses = Expense::query()
            ->with(['project', 'category'])
            ->where('party_id', $party->id)
            ->orderBy('expense_date')
            ->get();

        $grouped = [];
        foreach ($expenses as $expense) {
            $key  = $expense->project_id ?? 0;
            $name = $expense->project?->name ?? (config('modules.projects') ? 'Projesiz' : 'Giderler');

            if (! isset($grouped[$key])) {
                $grouped[$key] = ['project' => $name, 'total' => 0, 'items' => []];
            }

            $grouped[$key]['total']   += (float) $expense->amount;
            $grouped[$key]['items'][]  = [
                'date'        => optional($expense->expense_date)->format('d.m.Y'),
                'category'    => $expense->category?->name,
                'description' => $expense->description,
                'amount'      => (float) $expense->amount,
            ];
        }

        return array_values($grouped);
    }

    private function getCheckRows(): array
    {
        $party = $this->record;

        return Check::query()
            ->with('project')
            ->where('party_id', $party->id)
            ->orderBy('due_date')
            ->get()
            ->map(fn (Check $check) => [
                'project'  => $check->project?->name ?? 'Projesiz',
                'due_date' => optional($check->due_date)->format('d.m.Y'),
                'amount'   => (float) $check->amount,
                'status'   => $check->status,
            ])
            ->all();
    }
}
