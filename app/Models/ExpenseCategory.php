<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use Concerns\LogsActivity;

    public function activityAction(): string
    {
        return 'Gider kategorisi';
    }

    public function activitySummary(): ?string
    {
        return $this->name;
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact(['Ad' => $this->name, 'Tür' => $this->type]);
    }

    use HasFactory;

    protected $fillable = [
        'name',
        'type',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
