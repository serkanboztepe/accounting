<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Günlük konuşma analizi raporu (merkez). id = rapor numarası ("Rapor 4: 1'i yapalım…"). */
class ConversationReport extends Model
{
    use UsesCentralConnection;

    protected $fillable = ['report_date', 'message_count', 'firm_count', 'summary', 'body', 'model', 'cost_usd', 'sent_at'];

    protected $casts = ['report_date' => 'date', 'sent_at' => 'datetime', 'cost_usd' => 'decimal:6'];
}
