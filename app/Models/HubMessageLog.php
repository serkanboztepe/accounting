<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Hub: firma başına gelen/giden WhatsApp mesajı (maliyet takibi). hub_firm_id null = kayıtsız numara. */
class HubMessageLog extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['hub_firm_id', 'phone', 'direction'];
}
