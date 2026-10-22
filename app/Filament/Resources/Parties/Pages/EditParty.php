<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Services\Whatsapp\EmployeeDraft;
use App\Support\Labels;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Agreement;
use App\Models\AgreementItem;
use App\Models\Check;
use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PartyLedgerEntry;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Filament\Resources\Sales\Schemas\SaleForm;
use App\Filament\Resources\Sales\Schemas\SaleReturnForm;
use App\Support\AgreementDesk;
use App\Support\Forms\AgreementForm;
use App\Support\Forms\TradeLines;
use App\Support\Money;
use App\Support\PartyStatement;
use App\Support\TradeDesk;
use Illuminate\Validation\ValidationException;
use Filament\Notifications\Notification;
use App\Support\Forms\MoneyInput;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use App\Models\Party;
use App\Support\PartyDeletion;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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

    /** Kartta maaş günü / personel değişince maaş günü hatırlatması güncellenir ya da kapanır (tek doğru yer kart). */
    protected function afterSave(): void
    {
        EmployeeDraft::syncFromCard($this->record);
    }

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
                // Tek tıkla carinin tüm hareketleri gitmesin (kullanıcı 21.10): "Eminim Sil" yazılmadan silinmez.
                ->schema(fn (Party $record) => PartyDeletion::blockers($record) ? [] : [PartyDeletion::confirmField()])
                ->modalSubmitActionLabel('Cariyi sil')
                // Veritabanı reddederse 500 değil, nedeni söylenir (PartyDeletion::delete).
                ->using(fn (Party $record): bool => PartyDeletion::delete($record))
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

            // "Anlaşmadan" sekmesinde kalem işaretlendiyse anlaşma dışı tutar boş bırakılabilir.
            MoneyInput::make('amount', 'Tutar')->required(fn (Get $get) => ! AgreementForm::anyTaken($get)),

            Select::make('project_id')
                ->label(Labels::project() . ' (opsiyonel)')
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

    /** Ödeme Yaptım: ödeme yöntemi; "Çek" seçilince birden çok çek (no, banka, vade, tutar) — Faz 2.3. */
    private function paymentMethodSchema(): array
    {
        return [
            Select::make('payment_type')->label('Ödeme yöntemi')
                ->options(['cash' => 'Nakit', 'eft' => 'EFT', 'bank_transfer' => 'Havale', 'check' => 'Çek', 'other' => 'Diğer'])
                ->default('cash')->live(),
            Repeater::make('cheques')->label('Çekler')
                ->schema([
                    TextInput::make('check_number')->label('Çek no'),
                    TextInput::make('bank_name')->label('Banka'),
                    DatePicker::make('due_date')->label('Vade')->required(),
                    MoneyInput::make('amount', 'Tutar'),
                ])
                ->columns(4)
                ->addActionLabel('Çek ekle')
                ->defaultItems(1)
                ->helperText('Çeklerin toplamı ödeme tutarına eşit olmalı. Her çek vadesinden önce WhatsApp\'tan hatırlatılır.')
                ->visible(fn (Get $get) => $get('payment_type') === 'check')
                ->columnSpanFull(),
        ];
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
            ->schema(fn (array $arguments): array => $this->withAgreementTabs(self::AGREEMENT_TYPES[$arguments['type'] ?? ''] ?? null, [
                ...$this->ledgerFormSchema($arguments['type'] ?? null),
                ...(($arguments['type'] ?? null) === PartyLedgerEntry::TYPE_PAYMENT ? $this->paymentMethodSchema() : []),
            ], keep: ['entry_date']))
            ->action(function (array $data, array $arguments, Action $action): void {
                // "Anlaşmadan" sekmesinde işaretlenenler (Alım/Satış Yaptım) önce; anlaşma dışı tutar boşsa başka kayıt yok.
                [$agreementLines, $agreementProject, $priceToRest, $data] = AgreementForm::extract($data);
                $delivered = $this->deliverFromWindow($agreementLines, $agreementProject, $priceToRest,
                    self::AGREEMENT_TYPES[$arguments['type'] ?? ''] ?? '', $data['entry_date'], $action);
                if ($delivered && Money::parse($data['amount'] ?? null) <= 0) {
                    return;
                }
                $cheques = array_values(array_filter((array) ($data['cheques'] ?? []), fn ($c) => ! empty($c['due_date'])));
                unset($data['cheques']);
                // Çekle ödeme (Faz 2.3): çeklerin toplamı ödeme tutarını tutmalı — tutmazsa kaydetmeden uyar.
                if (($data['payment_type'] ?? null) === 'check' && $cheques !== []
                    && abs(array_sum(array_map(fn ($c) => Money::parse($c['amount'] ?? 0), $cheques)) - (float) $data['amount']) > 0.01) {
                    Notification::make()->danger()->title('Çeklerin toplamı ödeme tutarını tutmuyor')->send();
                    $action->halt();
                }
                $entry = $this->record->ledgerEntries()->create([
                    ...$data,
                    'type' => $arguments['type'] ?? null,
                ]);
                foreach ($cheques as $c) {
                    Check::create([
                        'party_id' => $this->record->id,
                        'project_id' => $data['project_id'] ?? null,
                        'party_ledger_entry_id' => $entry->id,
                        'check_number' => $c['check_number'] ?? null,
                        'bank_name' => $c['bank_name'] ?? null,
                        'issue_date' => $data['entry_date'],
                        'due_date' => $c['due_date'],
                        'amount' => Money::store($c['amount'] ?? 0),
                        'status' => 'issued',   // verdiğim çek — vade hatırlatmasına girer
                        'description' => $data['description'] ?? null,
                    ]);
                }
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
            ->schema(fn (): array => array_merge(
                $this->withAgreementTabs(Agreement::DIRECTION_SALE, SaleForm::components(withParty: false, dehydrateLines: true), keep: ['sale_date']),
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
            ->action(function (array $data, Action $action): void {
                [$agreementLines, $agreementProject, $priceToRest, $data] = AgreementForm::extract($data);
                $delivered = $this->deliverFromWindow($agreementLines, $agreementProject, $priceToRest, Agreement::DIRECTION_SALE,
                    $data['sale_date'], $action);
                // Peşin: anlaşmadan teslim edilenin tahsilatı da (satış penceresiyle aynı kural).
                if ($delivered && ! empty($data['collected_now'])) {
                    $this->collectNow($delivered['documents']->sum(fn ($d) => (float) $d->total_amount), $data, $data['sale_date'],
                        'Peşin tahsilat — anlaşma teslimatı');
                }
                if ($delivered && ! TradeLines::hasLines($data['lines'] ?? []) && Money::parse($data['manual_amount'] ?? null) <= 0) {
                    return; // yalnız anlaşma teslimatı
                }
                $sale = Sale::create([
                    'party_id'      => $this->record->id,
                    'project_id'    => $data['project_id'] ?? null,
                    'sale_date'     => $data['sale_date'],
                    // Ürünsüz tutar alanı yalnız Satışlar tezgahı açık firmada var; diğerlerinde boş.
                    'manual_amount' => $data['manual_amount'] ?? null,
                    'notes'         => $data['notes'] ?? null,
                ]);

                $sale->rebuildFromLines($data['lines'] ?? []);

                // Peşin: satış toplamı kadar tahsilat (net bakiye 0). Çekse portföye ekle.
                if (! empty($data['collected_now']) && (float) $sale->total_amount > 0) {
                    $this->collectNow((float) $sale->total_amount, $data, $data['sale_date'], 'Peşin tahsilat — Satış #' . $sale->id);
                }
            });
    }

    /** Peşin tahsilat satırı (+ çekse portföye) — Satış Yaptım'ın "Tahsil edildi" seçeneği. */
    private function collectNow(float $amount, array $data, string $date, string $description): void
    {
        if ($amount <= 0) {
            return;
        }
        $entry = $this->record->ledgerEntries()->create([
            'entry_date'   => $date,
            'amount'       => $amount,
            'project_id'   => $data['project_id'] ?? null,
            'description'  => $description,
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
                'due_date'              => $data['due_date'] ?? $date,
                'amount'                => $amount,
                'status'                => 'portfolio',
            ]);
        }
    }

    /**
     * Stoklu alış — Cari Ekstresi içinden. Alışlar ekranı (purchases) açıkken "Alım Yaptım" buna bağlanır (satışın aynası;
     * Akkuş 21.10: caride basit alış açılıyordu, un stoğa girmiyordu). Kayıt Alışlar tezgahıyla aynı yoldan (TradeDesk):
     * ürünler stoğa girer, ürünsüz tutar da yazılabilir (nakliye, usta); peşinse alış + ödeme birlikte, bakiye değişmez.
     */
    public function newPurchaseAction(): Action
    {
        return Action::make('newPurchase')
            ->modalHeading(fn (): string => 'Alım Yaptım · ' . $this->record->name)
            ->modalSubmitActionLabel('Kaydet')
            ->modalWidth('4xl')
            ->schema(fn (): array => $this->withAgreementTabs(Agreement::DIRECTION_PURCHASE, [
                DatePicker::make('purchase_date')->label('Tarih')->default(now())->required(),
                Toggle::make('paid')
                    ->label('Peşin (parası verildi)')
                    ->helperText('Açıksa alış + ödeme birlikte yazılır, bakiye değişmez; kapalıysa sen borçlanırsın.')
                    ->default(false)
                    ->live(),
                Select::make('payment_type')
                    ->label('Ödeme')
                    ->options(Purchase::PAYMENT_TYPES)
                    ->default('cash')
                    ->visible(fn (Get $get) => (bool) $get('paid')),
                TradeLines::repeater('in'),
                // Şube/proje yalnız ürünsüz alışta: "Ürün ekle" deyince gizlenir, satır silinince geri gelir
                // (ürünlü alış işletme geneli, Serkan 16.10).
                Select::make('project_id')
                    ->label(fn () => Labels::project())
                    ->options(fn () => Project::whereNotIn('status', ['cancelled'])->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Genel')
                    ->helperText('Ürünsüz alışın maliyeti bu şubeye yazılır.')
                    ->visible(fn (Get $get) => config('modules.projects') && empty($get('lines'))),
                MoneyInput::make('manual_amount', 'Ürünsüz tutar')
                    ->required(false)
                    ->helperText('Ürün seçmeden alış (ör. nakliye, usta, stok tutmadığın bir malzeme).'),
                TextInput::make('notes')->label('Açıklama')->maxLength(255),
            ], keep: ['purchase_date', 'paid', 'payment_type']))
            ->action(function (array $data, Action $action): void {
                [$agreementLines, $agreementProject, $priceToRest, $data] = AgreementForm::extract($data);
                $delivered = $this->deliverFromWindow($agreementLines, $agreementProject, $priceToRest, Agreement::DIRECTION_PURCHASE,
                    $data['purchase_date'], $action, ! empty($data['paid']), $data['payment_type'] ?? null);
                if ($delivered && ! TradeLines::hasLines($data['lines'] ?? []) && Money::parse($data['manual_amount'] ?? null) <= 0) {
                    return; // yalnız anlaşma teslimatı
                }
                try {
                    TradeDesk::savePurchase([
                        ...$data,
                        'mode'     => ! empty($data['paid']) ? 'cash' : 'party',
                        'party_id' => $this->record->id,
                    ]);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }
            });
    }

    // ── Anlaşmalar (Faz 6, 21.10) ─────────────────────────────────────────────────────────────────────────────
    // 🤝 Anlaşma yap → kart (Anlaşılan / Gelen / Kalan) → Teslim aldım / Teslim ettim. İş mantığı AgreementDesk'te.

    /** 🤝 Anlaşma yap — yön pencerede seçilir (bu cariyle son anlaşmanın yönü seçili gelir). */
    public function newAgreementAction(): Action
    {
        return Action::make('newAgreement')
            ->modalHeading(fn (): string => 'Yeni anlaşma · ' . $this->record->name)
            ->modalSubmitActionLabel('Kaydet')
            ->slideOver()
            ->modalWidth('3xl')
            ->fillForm(fn (): array => [
                'direction'            => AgreementForm::lastDirection($this->record->id),
                'agreement_date'       => now()->toDateString(),
                'advance_payment_type' => 'cash',
                'lines'                => [[]],
            ])
            ->schema(AgreementForm::createSchema())
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('saveAnother', arguments: ['another' => true])->label('Kaydet ve yeni'),
            ])
            ->action(function (array $data, array $arguments, Schema $schema, Action $action): void {
                try {
                    $agreement = AgreementDesk::create($this->record, $data);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Anlaşma kaydedildi: ' . $agreement->title)->send();
                if ($arguments['another'] ?? false) {
                    $schema->fill(['direction' => $data['direction'], 'agreement_date' => $data['agreement_date'],
                        'project_id' => $data['project_id'] ?? null, 'advance_payment_type' => 'cash', 'lines' => [[]]]);
                    $action->halt();
                }
            });
    }

    /** + Kalem ekle (anlaşma kartında). */
    public function addAgreementItemsAction(): Action
    {
        return Action::make('addAgreementItems')
            ->modalHeading(fn (array $arguments): string => 'Kalem ekle · ' . ($this->agreementArg($arguments)?->title ?? ''))
            ->modalSubmitActionLabel('Ekle')
            ->modalWidth('3xl')
            ->fillForm(fn (array $arguments): array => ['direction' => $this->agreementArg($arguments)?->direction, 'lines' => [[]]])
            ->schema([Hidden::make('direction'), ...AgreementForm::addItemsSchema()])
            ->action(function (array $data, array $arguments, Action $action): void {
                $agreement = $this->agreementArg($arguments);
                try {
                    AgreementDesk::addItems($agreement, $data['lines'] ?? []);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }
            });
    }

    /** Fiyatı değiştir (zam): geçmiş teslimat eski fiyatta kalır, kalan yeni fiyattan. */
    public function changeAgreementPriceAction(): Action
    {
        return Action::make('changeAgreementPrice')
            ->modalHeading(fn (array $arguments): string => 'Fiyatı değiştir · ' . ($this->agreementItemArg($arguments)?->name() ?? ''))
            ->modalDescription(fn (array $arguments): ?string => ($item = $this->agreementItemArg($arguments))
                ? 'Şu anki fiyat ' . Money::format((float) $item->unit_price) . ' ₺. Gelmiş olanlar eski fiyatta kalır; kalanlar yeni fiyattan.'
                : null)
            ->modalSubmitActionLabel('Değiştir')
            ->modalWidth('md')
            ->schema([MoneyInput::make('unit_price', 'Yeni fiyat')])
            ->action(function (array $data, array $arguments): void {
                if ($item = $this->agreementItemArg($arguments)) {
                    AgreementDesk::changePrice($item, Money::parse($data['unit_price']));
                }
            });
    }

    /** Teslimatı olmayan kalemi sil. */
    public function deleteAgreementItemAction(): Action
    {
        return Action::make('deleteAgreementItem')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Kalemi sil · ' . ($this->agreementItemArg($arguments)?->name() ?? ''))
            ->modalSubmitActionLabel('Sil')
            ->color('danger')
            ->action(function (array $arguments, Action $action): void {
                try {
                    AgreementDesk::deleteItem($this->agreementItemArg($arguments));
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }
            });
    }

    /** Anlaşmayı kapat (kalan borç doğurmaz) / yeniden aç. */
    public function toggleAgreementAction(): Action
    {
        return Action::make('toggleAgreement')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => ($this->agreementArg($arguments)?->status === Agreement::STATUS_CLOSED
                ? 'Anlaşmayı yeniden aç' : 'Anlaşmayı kapat') . ' · ' . ($this->agreementArg($arguments)?->title ?? ''))
            ->modalDescription(fn (array $arguments): string => $this->agreementArg($arguments)?->status === Agreement::STATUS_CLOSED
                ? 'Kalan kalemler yeniden teslim bekler.'
                : 'Kalan kalemler artık beklenmez. Borç yazılmaz; gelen teslimatlar olduğu gibi kalır.')
            ->modalSubmitActionLabel(fn (array $arguments): string => $this->agreementArg($arguments)?->status === Agreement::STATUS_CLOSED ? 'Aç' : 'Kapat')
            ->action(function (array $arguments): void {
                $agreement = $this->agreementArg($arguments);
                $agreement->status === Agreement::STATUS_CLOSED ? AgreementDesk::reopen($agreement) : AgreementDesk::close($agreement);
            });
    }

    /** Anlaşmayı sil (yalnız teslimatı yoksa). */
    public function deleteAgreementAction(): Action
    {
        return Action::make('deleteAgreement')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => 'Anlaşmayı sil · ' . ($this->agreementArg($arguments)?->title ?? ''))
            ->modalDescription('Teslimatı olmayan anlaşma silinir. Peşinat yazdıysan o cari ekstresinde kalır.')
            ->modalSubmitActionLabel('Sil')
            ->color('danger')
            ->action(function (array $arguments, Action $action): void {
                try {
                    AgreementDesk::delete($this->agreementArg($arguments));
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }
            });
    }

    /** Basit cari hareketi tipi → anlaşma yönü (yalnız Alım Yaptım / Satış Yaptım pencerelerinde bölüm var). */
    private const AGREEMENT_TYPES = [PartyLedgerEntry::TYPE_PURCHASE => Agreement::DIRECTION_PURCHASE, PartyLedgerEntry::TYPE_SALE => Agreement::DIRECTION_SALE];

    /** Alım/Satış Yaptım: açık anlaşma kalemi varsa "Anlaşmadan | Anlaşma dışı" sekmeleri; yoksa pencere aynen. */
    private function withAgreementTabs(?string $direction, array $components, array $keep = []): array
    {
        return $direction ? AgreementForm::withAgreementTabs($components, $direction, $this->record, $keep) : $components;
    }

    /**
     * Pencerede işaretlenen anlaşma kalemlerini kaydet (proje başına alış/satış belgesi). Hiç işaret yoksa null.
     * Hata → bildirim + pencere açık kalır; anlaşmadan fazla gelen → uyarı.
     */
    private function deliverFromWindow(array $lines, mixed $project, bool $priceToRest, string $direction, string $date, Action $action,
        bool $paid = false, ?string $paymentType = null): ?array
    {
        if ($lines === [] || $direction === '') {
            return null;
        }
        try {
            $result = AgreementDesk::deliver($this->record, $direction, $lines, $date, $project, $priceToRest, null, $paid, $paymentType);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
            $action->halt();
        }
        if ($result['over']) {
            Notification::make()->warning()->title('Anlaşmadan fazla')->body('⚠ ' . implode("\n", $result['over']))->persistent()->send();
        }

        return $result;
    }

    /** Argümandaki anlaşma — yalnız bu carinin (başka carinin anlaşmasına buradan dokunulmaz). */
    private function agreementArg(array $arguments): ?Agreement
    {
        return empty($arguments['agreement']) ? null
            : Agreement::where('party_id', $this->record->id)->find($arguments['agreement']);
    }

    private function agreementItemArg(array $arguments): ?AgreementItem
    {
        return empty($arguments['item']) ? null
            : AgreementItem::with('product.unit', 'agreement')->whereHas('agreement', fn ($q) => $q->where('party_id', $this->record->id))
                ->find($arguments['item']);
    }

    /** Cari sayfasındaki kartlar: açıklar üstte, bitenler/kapatılanlar ayrı. */
    private function getAgreementCards(): array
    {
        if (! config('modules.agreements')) {
            return ['open' => collect(), 'finished' => collect()];
        }
        $all = Agreement::where('party_id', $this->record->id)->orderByDesc('agreement_date')->orderByDesc('id')->get()
            ->each(fn (Agreement $a) => $a->loadForCard());
        return [
            'open'     => $all->where('status', Agreement::STATUS_OPEN)->values(),
            'finished' => $all->where('status', '!=', Agreement::STATUS_OPEN)->values(),
        ];
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
                    ->label(Labels::project() . ' (opsiyonel)')
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
     * Alış / satış belgesini sil — Alışlar / Satışlar ekranı kapalı firmada (müteahhit, 22.10: anlaşmadan gelen tuğla "Aç" → 403).
     * Stok satırları ve cari kaydı belgeyle silinir (cascade); anlaşmadan düşülen miktar geri döner, anlaşma durumu yenilenir.
     */
    public function deleteDocumentAction(): Action
    {
        return Action::make('deleteDocument')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => ! empty($arguments['purchase']) ? 'Alışı sil' : 'Satışı sil')
            ->modalDescription('Belge, kalemleri ve cari kaydı silinir. Anlaşmadan geldiyse düşülen miktar anlaşmaya geri döner.')
            ->modalSubmitActionLabel('Sil')
            ->color('danger')
            ->action(function (array $arguments): void {
                $doc = ! empty($arguments['purchase']) ? Purchase::find($arguments['purchase']) : Sale::find($arguments['sale'] ?? null);
                $this->deleteDocument($doc);
            });
    }

    /**
     * Alışı düzenle — ekstrede alış satırına tıklayınca (kullanıcı 22.10: "düzenleme ekranı açılsın, oradan düzenleyip veya silelim").
     * Proje, tarih, peşin, kalemler; "Sil" pencere içinde. Anlaşma bağı rebuildFromLines'ta ürün üzerinden korunur.
     */
    public function editPurchaseAction(): Action
    {
        return Action::make('editPurchase')
            ->modalHeading(fn (array $arguments): string => 'Alış #' . ($arguments['purchase'] ?? ''))
            ->modalSubmitActionLabel('Kaydet')
            ->slideOver()
            ->fillForm(function (array $arguments): array {
                $purchase = Purchase::findOrFail($arguments['purchase']);

                return [
                    'project_id'    => $purchase->project_id,
                    'purchase_date' => $purchase->purchase_date,
                    'paid'          => (bool) $purchase->paid,
                    'payment_type'  => $purchase->payment_type,
                    'notes'         => $purchase->notes,
                    'lines'         => $purchase->lines()->orderBy('id')->get()->map(fn ($m) => [
                        'product_id' => $m->product_id,
                        'quantity'   => number_format((float) $m->quantity, 2, '.', ''),
                        'unit_price' => $m->unit_price !== null ? Money::format((float) $m->unit_price) : null,
                        'amount'     => $m->amount !== null ? Money::format((float) $m->amount) : null,
                    ])->toArray(),
                ];
            })
            ->schema([
                AgreementForm::projectSelect('project_id'),
                DatePicker::make('purchase_date')->label('Tarih')->required(),
                Toggle::make('paid')->label('Peşin (parası verildi)')->live(),
                Select::make('payment_type')->label('Ödeme')->options(Purchase::PAYMENT_TYPES)->visible(fn (Get $get) => (bool) $get('paid')),
                TradeLines::repeater('in'),
                Textarea::make('notes')->label('Açıklama')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $purchase = Purchase::findOrFail($arguments['purchase']);
                abort_if((int) $purchase->party_id !== (int) $this->record->id, 403);
                if ($arguments['delete'] ?? false) {
                    $this->deleteDocument($purchase);

                    return;
                }
                $purchase->update([
                    'project_id'    => $data['project_id'] ?? null,
                    'purchase_date' => $data['purchase_date'],
                    'paid'          => (bool) ($data['paid'] ?? false),
                    'payment_type'  => ($data['paid'] ?? false) ? ($data['payment_type'] ?? null) : null,
                    'notes'         => $data['notes'] ?? null,
                ]);
                $purchase->rebuildFromLines($data['lines'] ?? []);
                $this->refreshAgreementsOf($purchase);
                Notification::make()->success()->title('Alış güncellendi')->send();
            })
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('deletePurchase', arguments: ['delete' => true])
                    ->label('Sil')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Alış, kalemleri ve cari kaydı silinir. Anlaşmadan geldiyse düşülen miktar anlaşmaya geri döner.'),
            ]);
    }

    /** Belgeyi sil (bu carinin) + bağlı anlaşmaların durumunu yenile. */
    private function deleteDocument(Purchase|Sale|null $doc): void
    {
        if (! $doc || (int) $doc->party_id !== (int) $this->record->id) {
            return;
        }
        $agreements = $this->agreementIdsOf($doc);
        \Illuminate\Support\Facades\DB::transaction(fn () => $doc->delete());
        Agreement::whereIn('id', $agreements)->get()->each(fn (Agreement $a) => AgreementDesk::refreshStatus($a));
        Notification::make()->success()->title($doc instanceof Purchase ? 'Alış silindi' : 'Satış silindi')->send();
    }

    private function agreementIdsOf(Purchase|Sale $doc): \Illuminate\Support\Collection
    {
        return AgreementItem::whereIn('id', $doc->lines()->whereNotNull('agreement_item_id')->pluck('agreement_item_id'))
            ->pluck('agreement_id')->unique();
    }

    private function refreshAgreementsOf(Purchase|Sale $doc): void
    {
        Agreement::whereIn('id', $this->agreementIdsOf($doc))->get()->each(fn (Agreement $a) => AgreementDesk::refreshStatus($a));
    }

    /**
     * Anlaşmayı düzenle: ad, proje, not (kullanıcı 22.10: "proje seçmemişim"). "Gelen malları da taşı" → bu anlaşmadan gelen
     * alış/satışların projesi de değişir (belge + stok satırları + cari kaydı) — proje maliyeti doğru projeye geçer.
     */
    public function editAgreementAction(): Action
    {
        return Action::make('editAgreement')
            ->modalHeading(fn (array $arguments): string => 'Anlaşmayı düzenle · ' . ($this->agreementArg($arguments)?->title ?? ''))
            ->modalSubmitActionLabel('Kaydet')
            ->fillForm(function (array $arguments): array {
                $a = $this->agreementArg($arguments);

                return ['title' => $a->title, 'project_id' => $a->project_id, 'notes' => $a->notes, 'move_deliveries' => true];
            })
            ->schema([
                TextInput::make('title')->label('Anlaşma adı')->required()->maxLength(255),
                AgreementForm::projectSelect('project_id'),
                Toggle::make('move_deliveries')
                    ->label('Gelen malları da bu projeye taşı')
                    ->helperText('Bu anlaşmadan önceden gelen alış/satışların projesi de değişir.')
                    ->visible(fn () => (bool) config('modules.projects')),
                Textarea::make('notes')->label('Not')->rows(2),
            ])
            ->action(function (array $data, array $arguments): void {
                $a = $this->agreementArg($arguments);
                $projectId = $data['project_id'] ?? null;
                \Illuminate\Support\Facades\DB::transaction(function () use ($a, $data, $projectId) {
                    $a->update(['title' => $data['title'], 'project_id' => $projectId, 'notes' => $data['notes'] ?? null]);
                    if (! ($data['move_deliveries'] ?? false) || ! config('modules.projects')) {
                        return;
                    }
                    $lines = \App\Models\StockMovement::whereIn('agreement_item_id', $a->items()->pluck('id'));
                    foreach ([Purchase::class => 'purchase_id', Sale::class => 'sale_id'] as $model => $fk) {
                        foreach ($model::whereIn('id', (clone $lines)->whereNotNull($fk)->pluck($fk)->unique())->get() as $doc) {
                            $doc->update(['project_id' => $projectId]);
                            $doc->lines()->update(['project_id' => $projectId]);
                            PartyLedgerEntry::where($fk, $doc->id)->update(['project_id' => $projectId]);
                        }
                    }
                });
                Notification::make()->success()->title('Anlaşma güncellendi')->send();
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
                    'project_id'    => $sale->project_id,
                    'sale_date'     => $sale->sale_date,
                    'manual_amount' => $sale->manual_amount,
                    'notes'         => $sale->notes,
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
                    ...(array_key_exists('manual_amount', $data) ? ['manual_amount' => $data['manual_amount']] : []),
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
            'activityLogs'     => $this->getActivityLogs(),
            'contracts'        => $this->getContractRows(),
            'invoices'         => $this->getInvoiceRows(),
            'expenses'         => $this->getExpenseRows(),
            'checks'           => $this->getCheckRows(),
            'agreements'       => $this->getAgreementCards(),
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

    /**
     * İşlem geçmişi (kullanıcı 21.10: "log gibi çalışsın, silinse de güncellense de görünsün"): bu carinin defter satırları,
     * yeniden eskiye. Eski "Aktivite Zaman Çizgisi" o an var olan kayıtlardan çiziliyordu — silinen kayıt kayboluyordu.
     */
    private function getActivityLogs(): \Illuminate\Support\Collection
    {
        return \App\Models\ActivityLog::where('party_id', $this->record->id)
            ->orderByDesc('happened_at')->orderByDesc('id')->limit(500)->get();
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
