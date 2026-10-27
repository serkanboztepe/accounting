<?php

namespace App\Filament\Resources\Parties\Pages;

use App\Filament\Pages\CariUnlock;
use App\Filament\Resources\Parties\PartyResource;
use App\Support\CariLock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use App\Models\Party;
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
            CreateAction::make(),
        ];
    }
}
