<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Pages\CariUnlock;
use App\Filament\Resources\Parties\PartyResource;
use App\Support\CariLock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use App\Filament\Resources\Parties\Schemas\PartyForm;
use App\Models\Party;
use App\Services\Whatsapp\EmployeeDraft;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Icons\Heroicon;

class ListParties extends ListRecords
{
    protected static string $resource = PartyResource::class;

    /**
     * Tipe göre süzme (27.10): "İkisi de" ve tipi boş olan hem satışta hem alımda görünür. Adlar paranın yönüne göre (kullanıcı 28.10:
     * "tedarikçi / müşteri kafa karıştırıcı", "alım mal çağrıştırıyor" — usta, taşeron, ev sahibi de "ödeme yaptıklarım"); yanında sayı.
     */
    public function getTabs(): array
    {
        // 06.11 kullanıcı: adlar "Satış / Alım yaptıklarım", Hepsi EN SONDA (açılışta yine seçili — getDefaultActiveTab).
        // Kişisel / Ticari ayrımı açık firmada (hub, Yıldız): Ticari (seçili) · Kişisel · Personel · Hepsi.
        $side = fn (string $kind) => fn (Builder $query) => $query->where('is_employee', false)
            ->where(fn ($q) => $q->whereNull('kind')->orWhereIn('kind', [$kind, Party::KIND_BOTH, Party::KIND_PERSONAL]));
        $employees = ['Personel', fn (Builder $query) => $query->where('is_employee', true)];
        $all = ['Hepsi', fn (Builder $query) => $query];
        $tabs = \App\Support\Features::personalPartyGroups() ? [
            'commercial' => ['Ticari', fn (Builder $query) => $query->where('is_employee', false)
                ->where(fn ($q) => $q->whereNull('kind')->orWhere('kind', '!=', Party::KIND_PERSONAL))],
            'personal' => ['Kişisel', fn (Builder $query) => $query->where('kind', Party::KIND_PERSONAL)],
            'employees' => $employees,
            'all' => $all,
        ] : [
            'customers' => ['Satış yaptıklarım', $side(Party::KIND_CUSTOMER)],
            'suppliers' => ['Alım yaptıklarım', $side(Party::KIND_SUPPLIER)],
            'employees' => $employees,
            'all' => $all,
        ];

        return collect($tabs)->map(fn (array $t) => Tab::make($t[0])->modifyQueryUsing($t[1])
            ->badge(fn () => $t[1](Party::query())->count()))->all();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return \App\Support\Features::personalPartyGroups() ? 'commercial' : 'all';
    }

    /** Sekme çubuğu yerine yuvarlak düğmeler (kullanıcı 27.10: "tabları sevmedim, anlaşma detaydaki gibi"); süzme getTabs'tan. */
    public function getTabsContentComponent(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\View::make('filament.resources.parties.kind-pills')
            ->viewData(fn (): array => [
                'tabs' => collect($this->getCachedTabs())->map(fn (Tab $tab) => ['label' => $tab->getLabel(), 'count' => $tab->getBadge()])->all(),
                'active' => (string) ($this->activeTab ?? 'all'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('lockCari')
                ->label('Kilitle')
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->visible(fn () => CariLock::enabled())
                ->action(function () {
                    CariLock::lock();
                    $this->redirect(CariUnlock::getUrl());
                }),
            // Yeni cari pencerede (27.10: ayrı sayfa yerine). Personel + maaş günü → hatırlatma; kaydedince carinin sayfası açılır.
            CreateAction::make()
                ->modalHeading('Yeni kişi / firma')
                ->modalWidth('2xl')
                ->schema(PartyForm::components())
                ->using(function (array $data): Party {
                    $choice = $data['first_salary'] ?? null; // kolon değil — ilk ay seçimi karta işlenir
                    unset($data['first_salary']);
                    $party = Party::create($data);
                    EmployeeDraft::applyFirstChoice($party, $choice);

                    return $party;
                })
                ->after(fn (Party $record) => EmployeeDraft::syncFromCard($record))
                ->successRedirectUrl(fn (Party $record): string => PartyResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
