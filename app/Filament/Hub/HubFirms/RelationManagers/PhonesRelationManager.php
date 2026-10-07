<?php

namespace App\Filament\Hub\HubFirms\RelationManagers;

use App\Models\HubPhone;
use App\Support\Phone;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Firmanın WhatsApp numaraları — bu numaralardan gelen mesajlar bu firmaya gider. */
class PhonesRelationManager extends RelationManager
{
    protected static string $relationship = 'phones';

    protected static ?string $title = 'Numaralar';

    protected static ?string $modelLabel = 'Numara';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('phone')
                ->label('WhatsApp numarası')
                ->placeholder('0532 123 45 67')
                ->helperText('0532…, +90 532…, 532… — hepsi olur. Yurt dışı numarayı ülke koduyla yaz.')
                ->required()
                ->rule(fn (?HubPhone $record) => function (string $attribute, $value, Closure $fail) use ($record) {
                    $normalized = Phone::normalize((string) $value);
                    if (strlen($normalized) < 10) {
                        $fail('Geçerli bir numara gir.');

                        return;
                    }
                    $other = HubPhone::with('firm')->where('phone', $normalized)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->first();
                    if ($other) {
                        $fail("Bu numara zaten kayıtlı: {$other->firm?->name}. Bir numara tek firmaya bağlanır.");
                    }
                }),
            TextInput::make('name')->label('Ad Soyad')->placeholder('Ferhat Yıldız')->maxLength(255),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('phone')
            ->columns([
                TextColumn::make('phone')->label('Numara')->searchable()
                    ->formatStateUsing(fn (string $state) => Phone::display($state)),
                TextColumn::make('name')->label('Ad Soyad')->searchable(),
                ToggleColumn::make('is_active')->label('Aktif'),
            ])
            ->emptyStateHeading('Henüz numara yok')
            ->emptyStateDescription('Bu firmadan asistana yazacak kişilerin WhatsApp numaralarını ekle.')
            ->headerActions([
                CreateAction::make()->label('Numara Ekle'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
