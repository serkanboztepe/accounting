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

    /** Tipe göre süzme (27.10): "İkisi de" ve tipi boş olan hem müşteride hem tedarikçide görünür. */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Hepsi'),
            'customers' => Tab::make('Müşteriler')->modifyQueryUsing(fn (Builder $query) => $query->where('is_employee', false)
                ->where(fn ($q) => $q->whereNull('kind')->orWhereIn('kind', [Party::KIND_CUSTOMER, Party::KIND_BOTH]))),
            'suppliers' => Tab::make('Tedarikçiler')->modifyQueryUsing(fn (Builder $query) => $query->where('is_employee', false)
                ->where(fn ($q) => $q->whereNull('kind')->orWhereIn('kind', [Party::KIND_SUPPLIER, Party::KIND_BOTH]))),
            'employees' => Tab::make('Personel')->modifyQueryUsing(fn (Builder $query) => $query->where('is_employee', true)),
        ];
    }

    /** Sekme çubuğu yerine yuvarlak düğmeler (kullanıcı 27.10: "tabları sevmedim, anlaşma detaydaki gibi"); süzme getTabs'tan. */
    public function getTabsContentComponent(): \Filament\Schemas\Components\Component
    {
        return \Filament\Schemas\Components\View::make('filament.resources.parties.kind-pills')
            ->viewData(fn (): array => [
                'tabs' => collect($this->getCachedTabs())->map(fn (Tab $tab) => $tab->getLabel())->all(),
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
                ->after(fn (Party $record) => EmployeeDraft::syncFromCard($record))
                ->successRedirectUrl(fn (Party $record): string => PartyResource::getUrl('edit', ['record' => $record])),
        ];
    }
}
