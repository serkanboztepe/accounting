<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\ContractPayment;
use App\Models\Expense;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Ödenmemiş borç hatırlatıcısı — ödenmemiş/kısmi giderler + çek dışı ödenmemiş
 * sözleşme ödemeleri, vadeye göre gruplanır: Gecikmiş / Bugün / Yaklaşan (14g) /
 * Vadesiz açık borçlar. Çekler ayrı widget'larda (Yaklaşan/Gecikmiş Çekler).
 * İleride aynı veri WhatsApp hatırlatmasını besleyecek.
 */
class PayablesReminderWidget extends Widget
{
    protected string $view = 'filament.widgets.payables-reminder';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(14);

        $rows = collect();

        // Ödenmemiş / kısmi giderler
        foreach (
            Expense::query()
                ->with(['party', 'project', 'category'])
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->get() as $e
        ) {
            $rows->push([
                'type'    => 'Gider',
                'title'   => $e->description ?: ($e->category?->name ?? 'Gider'),
                'party'   => $e->party?->name,
                'project' => $e->project?->name,
                'amount'  => (float) $e->amount,
                'due'     => $e->due_date,
                'url'     => ExpenseResource::getUrl('index'),
            ]);
        }

        // Ödenmemiş çek dışı sözleşme ödemeleri (çek vadesi ayrı widget'larda)
        foreach (
            ContractPayment::query()
                ->with(['contract.party', 'contract.project'])
                ->where('status', 'unpaid')
                ->where('payment_type', '!=', 'check')
                ->get() as $p
        ) {
            $rows->push([
                'type'    => 'Sözleşme Ödemesi',
                'title'   => $p->contract?->title ?? 'Sözleşme ödemesi',
                'party'   => $p->contract?->party?->name,
                'project' => $p->contract?->project?->name,
                'amount'  => (float) $p->amount,
                'due'     => $p->due_date,
                'url'     => ContractPaymentResource::getUrl('edit', ['record' => $p->id]),
            ]);
        }

        // Vadeye göre grupla. Vadesi 14 günden uzak olanlar "acil değil" → gösterilmez
        // (vadeye 14 gün kalınca yaklaşana düşer). Vadesiz açık borçlar hep listelenir.
        $buckets = ['gecikmis' => [], 'bugun' => [], 'yaklasan' => [], 'vadesiz' => []];

        foreach ($rows as $row) {
            $due = $row['due'] ? Carbon::parse($row['due'])->startOfDay() : null;

            if ($due === null) {
                $buckets['vadesiz'][] = $row;
            } elseif ($due->lt($today)) {
                $row['days'] = (int) abs($due->diffInDays($today));
                $buckets['gecikmis'][] = $row;
            } elseif ($due->eq($today)) {
                $buckets['bugun'][] = $row;
            } elseif ($due->lte($soon)) {
                $row['days'] = (int) abs($today->diffInDays($due));
                $buckets['yaklasan'][] = $row;
            }
        }

        // Gecikmiş: en eski önce; yaklaşan: en yakın önce
        usort($buckets['gecikmis'], fn ($a, $b) => $a['due'] <=> $b['due']);
        usort($buckets['yaklasan'], fn ($a, $b) => $a['due'] <=> $b['due']);

        return [
            'buckets' => $buckets,
            'isEmpty' => collect($buckets)->every(fn ($b) => count($b) === 0),
        ];
    }
}
