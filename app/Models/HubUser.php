<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** Tek panel: /hub yöneticisi (S-CODER). Merkez veritabanının users tablosu; firma verisine erişmez. */
class HubUser extends Authenticatable implements FilamentUser
{
    use UsesCentralConnection;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'hub';
    }
}
