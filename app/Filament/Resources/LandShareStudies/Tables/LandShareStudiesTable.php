<?php

namespace App\Filament\Resources\LandShareStudies\Tables;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use App\Models\LandShareStudy;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LandShareStudiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Çalışma')->searchable()->sortable(),
                TextColumn::make('project.name')->label('Proje')->searchable()->sortable(),
                TextColumn::make('ada')->label('Ada')->toggleable(),
                TextColumn::make('parsel')->label('Parsel')->toggleable(),
                TextColumn::make('shareholders_count')->label('Hissedar')->counts('shareholders')->badge(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ['draft' => 'Taslak', 'final' => 'Kesinleşti'][$state] ?? $state)
                    ->color(fn (string $state) => $state === 'final' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Oluşturma')->date('d.m.Y')->sortable()->toggleable(),
            ])
            ->recordActions([
                Action::make('ledger')
                    ->label('Cetvel')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->color('primary')
                    ->url(fn (LandShareStudy $record) => LandShareStudyResource::getUrl('ledger', ['record' => $record])),
                Action::make('build')
                    ->label('Veri Girişi')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (LandShareStudy $record) => LandShareStudyResource::getUrl('build', ['record' => $record])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
