<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Kişiye bağlı eski giderleri kişinin hesabına taşır (28.10, kullanıcı: "hepsini cariye al"). Gider = kimseye bağlı olmayan
 * harcama; kişiyle olan her şey kişinin hesabında. Her kişili gider → aynı tarih / proje / açıklamayla alış (kategori adı
 * açıklamanın sonuna); ödendiyse yanına aynı projeyle ödeme (net 0). Ödenmemiş / kısmi → yalnız alış (döküm de kısmiyi
 * ödenmemiş sayıyordu). Gider silinir. Kişisiz giderlere dokunulmaz.
 *
 * Emniyet: kişi bakiyesi, proje maliyeti (gider + alış) ve ay toplamı (gider + alış) önce = sonra değilse istisna atar —
 * çağıran transaction'ı geri alır (FirmsExpensePartiesToLedger).
 */
final class ExpensePartyMove
{
    public const NOTE = 'Giderden taşındı';

    /** @return array{rows: list<array>, checks: list<string>, kinds: list<string>} */
    public static function run(): array
    {
        $expenses = Expense::with(['party', 'category', 'project'])->whereNotNull('party_id')->orderBy('expense_date')->orderBy('id')->get();
        if ($expenses->isEmpty()) {
            return ['rows' => [], 'checks' => [], 'kinds' => []];
        }

        $parties = $expenses->pluck('party')->filter()->unique('id');
        $projectIds = $expenses->pluck('project_id')->filter()->unique()->values();
        $months = $expenses->map(fn (Expense $e) => $e->expense_date->format('Y-m'))->unique()->values();
        $kindsBefore = $parties->mapWithKeys(fn (Party $p) => [$p->id => $p->kind]);
        $before = self::snapshot($parties, $projectIds, $months);

        $rows = [];
        foreach ($expenses as $e) {
            $desc = trim((string) ($e->description ?: ($e->category?->name ?? 'Gider')));
            if ($e->category && ! str_contains(mb_strtolower($desc), mb_strtolower($e->category->name))) {
                $desc .= ' · ' . $e->category->name;
            }
            $paid = $e->payment_status === 'paid';
            $note = self::NOTE . ' (gider #' . $e->id . ')' . ($e->notes ? ' — ' . $e->notes : '');

            PartyLedgerEntry::create([
                'party_id' => $e->party_id,
                'project_id' => $e->project_id,
                'entry_date' => $e->expense_date->toDateString(),
                'due_date' => $paid ? null : $e->due_date?->toDateString(),
                'type' => PartyLedgerEntry::TYPE_PURCHASE,
                'description' => $desc,
                'amount' => $e->amount,
                'notes' => $note,
            ]);
            if ($paid) {
                PartyLedgerEntry::create([
                    'party_id' => $e->party_id,
                    'project_id' => $e->project_id,
                    'entry_date' => $e->expense_date->toDateString(),
                    'type' => PartyLedgerEntry::TYPE_PAYMENT,
                    'description' => 'Alış ödemesi — ' . $desc,
                    'amount' => $e->amount,
                    'notes' => $note,
                ]);
            }
            ActivityLog::deleteQuery(Expense::whereKey($e->id), 'Kişinin hesabına alış olarak taşındı');

            $rows[] = ['id' => $e->id, 'date' => $e->expense_date->format('d.m.Y'), 'party' => $e->party?->name, 'amount' => (float) $e->amount,
                'paid' => $paid, 'project' => $e->project?->name, 'description' => $desc,
                'advance_word' => str_contains(mb_strtolower($desc), 'avans')];
        }

        $after = self::snapshot($parties->map(fn (Party $p) => $p->fresh()), $projectIds, $months);
        $checks = [];
        foreach ($before as $key => $value) {
            if (abs($value - $after[$key]) >= 0.01) {
                throw new RuntimeException("{$key}: önce " . Money::format($value) . ', sonra ' . Money::format($after[$key]));
            }
            $checks[] = "{$key}: " . Money::format($value) . ' ✓';
        }

        $kinds = [];
        foreach ($parties as $p) {
            $now = Party::whereKey($p->id)->value('kind');
            if ($now !== $kindsBefore[$p->id]) {
                $kinds[] = $p->name . ': tip ' . ($kindsBefore[$p->id] ? Party::KINDS[$kindsBefore[$p->id]] : 'boş') . ' → ' . (Party::KINDS[$now] ?? 'boş');
            }
        }

        return ['rows' => $rows, 'checks' => $checks, 'kinds' => $kinds];
    }

    /** Taşımada değişmemesi gereken rakamlar: kişi bakiyesi, proje (gider + alış), ay (gider + alış). */
    private static function snapshot($parties, $projectIds, $months): array
    {
        $out = [];
        foreach ($parties as $p) {
            $out['Bakiye · ' . $p->name] = (float) PartyStatement::build($p)['balance'];
        }
        foreach ($projectIds as $id) {
            $out['Proje · ' . \App\Models\Project::whereKey($id)->value('name')] = (float) Expense::where('project_id', $id)->sum('amount')
                + ProjectCost::purchases($id);
        }
        foreach ($months as $m) {
            $from = Carbon::parse($m . '-01');
            $r = MonthlyResult::forRange($from, $from->copy()->endOfMonth());
            $out['Ay · ' . $m] = (float) Expense::whereBetween('expense_date', [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()])->sum('amount')
                + $r['purchases'];
        }

        return $out;
    }
}
