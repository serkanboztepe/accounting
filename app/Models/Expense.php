<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use Concerns\LogsActivity;
    use Concerns\RequiresFeature;

    public static function featureEnabled(): bool
    {
        return \App\Support\Features::expenses();
    }

    public static function featureLabel(): string
    {
        return 'gider takibi';
    }

    public function activityAction(): string
    {
        return 'Gider';
    }

    public function activityAmount(): ?float
    {
        return (float) $this->amount;
    }

    public function activitySummary(): ?string
    {
        return $this->description ?: \App\Models\ActivityLog::name(ExpenseCategory::class, $this->expense_category_id);
    }

    public function activityDetails(): array
    {
        return \App\Models\ActivityLog::compact([
            'Tarih'        => \App\Models\ActivityLog::date($this->expense_date),
            'Tutar'        => \App\Models\ActivityLog::money($this->amount),
            'Kategori'     => \App\Models\ActivityLog::name(ExpenseCategory::class, $this->expense_category_id),
            'Açıklama'     => $this->description,
            'Ödeme durumu' => ['paid' => 'Ödendi', 'unpaid' => 'Ödenmedi', 'partial' => 'Kısmen ödendi'][$this->payment_status] ?? $this->payment_status,
            'Vade'         => \App\Models\ActivityLog::date($this->due_date),
            \App\Support\Labels::project() => \App\Models\ActivityLog::name(\App\Models\Project::class, $this->project_id),
            'Kişi / Firma' => \App\Models\ActivityLog::name(Party::class, $this->party_id),
            'Not'          => $this->notes,
        ]);
    }

    use HasFactory;

    protected $fillable = [
        'project_id',
        'party_id',
        'expense_category_id',
        'expense_date',
        'amount',
        'payment_status',
        'due_date',
        'description',
        'notes',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
