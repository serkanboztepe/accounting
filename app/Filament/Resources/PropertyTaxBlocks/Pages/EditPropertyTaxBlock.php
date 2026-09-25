<?php

namespace App\Filament\Resources\PropertyTaxBlocks\Pages;

use App\Filament\Resources\PropertyTaxBlocks\PropertyTaxBlockResource;
use App\Filament\Resources\PropertyTaxBlocks\Schemas\PropertyTaxBlockForm;
use App\Filament\Resources\PropertyTaxProjects\PropertyTaxProjectResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class EditPropertyTaxBlock extends EditRecord
{
    protected static string $resource = PropertyTaxBlockResource::class;

    public function getTitle(): string
    {
        return $this->record->name.' — Daireler';
    }

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
            // Blok ayarları sağ üstteki ⚙ Ayarlar modalından; gövde daire yönetimine kaldı.
            Action::make('settings')
                ->label('Ayarlar')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->modalHeading('Blok Ayarları')
                ->modalSubmitActionLabel('Kaydet')
                ->fillForm(fn (): array => $this->record->attributesToArray())
                ->schema(PropertyTaxBlockForm::components())
                ->action(fn (array $data) => $this->record->update($data)),

            DeleteAction::make(),
        ];
    }

    /**
     * Gövdeden inline blok formu + alttaki Kaydet bar'ı kaldır — düzenleme ⚙ Ayarlar modalından.
     * Gövde: daireler (asıl çalışılan yer).
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getRelationManagersContentComponent(),
        ]);
    }
}
