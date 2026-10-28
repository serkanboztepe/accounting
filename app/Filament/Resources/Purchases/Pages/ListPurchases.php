<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Support\Labels;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Party;
use App\Models\Purchase;
use App\Support\Forms\MoneyInput;
use App\Support\Forms\TradeLines;
use App\Support\Money;
use App\Support\PartyBalances;
use App\Support\PartyStatement;
use App\Support\TradeDesk;
use Filament\Actions\Action;
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
 * ALIŞ TEZGAHI — satış tezgahının aynası: üstte giriş paneli ([Cariden] [Peşin], ürünler ya da ürünsüz tutar),
 * altta bugünün alışları. Cariden: stok girişi + cariye borç. Peşin: stok girişi + (cari seçildiyse) alış + ödeme.
 */
class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    public ?array $deskData = [];

    public ?int $lastSavedId = null;

    public function mount(): void
    {
        parent::mount();
        $this->deskForm->fill(self::deskDefaults());
    }

    public function getTitle(): string
    {
        return 'Alışlar';
    }

    private static function deskDefaults(array $keep = []): array
    {
        return [
            'mode'          => $keep['mode'] ?? 'party',
            'payment_type'  => $keep['payment_type'] ?? 'cash',
            'purchase_date' => $keep['purchase_date'] ?? now()->toDateString(),
            'party_id'      => null,
            'project_id'    => $keep['project_id'] ?? null,
            'lines'         => [],
            'manual_amount' => null,
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
                    ->options(['party' => 'Veresiye', 'cash' => 'Peşin'])
                    ->icons(['party' => 'heroicon-o-user', 'cash' => 'heroicon-o-banknotes'])
                    ->inline()
                    ->live()
                    ->columnSpan(['default' => 1, 'md' => 2]),

                DatePicker::make('purchase_date')->label('Tarih')->required(),

                Select::make('party_id')
                    ->label(fn (Get $get) => $get('mode') === 'cash' ? 'Kimden (isteğe bağlı)' : 'Kimden')
                    ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->helperText(function (Get $get) {
                        $party = $get('party_id') ? Party::find($get('party_id')) : null;
                        if (! $party) {
                            return $get('mode') === 'cash' ? 'Seçersen alış ve ödeme hesap dökümünde birlikte görünür.' : null;
                        }

                        return PartyBalances::line($party->name, PartyStatement::build($party)['balance']);
                    })
                    ->columnSpan(['default' => 1, 'md' => 2]),

                ToggleButtons::make('payment_type')
                    ->label('Ödeme')
                    ->options(Purchase::PAYMENT_TYPES)
                    ->inline()
                    ->visible(fn (Get $get) => $get('mode') === 'cash'),

                TradeLines::repeater('in'),

                // Proje / Şube (firmada açıksa) yalnız ürünsüz alışta: "Ürün ekle" deyince gizlenir, satır silinince geri gelir
                // (ürünlü alış işletme geneli, Serkan 16.10). "Alışı yere yaz" açık firmada (çiftlik) ürünlü alışta da görünür.
                Select::make('project_id')
                    ->label(fn () => Labels::project())
                    ->options(fn () => \App\Models\Project::whereNotIn('status', ['cancelled'])->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('Genel')
                    ->helperText(fn () => \App\Support\Features::placeCost() ? 'Alışın maliyeti bu yere yazılır (ürünler stoğa da girer).' : 'Ürünsüz alışın maliyeti bu şubeye yazılır.')
                    ->visible(fn (Get $get) => \App\Support\Features::places() && (\App\Support\Features::placeCost() || empty($get('lines'))))
                    ->columnSpan(['default' => 1, 'md' => 2]),

                MoneyInput::make('manual_amount', 'Ürünsüz tutar')
                    ->required(false)
                    ->live(debounce: 400)
                    ->helperText('Ürün seçmeden alış (ör. stok tutmadığın bir malzeme).'),

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
            $purchase = TradeDesk::savePurchase($data);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('deskData.' . $field, $messages[0]);
            }
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

            return;
        }

        $this->lastSavedId = $purchase->id;
        $this->deskForm->fill(self::deskDefaults($data));
        $this->resetTable();

        Notification::make()
            ->success()
            ->title('Kaydedildi: ' . Money::format((float) $purchase->total_amount) . ' ₺ · ' . ($purchase->paid ? 'Peşin' : $purchase->supplierLabel()))
            ->actions([
                Action::make('undo')
                    ->label('Geri al')
                    ->button()
                    ->color('gray')
                    ->dispatch('undo-desk-purchase', ['id' => $purchase->id])
                    ->close(),
            ])
            ->send();
    }

    #[On('undo-desk-purchase')]
    public function undoDeskPurchase(int $id): void
    {
        Purchase::find($id)?->delete();
        $this->lastSavedId = null;
        $this->resetTable();

        Notification::make()->title('Alış geri alındı')->send();
    }

    public function getTabs(): array
    {
        return [
            'all'   => Tab::make('Tümü'),
            'party' => Tab::make('Veresiye')->modifyQueryUsing(fn (Builder $query) => $query->where('paid', false)),
            'cash'  => Tab::make('Peşin')->modifyQueryUsing(fn (Builder $query) => $query->where('paid', true)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Yeni alış')
                ->schema([EmbeddedSchema::make('deskForm')])
                ->compact(),
            View::make('filament.trade.desk-summary')
                ->viewData(fn () => [
                    'today' => TradeDesk::purchaseSummary(now()->toDateString(), now()->toDateString()),
                    'month' => TradeDesk::purchaseSummary(now()->startOfMonth()->toDateString(), now()->toDateString()),
                    'kind'  => 'purchase',
                ]),
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }
}
