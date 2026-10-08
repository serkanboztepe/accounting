<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tek panel: kullanıcı e-postası → firma. Girişte hangi firma veritabanına bakılacağını söyler.
 * Firma veritabanındaki users tablosundan otomatik tutulur (bkz User::booted); şifre burada YOK.
 */
class FirmUser extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['hub_firm_id', 'email'];

    protected static function booted(): void
    {
        static::saving(fn (FirmUser $u) => $u->email = mb_strtolower(trim($u->email)));
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(HubFirm::class, 'hub_firm_id');
    }
}
