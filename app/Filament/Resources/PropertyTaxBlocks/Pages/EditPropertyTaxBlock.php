<?php

namespace App\Filament\Resources\PropertyTaxBlocks\Pages;

use App\Filament\Resources\PropertyTaxBlocks\PropertyTaxBlockResource;
use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

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
            DeleteAction::make(),
        ];
    }
}
