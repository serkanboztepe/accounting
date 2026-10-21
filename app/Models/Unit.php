<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use Concerns\LogsActivity;

    public function activityAction(): string
    {
        return 'Birim';
    }

    public function activitySummary(): ?string
    {
        return $this->name;
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact(['Ad' => $this->name, 'Kod' => $this->code]);
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'code',
    ];

}
