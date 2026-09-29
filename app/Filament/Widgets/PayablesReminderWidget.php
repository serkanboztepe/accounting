<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ContractPayments\ContractPaymentResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Parties\PartyResource;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\PartyStatement;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Ödeme hatırlatıcı — iki net kavram, birbirine karışmaz:
 *
 *  1) VADELİ ÖDEMELER (gecikmiş/bugün/yaklaşan): vade girilmiş ödenmemiş kalemler
 *     (gider + çek dışı sözleşme ödemesi). Takvim hatırlatması — kalem bazlı.
 *
 *  2) AÇIK CARİ HESAPLARI: net borçlu olduğumuz cariler. Tutar, ham payment_status'ten
 *     DEĞİL, PartyStatement (cari ekstresi) NET bakiyesinden gelir — böylece widget ile
 *     ekstre asla çelişmez. Toptan ödemelerle kapanmış cari (net=0) burada görünmez.
 *
 * Çekler ayrı widget'larda (Yaklaşan/Gecikmiş Çekler).
 */
class PayablesReminderWidget extends Widget
{
    protected string $view = 'filament.widgets.payables-reminder';

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $today = Carbon::today();
        $soon = $today->copy()->addDays(14);

        // --- 1) VADELİ ÖDEMELER (yalnız vade girilmiş ödenmemiş kalemler) ---
        $dueRows = collect();

        foreach (
            Expense::query()
                ->with(['party', 'project', 'category'])
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->whereNotNull('due_date')
                ->get() as $e
        ) {
            $dueRows->push([
                'type'  => 'Gider',
                'title' => $e->description ?: ($e->category?->name ?? 'Gider'),
                'party' => $e->party?->name,
                'amount' => (float) $e->amount,
                'due'   => $e->due_date,
                'url'   => ExpenseResource::getUrl('index'),
            ]);
        }

        foreach (
            ContractPayment::query()
                ->with(['contract.party'])
                ->where('status', 'unpaid')
                ->where('payment_type', '!=', 'check')
                ->whereNotNull('due_date')
                ->get() as $p
        ) {
            $dueRows->push([
                'type'  => 'Sözleşme Ödemesi',
                'title' => $p->contract?->title ?? 'Sözleşme ödemesi',
                'party' => $p->contract?->party?->name,
                'amount' => (float) $p->amount,
                'due'   => $p->due_date,
                'url'   => ContractPaymentResource::getUrl('edit', ['record' => $p->id]),
            ]);
        }

        $buckets = ['gecikmis' => [], 'bugun' => [], 'yaklasan' => []];
        foreach ($dueRows as $row) {
            $due = Carbon::parse($row['due'])->startOfDay();
            if ($due->lt($today)) {
                $row['days'] = (int) abs($due->diffInDays($today));
                $buckets['gecikmis'][] = $row;
            } elseif ($due->eq($today)) {
                $buckets['bugun'][] = $row;
            } elseif ($due->lte($soon)) {
                $row['days'] = (int) abs($today->diffInDays($due));
                $buckets['yaklasan'][] = $row;
            }
            // 14 günden uzak vade henüz acil değil → yaklaşınca görünür.
        }
        usort($buckets['gecikmis'], fn ($a, $b) => $a['due'] <=> $b['due']);
        usort($buckets['yaklasan'], fn ($a, $b) => $a['due'] <=> $b['due']);

        // --- 2) AÇIK CARİ HESAPLARI (ekstre net < 0 → biz borçluyuz) ---
        $partyIds = collect()
            ->merge(Expense::query()->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->merge(PartyLedgerEntry::query()->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->merge(Contract::withoutGlobalScope('purchase')->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->unique()
            ->values();

        $openAccounts = [];
        foreach (Party::whereIn('id', $partyIds)->get() as $party) {
            $balance = PartyStatement::build($party)['balance'];
            // Ekstre: bakiye < 0 → biz cariye borçluyuz. |bakiye| = açık borç.
            if ($balance < -0.01) {
                $openAccounts[] = [
                    'party'  => $party->name,
                    'amount' => abs($balance),
                    'url'    => PartyResource::getUrl('edit', ['record' => $party->id]),
                ];
            }
        }
        usort($openAccounts, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        // --- 3) CARİ'SİZ AÇIK GİDERLER — netlenecek hesap yok, payment_status tek doğru kaynak.
        // Vadeliler zaten (1)'de; burada yalnız cari'siz + vadesiz ödenmemişler.
        $orphanExpenses = [];
        foreach (
            Expense::query()
                ->with(['project', 'category'])
                ->whereIn('payment_status', ['unpaid', 'partial'])
                ->whereNull('party_id')
                ->whereNull('due_date')
                ->orderBy('expense_date')
                ->get() as $e
        ) {
            $orphanExpenses[] = [
                'title'   => $e->description ?: ($e->category?->name ?? 'Gider'),
                'project' => $e->project?->name,
                'amount'  => (float) $e->amount,
                'url'     => ExpenseResource::getUrl('index'),
            ];
        }

        return [
            'buckets'        => $buckets,
            'openAccounts'   => $openAccounts,
            'orphanExpenses' => $orphanExpenses,
            'isEmpty'        => collect($buckets)->every(fn ($b) => count($b) === 0)
                && count($openAccounts) === 0
                && count($orphanExpenses) === 0,
        ];
    }
}
