<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Anthropic bakiyesi: girildiği andaki tutar (Billing sayfasından okunur); kalan = bu − sonraki harcama. */
class AiCredit extends Model
{
    use UsesCentralConnection;

    protected $table = 'hub_ai_credits';

    protected $fillable = ['balance_usd', 'note'];

    protected $casts = ['balance_usd' => 'float'];
}
