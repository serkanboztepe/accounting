<?php

namespace App\Filament\Hub\HubFirms\RelationManagers;

use App\Models\FirmUser;
use App\Models\User;
use App\Tenancy\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tek panel: firmanın panel kullanıcıları. Kullanıcı firmanın KENDİ veritabanında durur;
 * buradaki liste merkezdeki e-posta → firma eşlemesidir (User kaydedilince kendiliğinden güncellenir).
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Kullanıcılar';

    protected static ?string $modelLabel = 'Kullanıcı';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Tenancy::enabled() && $ownerRecord->isLocal();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')->label('E-posta (giriş)')->searchable(),
                TextColumn::make('created_at')->label('Eklendi')->date('d.m.Y')->color('gray'),
            ])
            ->emptyStateHeading('Henüz kullanıcı yok')
            ->headerActions([
                Action::make('addUser')
                    ->label('Kullanıcı Ekle')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        TextInput::make('name')->label('Ad Soyad')->required(),
                        TextInput::make('email')->label('E-posta')->email()->required(),
                        TextInput::make('password')->label('Geçici şifre')->password()->revealable()->required()->minLength(8),
                    ])
                    ->action(function (array $data) {
                        $exists = Tenancy::run($this->getOwnerRecord(), fn () => User::whereRaw('lower(email) = ?', [mb_strtolower(trim($data['email']))])->exists());
                        if ($exists) {
                            Notification::make()->danger()->title('Bu e-posta bu firmada zaten var')->send();

                            return;
                        }
                        Tenancy::run($this->getOwnerRecord(), fn () => User::create($data));
                        Notification::make()->success()->title('Kullanıcı eklendi')->send();
                    }),
            ])
            ->recordActions([
                Action::make('resetPassword')
                    ->label('Şifre')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->modalHeading(fn (FirmUser $record) => "Yeni şifre: {$record->email}")
                    ->schema([
                        TextInput::make('password')->label('Yeni şifre')->password()->revealable()->required()->minLength(8),
                    ])
                    ->action(function (FirmUser $record, array $data) {
                        $updated = Tenancy::run($this->getOwnerRecord(), fn () => User::whereRaw('lower(email) = ?', [$record->email])->first()?->update(['password' => $data['password']]));
                        Notification::make()->{$updated ? 'success' : 'danger'}()
                            ->title($updated ? 'Şifre değişti' : 'Kullanıcı firma veritabanında bulunamadı')->send();
                    }),
                Action::make('removeUser')
                    ->label('Sil')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Kullanıcı firmanın veritabanından silinir, panele giremez. Firmanın kayıtları silinmez.')
                    ->action(function (FirmUser $record) {
                        Tenancy::run($this->getOwnerRecord(), fn () => User::whereRaw('lower(email) = ?', [$record->email])->get()->each->delete());
                        $record->delete();
                    }),
            ]);
    }
}
