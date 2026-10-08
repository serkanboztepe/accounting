<?php

namespace App\Tenancy;

/**
 * Merkez tablolar (firmalar, telefonlar, kullanıcı→firma, hub yöneticileri): tek panelde
 * her zaman 'central' bağlantısı — aktif firma ne olursa olsun. Eski düzende varsayılan bağlantı.
 */
trait UsesCentralConnection
{
    public function getConnectionName()
    {
        return Tenancy::enabled() ? 'central' : $this->connection;
    }
}
