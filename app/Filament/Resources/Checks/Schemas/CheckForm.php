<?php

namespace App\Filament\Resources\Checks\Schemas;

use App\Models\Check;
use App\Models\ContractPayment;
use App\Support\Forms\MoneyInput;
use App\Support\Labels;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Çek formu (Faz 2.2, 17.10 sadeleştirme): "Durum" yön + durum olarak ikiye ayrıldı (verildi/ödendi, alındı/tahsil edildi) — eskiden 6 durum karışıktı ("Portföyde"
 * aslında aldığım çek, "Verildi" verdiğim çek). Veritabanındaki status değerleri AYNEN (issued/paid/portfolio/collected/
 * bounced/cancelled); yalnız ekran değişti. Sözleşme alanı yalnız sözleşme modülünde, proje alanı yalnız proje modülünde.
 */
class CheckForm
{
    /** Yön → durum seçenekleri (veritabanı değeri → ekranda görünen). */
    // 17.10 kullanıcı: yalnız iki durum. Karşılıksız / iptal YOK → "Çeki sil" (bağlı ödeme/tahsilatla birlikte, CheckRemoval).
    public const STATES = [
        'given' => ['issued' => 'Verildi', 'paid' => 'Ödendi'],
        'received' => ['portfolio' => 'Alındı', 'collected' => 'Tahsil edildi'],
    ];

    public static function directionOf(?string $status): string
    {
        return in_array($status, ['portfolio', 'collected'], true) ? 'received' : 'given';
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Çek')
                ->schema([
                    Select::make('direction')
                        ->label('Yön')
                        ->options(['given' => 'Verdiğim çek (benim borcum)', 'received' => 'Aldığım çek (müşteriden)'])
                        ->default('given')
                        ->live()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (Select $component, ?Check $record) => $component->state(self::directionOf($record?->status)))
                        ->afterStateUpdated(fn (Set $set, ?string $state) => $set('status', $state === 'received' ? 'portfolio' : 'issued')),
                    Select::make('status')
                        ->label('Durum')
                        ->options(fn (Get $get) => self::STATES[$get('direction') ?: 'given'])
                        ->default('issued')
                        ->required(),
                    Select::make('party_id')
                        ->label('Kişi / Firma')
                        ->relationship('party', 'name')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->helperText('Boş bırakılırsa çek yalnız çek olarak kaydedilir, cariye işlenmez.'),
                    // Çek cariye işlenir (23.10 tasarım hatası düzeltmesi; kullanıcı 26.10: seçenek YOK): cari seçiliyse yeni çekte ve
                    // düzenlemede her zaman — verilen = ödeme, alınan = tahsilat satırı, çek bağlı (CheckLedger::apply). Carisiz = hatırlatma.
                    Placeholder::make('ledger_info')
                        ->hiddenLabel()
                        ->content(fn (Get $get, ?Check $record) => match (true) {
                            filled($get('contract_payment_id')) => 'Sözleşme ödemesine bağlı — cariye ayrıca yazılmaz, sözleşme ödemesi tutar.',
                            blank($get('party_id')) => 'Carisiz çek — cariye işlenmez, yalnız vade hatırlatması olarak durur.',
                            (bool) $record?->party_ledger_entry_id => 'ℹ️ Cariye işli — değişiklik ekstreye de yansır; cari kaldırılır ya da çek silinirse cariden de silinir.',
                            ($get('direction') ?: 'given') === 'received' => 'ℹ️ Kaydedince cariye TAHSİLAT olarak işlenir — bakiyesi düşer.',
                            default => 'ℹ️ Kaydedince cariye ÖDEME olarak işlenir — ona olan borcun düşer.',
                        })
                        ->columnSpanFull(),
                    DatePicker::make('due_date')
                        ->label('Vade')
                        ->required(),
                    MoneyInput::make('amount')->live(onBlur: true),
                    // Carinin anlaşması varsa: bu çek hangi anlaşma için (26.10) — cari satırına yazılır (CheckLedger::applyAgreement).
                    \App\Support\Forms\AgreementPaymentChoice::field(
                        fn (Get $get) => blank($get('party_id')) || filled($get('contract_payment_id')) ? null : \App\Models\Party::find($get('party_id')),
                        fn (Get $get) => in_array($get('status'), ['portfolio', 'collected'], true)
                            ? \App\Models\PartyLedgerEntry::TYPE_COLLECTION : \App\Models\PartyLedgerEntry::TYPE_PAYMENT,
                        'amount',
                        fn (mixed $record) => $record instanceof Check ? $record->ledgerEntry : null,
                    )
                        ->dehydrated(false)
                        ->afterStateHydrated(fn (\Filament\Forms\Components\Radio $component, ?Check $record) => $component->state(
                            $record?->ledgerEntry ? ($record->ledgerEntry->agreement_id ?? \App\Support\Forms\AgreementPaymentChoice::OTHER) : null)),
                    TextInput::make('check_number')
                        ->label('Çek No')
                        ->maxLength(255),
                    TextInput::make('bank_name')
                        ->label('Banka')
                        ->maxLength(255),
                    DatePicker::make('issue_date')
                        ->label('Düzenleme Tarihi'),
                    Select::make('project_id')
                        ->label(fn () => Labels::project())
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload()
                        ->visible(fn () => (bool) \App\Support\Features::places()),
                    // Sözleşme ödemesi: yalnız sözleşme modülünde; okunur ad ("Beton · Kuşak · 20.11.2026 · 1.050.000 ₺").
                    Select::make('contract_payment_id')
                        ->label('Sözleşme ödemesi')
                        ->relationship('contractPayment', 'id')
                        ->getOptionLabelFromRecordUsing(fn (ContractPayment $p) => self::paymentLabel($p))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn () => (bool) \App\Support\Features::contracts())
                        ->afterStateUpdated(function ($state, Set $set) {
                            if (! $state || ! ($payment = ContractPayment::with('contract')->find($state))) {
                                return;
                            }
                            $set('project_id', $payment->contract?->project_id);
                            $set('party_id', $payment->contract?->party_id);
                            $set('amount', Money::format((float) $payment->amount));
                            $set('due_date', $payment->due_date ?? $payment->payment_date);
                        }),
                    TextInput::make('description')
                        ->label('Ne için')
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Notlar')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(), // sayfa iki sütunlu: tek blok sol yarıda kalıyordu (kullanıcı 26.10)
        ]);
    }

    public static function paymentLabel(ContractPayment $p): string
    {
        $contract = $p->contract()->withoutGlobalScopes()->with('party')->first();

        return implode(' · ', array_filter([$contract?->title, $contract?->party?->name,
            ($p->due_date ?? $p->payment_date)?->format('d.m.Y'), Money::format((float) $p->amount) . ' ₺']));
    }
}
