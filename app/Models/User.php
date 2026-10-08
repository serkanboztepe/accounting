<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Tenancy\Tenancy;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Filament paneline kimler girebilir. Bu tek-şirket iç uygulaması;
     * hesabı olan her kullanıcı (yalnız güvenilir kişilere açılır) girebilir.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin';
    }

    /**
     * Tek panel: firma veritabanındaki kullanıcı, merkezdeki e-posta → firma eşlemesine
     * yansısın (giriş o eşlemeden firmayı bulur). Firma bağlamı yoksa (eski düzen) dokunma.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if (! $firm = Tenancy::current()) {
                return;
            }
            if ($user->wasChanged('email') && $user->getOriginal('email')) {
                FirmUser::where('hub_firm_id', $firm->id)->where('email', mb_strtolower($user->getOriginal('email')))->delete();
            }
            FirmUser::firstOrCreate(['hub_firm_id' => $firm->id, 'email' => mb_strtolower(trim($user->email))]);
        });

        static::deleted(function (User $user) {
            if ($firm = Tenancy::current()) {
                FirmUser::where('hub_firm_id', $firm->id)->where('email', mb_strtolower(trim($user->email)))->delete();
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
