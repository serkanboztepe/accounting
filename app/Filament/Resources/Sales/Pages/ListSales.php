<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Support\Labels;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Party;
use App\Models\Sale;
use App\Support\Forms\MoneyInput;
use App\Support\Forms\TradeLines;
use App\Support\Money;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use App\Support\TradeDesk;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;

/**
 * Satışlar. "Satışlar ekranı" modülü (sales_desk) açıksa SATIŞ TEZGAHI: üstte hep açık giriş paneli
 * ([Peşin] [Cariye Satış], ürünler ya da ürünsüz tutar), altta bugünün satışları. Kapalıysa eski liste
 * aynen (Yeni Yapı gibi mevcut firmalar etkilenmez).
 */
class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    /** Tezgah formunun durumu. */
    public ?array $deskData = [];

    /** Az önce kaydedilen satış — listede vurgulanır. */
    public ?int $lastSavedId = null;

    public static function deskEnabled(): bool
    {
        return (bool) \App\Support\Features::salesDesk();
    }

    public function mount(): void
    {
        parent::mount();

        if (self::deskEnabled()) {
            $this->deskForm->fill(self::deskDefaults());
        }
    }

    public function getTitle(): string
    {
        return self::deskEnabled() ? 'Satışlar' : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        return self::deskEnabled() ? [] : [CreateAction::make()->label('Yeni Satış')];
    }

    private static function deskDefaults(array $keep = []): array
    {
        return [
            'mode'          => $keep['mode'] ?? 'cash',
            'payment'       => $keep['payment'] ?? 'cash',
            'sale_date'     => $keep['sale_date'] ?? now()->toDateString(),
            'party_id'      => null,
            'project_id'    => $keep['project_id'] ?? null,
            'lines'         => [],
            'manual_amount' => null,
            'cash_part'     => null,
            'notes'         => null,
        ];
    }

    public function deskForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('deskData')
            ->columns(['default' => 1, 'md' => 3])
            ->components([
                ToggleButtons::make('mode')
                    ->hiddenLabel()
                    ->options(['cash' => 'Peşin', 'party' => 'Cariye Satış'])
                    ->icons(['cash' => 'heroicon-o-banknotes', 'party' => 'heroicon-o-user'])
                    ->inline()
                    ->live()
                    ->columnSpan(['default' => 1, 'md' => 2]),

                DatePicker::make('sale_date')
                    ->label('Tarih')
                    ->required(),

                // Proje / Şube (firmada açıksa): peşin kasada birden çok şube varsa zorunlu (WhatsApp'taki kuralın aynısı).
                Select::make('project_id')
                    ->label(fn () => Labels::project())
                    ->options(fn () => \App\Models\Project::whereNotIn('status', ['cancelled'])->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Genel')
                    ->visible(fn () => (bool) \App\Support\Features::places())
                    ->required(fn (Get $get) => $get('mode') === 'cash' && \App\Models\Project::whereNotIn('status', ['cancelled'])->count() > 1),

                Select::make('party_id')
                    ->label('Cari')
                    ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->visible(fn (Get $get) => $get('mode') === 'party')
                    ->helperText(function (Get $get) {
                        $party = $get('party_id') ? Party::find($get('party_id')) : null;

                        return $party ? PartyBalances::line($party->name, PartyStatement::build($party)['balance']) : null;
                    })
                    ->columnSpan(['default' => 1, 'md' => 2]),

                ToggleButtons::make('payment')
                    ->label('Ödeme')
                    ->options(['cash' => 'Nakit', 'card' => 'Kart', 'mixed' => 'Nakit + Kart'])
                    ->inline()
                    ->live()
                    ->visible(fn (Get $get) => $get('mode') === 'cash')
                    ->columnSpan(['default' => 1, 'md' => 2]),

                MoneyInput::make('cash_part', 'Nakit kısmı')
                    ->required(false)
                    ->helperText('Kalanı kart sayılır.')
                    ->visible(fn (Get $get) => $get('mode') === 'cash' && $get('payment') === 'mixed'),

                TradeLines::repeater('out'),

                MoneyInput::make('manual_amount', 'Ürünsüz tutar')
                    ->required(false)
                    ->live(debounce: 400)
                    ->helperText('Ürün seçmeden satış ya da günün toplu cirosu (ör. akşam kasası).'),

                TextInput::make('notes')
                    ->label('Açıklama')
                    ->maxLength(255)
                    ->columnSpan(['default' => 1, 'md' => 2]),

                Text::make(fn (Get $get) => 'Toplam: ' . Money::format(TradeLines::total($get('lines'), $get('manual_amount'))) . ' ₺')
                    ->weight('bold')
                    ->size('lg')
                    ->columnSpan(['default' => 1, 'md' => 2]),

                Actions::make([
                    Action::make('saveDesk')
                        ->label('Kaydet')
                        ->icon('heroicon-o-check')
                        ->keyBindings(['mod+s'])
                        ->action(fn () => $this->saveDesk()),
                ])->alignEnd(),
            ]);
    }

    public function saveDesk(): void
    {
        $data = $this->deskForm->getState();

        try {
            $sale = TradeDesk::saveSale($data);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('deskData.' . $field, $messages[0]);
            }
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

            return;
        }

        $this->lastSavedId = $sale->id;
        // Form temizlenir; tarih ve Peşin/Cariye + ödeme seçimi korunur (art arda giriş).
        $this->deskForm->fill(self::deskDefaults($data));
        $this->resetTable();

        Notification::make()
            ->success()
            ->title('Kaydedildi: ' . Money::format((float) $sale->total_amount) . ' ₺ · ' . ($sale->paymentLabel() ?? $sale->customerLabel()))
            ->actions([
                Action::make('undo')
                    ->label('Geri al')
                    ->button()
                    ->color('gray')
                    ->dispatch('undo-desk-sale', ['id' => $sale->id])
                    ->close(),
            ])
            ->send();
    }

    #[On('undo-desk-sale')]
    public function undoDeskSale(int $id): void
    {
        // Silme cascade: stok çıkışları ve (cariye satışta) cari borç satırı birlikte gider.
        $sale = Sale::find($id);
        if (! $sale || $sale->saleReturns()->exists()) {
            return;
        }

        $sale->delete();
        $this->lastSavedId = null;
        $this->resetTable();

        Notification::make()->title('Satış geri alındı')->send();
    }

    public function getTabs(): array
    {
        if (! self::deskEnabled()) {
            return [];
        }

        return [
            'all'   => Tab::make('Tümü'),
            'cash'  => Tab::make('Peşin')->modifyQueryUsing(fn (Builder $query) => $query->whereNull('party_id')),
            'party' => Tab::make('Cariye')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('party_id')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        if (! self::deskEnabled()) {
            return parent::content($schema);
        }

        return $schema->components([
            Section::make('Yeni satış')
                ->schema([EmbeddedSchema::make('deskForm')])
                ->compact(),
            View::make('filament.trade.desk-summary')
                ->viewData(fn () => [
                    'today' => TradeDesk::salesSummary(now()->toDateString(), now()->toDateString()),
                    'month' => TradeDesk::salesSummary(now()->startOfMonth()->toDateString(), now()->toDateString()),
                    'kind'  => 'sale',
                ]),
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }
}
