<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Party;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Sade proje formu. Durum ve tarihler sorulmaz:
 *  - Başlangıç = oluşturma günü (Project::creating), Durum = aktif
 *  - Bitiş = listede "Tamamlandı" aksiyonunun günü
 */
class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Ad')->required()->maxLength(255),
            // Müteahhit kendi projelerini takip eder — müşteri sahipliği sadece satış yapan
            // kurulumlarda anlamlı (cari satışta müşterinin projelerini getirmek için).
            Select::make('party_id')
                ->label('Sahip müşteri')
                ->options(fn () => Party::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->preload()
                ->visible(fn () => config('app.profile') !== 'muteahhit')
                ->helperText('Opsiyonel — proje bir müşteriye aitse seç. Kendi projelerinde boş bırak.'),
            TextInput::make('cadastral_parcel')
                ->label('Ada / Parsel')
                ->placeholder('880/294')
                ->maxLength(255),
            Textarea::make('address')
                ->label('Adres')
                ->placeholder('Mahalle, cadde/sokak, no, ilçe/il')
                ->rows(2)
                ->columnSpanFull(),
            Textarea::make('notes')->label('Notlar')->columnSpanFull(),
        ])->columns(2);
    }
}
