<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\LandShareStudies\LandShareStudyResource;
use App\Models\LandShareStudy;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LandShareStudiesRelationManager extends RelationManager
{
    protected static string $relationship = 'landShareStudies';

    protected static ?string $title = 'Kat Karşılığı Çalışmaları';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Çalışma Adı')
                ->required()
                ->placeholder('298 Ada / 8 Parsel — v1')
                ->columnSpanFull(),
            TextInput::make('ada')->label('Ada'),
            TextInput::make('parsel')->label('Parsel'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Çalışma')->searchable(),
                TextColumn::make('shareholders_count')->label('Hissedar')->counts('shareholders')->badge(),
                TextColumn::make('status')
                    ->label('Durum')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ['draft' => 'Taslak', 'final' => 'Kesinleşti'][$state] ?? $state)
                    ->color(fn (string $state) => $state === 'final' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Oluşturma')->date('d.m.Y'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Yeni Çalışma')
                    ->modalHeading('Yeni Kat Karşılığı Çalışması')
                    // Kaydettikten sonra veri girişi için sihirbaza (build) git.
                    ->after(fn (LandShareStudy $record) => redirect(
                        LandShareStudyResource::getUrl('build', ['record' => $record]),
                    )),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Veri Girişi')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (LandShareStudy $record) => LandShareStudyResource::getUrl('build', ['record' => $record])),
                Action::make('ledger')
                    ->label('Cetvel')
                    ->icon(Heroicon::OutlinedTableCells)
                    ->color('primary')
                    ->url(fn (LandShareStudy $record) => LandShareStudyResource::getUrl('ledger', ['record' => $record])),
                DeleteAction::make(),
            ]);
    }
}
