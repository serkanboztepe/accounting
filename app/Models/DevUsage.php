<?php

namespace App\Models;

use App\Tenancy\UsesCentralConnection;
use Illuminate\Database\Eloquent\Model;

/** Yerel (test/geliştirme) AI çağrılarının maliyeti — merkezde, hub "Toplu Maliyet"te görünür. */
class DevUsage extends Model
{
    use UsesCentralConnection;

    protected $table = 'hub_dev_usage';

    protected $fillable = ['source', 'model', 'input_tokens', 'output_tokens', 'cache_write_tokens', 'cache_read_tokens', 'cost_usd'];

    protected $casts = ['cost_usd' => 'float'];
}
