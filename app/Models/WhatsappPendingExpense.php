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
    ];

    protected $casts = [
        'extracted' => 'array',
    ];
}
