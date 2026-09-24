<?php

namespace App\Filament\Resources\PropertyTaxBlocks\Pages;

use App\Filament\Resources\PropertyTaxBlocks\PropertyTaxBlockResource;
use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use App\Models\PropertyTaxUnit;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPropertyTaxBlock extends EditRecord
{
    protected static string $resource = PropertyTaxBlockResource::class;

    public function getTitle(): string
    {
        return $this->record->name.' — Daireler';
    }

    /**
     * Blok kaynağının index sayfası yok (navigasyonda gizli). Breadcrumb ve
     * yönlendirmeleri parent projeye bağla — aksi halde Filament index route arar.
     */
    public function getBreadcrumbs(): array
    {
        $project = $this->record->project;

        return [
            PropertyTaxProjectResource::getUrl('index') => 'Emlak Beyanı Projeleri',
            PropertyTaxProjectResource::getUrl('edit', ['record' => $project]) => $project->name,
            $this->record->name.' — Daireler',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return PropertyTaxProjectResource::getUrl('edit', ['record' => $this->record->property_tax_project_id]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bulkCreateUnits')
                ->label('Toplu Daire Oluştur')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->modalHeading('Toplu Daire Oluştur')
                ->modalDescription('Kat ve daire sayısını gir; daireler otomatik oluşturulur (numaralandırma + kat/sıra). Sonra farklı olanları düzeltirsin.')
                ->modalSubmitActionLabel('Oluştur')
                ->schema([
                    TextInput::make('floors')
                        ->label('Kat Sayısı')
                        ->numeric()->minValue(1)->required()->default(4),
                    TextInput::make('per_floor')
                        ->label('Katta Kaç Daire')
                        ->numeric()->minValue(1)->required()->default(2),
                    TextInput::make('start_no')
                        ->label('Başlangıç Daire No')
                        ->numeric()->minValue(1)->required()->default(1),
                    TextInput::make('area')
                        ->label('Standart Yüzölçümü (m²)')
                        ->numeric()
                        ->helperText('Boş bırakılabilir; sonra daire bazında girilir.'),
                ])
                ->action(function (array $data) {
                    $this->bulkCreateUnits($data);
                }),

            \Filament\Actions\DeleteAction::make(),
        ];
    }

    private function bulkCreateUnits(array $data): void
    {
        $floors = (int) $data['floors'];
        $perFloor = (int) $data['per_floor'];
        $no = (int) $data['start_no'];
        $area = $data['area'] !== null && $data['area'] !== '' ? (float) $data['area'] : null;
        $sort = (int) ($this->record->units()->max('sort_order') ?? 0);

        $created = 0;
        for ($floor = 1; $floor <= $floors; $floor++) {
            for ($pos = 1; $pos <= $perFloor; $pos++) {
                $this->record->units()->create([
                    'unit_no'        => (string) $no,
                    'floor_no'       => $floor,
                    'floor_position' => $pos,
                    'area'           => $area,
                    'sort_order'     => ++$sort,
                    // arsa payı pay/payda boş → bloğun varsayılanını devralır
                ]);
                $no++;
                $created++;
            }
        }

        Notification::make()
            ->title($created.' daire oluşturuldu')
            ->success()
            ->send();
    }
}
