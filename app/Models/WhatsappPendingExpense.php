<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappPendingExpense extends Model
{
    protected $fillable = [
        'phone',
        'extracted',
        'summary',
        'media_url',
        'status',
        'follow_ups',
        'followed_up_at',
    ];

    protected $casts = [
        'extracted' => 'array',
        'followed_up_at' => 'datetime',
    ];
}
