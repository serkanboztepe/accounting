<?php

namespace App\Services\Agent\Ops;

use App\Http\Controllers\WhatsappWebhookController;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\WhatsappPendingExpense;
use App\Services\Whatsapp\ChatAssistant;
use App\Services\Whatsapp\DraftGaps;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\Money;
use App\Support\PartyStatement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ajanın önerisi (plan v5 §1–2): ajan HİÇBİR ŞEY yazmaz, yalnız önerir.
 *
 *   propose  → işlemler sırayla DENEME kaydından geçer (geri alınır): eksik/çelişki varsa ajana bulgu döner; yoksa kodun yazdığı
 *              kart + gerçek etki (bakiye önce → sonra) döner ve öneri saklanır (status = agent_open; eski yol görmez).
 *   confirm  → çift anahtar: (1) müşterinin HAM mesajında onay var, (2) mesajda kartta olmayan rakam yok, (3) kart gerçekten
 *              gönderildi, (4) o arada kayıtlar değişmedi. Tutmazsa kayıt yok, sebep ajana döner. Tutarsa işlemler TEK işlemde,
 *              mevcut kayıt yoluyla yazılır (hepsi ya da hiçbiri).
 *
 * İşlem türleri: record (gider, ödeme, tahsilat, satış, alış, eski borç/alacak) · delete · edit (sil + yerine yaz) · target_balance
 * ("622.000 kaldı": kayıtla karşılaştırılır, fark varsa önce sebebi sorulur).
 */
final class AgentProposal
{
    public const OP_TYPES = ['record', 'delete', 'edit', 'target_balance'];

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return array{ok: bool, issues?: list<string>, noop?: string, proposal_id?: int, card?: string, effects?: array, warnings?: list<string>}
     */
    public static function propose(string $phone, array $ops, string $sourceText = ''): array
    {
        $ops = array_values(array_filter($ops, 'is_array'));
        if ($ops === []) {
            return ['ok' => false, 'issues' => ['ops boş — en az bir işlem ver.']];
        }
        if (count($ops) > (int) config('agent.ops_per_proposal')) {
            return ['ok' => false, 'issues' => ['Bir öneride en çok ' . config('agent.ops_per_proposal') . ' işlem olur; böl.']];
        }

        $run = DryRun::run(fn () => self::apply($phone, $ops, $sourceText));
        if ($run['issues'] !== []) {
            return ['ok' => false, 'issues' => $run['issues']];
        }
        if ($run['lines'] === []) {
            return ['ok' => false, 'noop' => implode(' ', $run['notes']) ?: 'Yazılacak bir şey yok.'];
        }

        $large = collect($run['amounts'])->filter(fn ($a) => $a >= (float) config('agent.large_amount'))->max();
        $card = AgentCard::build($run['lines'], $run['balances'], $run['deletes'], $large !== null ? (float) $large : null, $run['warnings']);

        WhatsappPendingExpense::agentOpen($phone)->update(['status' => WhatsappPendingExpense::AGENT_SUPERSEDED]);
        $proposal = WhatsappPendingExpense::create([
            'phone' => $phone,
            'status' => WhatsappPendingExpense::AGENT_OPEN,
            'summary' => $card,
            'extracted' => [
                'agent' => true,
                'ops' => $ops,
                'source_text' => mb_substr($sourceText, 0, 1000),
                'card_sent' => false,
                'fingerprint' => self::fingerprint($ops, $run['party_ids'], $run['refs']),
                'party_ids' => $run['party_ids'],
                'refs' => $run['refs'],
                'numbers' => $run['numbers'],
                'large_amount' => $large !== null ? (float) $large : null,
                'deletes' => $run['deletes'],
            ],
        ]);

        return [
            'ok' => true,
            'proposal_id' => $proposal->id,
            'card' => $card,
            'effects' => [
                'yazılacak_satırlar' => $run['created_lines'],
                'bakiyeler' => array_map(fn ($b) => ['kişi' => $b['name'], 'önce' => AgentCard::phrase($b['before']), 'sonra' => AgentCard::phrase($b['after'])], $run['balances']),
                'notlar' => $run['notes'],
            ],
            'warnings' => $run['warnings'],
        ];
    }

    /** Açık öneri (son `proposal_minutes` içinde). */
    public static function open(string $phone): ?WhatsappPendingExpense
    {
        return WhatsappPendingExpense::agentOpen($phone)
            ->where('updated_at', '>=', now()->subMinutes((int) config('agent.proposal_minutes')))
            ->latest('id')->first();
    }

    /** Kart müşteriye gerçekten gitti (AgentTurn teslimden sonra çağırır) — gitmeyen kart onaylanamaz. */
    public static function markSent(int $proposalId): void
    {
        $p = WhatsappPendingExpense::whereKey($proposalId)->where('status', WhatsappPendingExpense::AGENT_OPEN)->first();
        if ($p) {
            $p->update(['extracted' => ['card_sent' => true] + $p->extracted]);
        }
    }

    /**
     * @return array{ok: bool, reason?: string, text?: string, created?: list<array{0:string,1:int}>}
     */
    public static function confirm(string $phone, int $proposalId, string $rawMessage): array
    {
        $proposal = self::open($phone);
        if (! $proposal || $proposal->id !== $proposalId) {
            return self::no('Bu numarada bu id ile onay bekleyen öneri yok (süresi dolmuş ya da yenisi yapılmış olabilir). Gerekirse yeniden öner.');
        }
        $x = $proposal->extracted;
        if (empty($x['card_sent'])) {
            return self::no('Kart henüz müşteriye gösterilmedi — önce kartı gönder, onayı sonraki mesajda al.');
        }

        // Anahtar 1: müşterinin kendi mesajında onay.
        $deletes = (int) ($x['deletes'] ?? 0);
        $large = $x['large_amount'] ?? null;
        $lower = mb_strtolower(strtr($rawMessage, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');
        if ($deletes >= (int) config('agent.delete_word_from')) {
            if (! preg_match('/(^|[^\p{L}])sil([^\p{L}]|$)/u', $lower)) {
                return self::no($deletes . ' kayıt silinecek: müşteri "sil" yazmadan silinmez. Kartı hatırlat.');
            }
        } elseif ($large !== null) {
            if (! Numbers::contains($rawMessage, (float) $large)) {
                return self::no('Tutar büyük (' . Money::format((float) $large) . ' ₺): müşteri tutarı yazmadan kaydedilmez ("evet" yetmez). Kartı hatırlat.');
            }
        } elseif (! ChatAssistant::hasAssent($rawMessage)) {
            return self::no('Müşterinin mesajında onay yok ("evet", "tamam", "kaydet"…). Kayıt yapılmadı; ne demek istediğini yorumla ya da sor.');
        }
        // "sil" silme onayında iptal sözü değildir; öteki durumlarda iptal sözü onayı bozar ("evet değil iptal").
        if ($deletes < (int) config('agent.delete_word_from') && ChatAssistant::hasCancel($rawMessage)) {
            return self::no('Müşterinin mesajında onayla birlikte vazgeçme/itiraz sözü de var — onay sayılmaz. Ne demek istediğini yorumla ya da sor.');
        }

        // Anahtar 2: mesajda kartta olmayan rakam yok.
        if (($new = Numbers::unknown($rawMessage, (array) ($x['numbers'] ?? []))) !== null) {
            return self::no('Müşteri kartta olmayan bir rakam yazdı (' . Money::format($new) . '). Kayıt yapılmadı — kartı düzeltmek istiyor olabilir; '
                . 'ne dediğini yorumla, gerekirse yeni öneri yap.');
        }

        // Anahtar 3: o arada kayıtlar değişmedi.
        if (self::fingerprint((array) $x['ops'], (array) ($x['party_ids'] ?? []), (array) ($x['refs'] ?? [])) !== ($x['fingerprint'] ?? null)) {
            $proposal->update(['status' => WhatsappPendingExpense::AGENT_SUPERSEDED]);

            return self::no('Kart gösterildikten sonra bu kişilerin kayıtları değişti — kart bayat. Güncel durumu oku ve yeniden öner.');
        }
        if ($deletes > 0 && self::deletedToday() + $deletes > (int) config('agent.delete_per_day')) {
            return self::no('Günlük silme sınırı (' . config('agent.delete_per_day') . ') doldu — kalanı panelden silinir.');
        }

        try {
            $run = DB::transaction(function () use ($phone, $proposal, $x) {
                // Çift onay emniyeti: öneri satırı kilitli okunur.
                $locked = WhatsappPendingExpense::whereKey($proposal->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== WhatsappPendingExpense::AGENT_OPEN) {
                    throw new RuntimeException('Bu öneri zaten işlendi.');
                }
                $run = self::apply($phone, (array) $x['ops'], (string) ($x['source_text'] ?? ''));
                if ($run['issues'] !== []) {
                    throw new RuntimeException(implode(' ', $run['issues']));
                }
                $locked->update(['status' => WhatsappPendingExpense::AGENT_CONFIRMED,
                    'extracted' => ['created_records' => $run['created'], 'deleted_count' => $run['deletes']] + $x]);

                return $run;
            });
        } catch (RuntimeException $e) {
            return self::no('Kaydedilemedi, hiçbir şey yazılmadı: ' . $e->getMessage());
        }

        $lines = array_map(fn ($b) => '📒 ' . $b['name'] . ': ' . AgentCard::phrase($b['after']), $run['balances']);

        return ['ok' => true, 'created' => $run['created'], 'party_ids' => $run['party_ids'],
            'text' => trim(($run['deletes'] > 0 && $run['created'] === [] ? '🗑️ Silindi.' : '✅ Kaydedildi.') . "\n" . implode("\n", $lines))];
    }

    /** @return array{ok: bool, reason?: string} */
    public static function cancel(string $phone, int $proposalId, string $rawMessage): array
    {
        $proposal = WhatsappPendingExpense::agentOpen($phone)->whereKey($proposalId)->first();
        if (! $proposal) {
            return self::no('Açık öneri yok.');
        }
        if (! ChatAssistant::hasCancel($rawMessage)) {
            return self::no('Müşterinin mesajında vazgeçme sözü yok ("iptal", "vazgeç", "hayır"…) — öneri duruyor. Ne demek istediğini yorumla ya da sor.');
        }
        $proposal->update(['status' => WhatsappPendingExpense::AGENT_CANCELLED]);

        return ['ok' => true];
    }

    // ── İşlemleri uygula (deneme kaydında ve gerçek kayıtta AYNI yol) ─────────────────────────────

    /**
     * @return array{issues: list<string>, warnings: list<string>, notes: list<string>, lines: list<string>, created: list<array{0:string,1:int}>,
     *   created_lines: list<string>, balances: list<array{party_id:int,name:string,before:float,after:float}>, party_ids: list<int>,
     *   refs: list<string>, numbers: list<float>, amounts: list<float>, deletes: int}
     */
    private static function apply(string $phone, array $ops, string $sourceText): array
    {
        $s = ['issues' => [], 'warnings' => [], 'notes' => [], 'lines' => [], 'created' => [], 'created_lines' => [], 'before' => [],
            'party_ids' => [], 'refs' => [], 'amounts' => [], 'old_amounts' => [], 'deletes' => 0];

        foreach ($ops as $i => $op) {
            $type = (string) ($op['type'] ?? '');
            $prefix = count($ops) > 1 ? ($i + 1) . '. işlem: ' : '';
            $issues = match ($type) {
                'record' => self::applyRecord($s, $phone, (array) ($op['entry'] ?? $op), $sourceText),
                'delete' => self::applyDelete($s, (array) ($op['refs'] ?? [])),
                'edit' => self::applyEdit($s, $phone, $op, $sourceText),
                'target_balance' => self::applyTarget($s, $phone, $op, $sourceText),
                default => ['type şunlardan biri olmalı: ' . implode(', ', self::OP_TYPES) . '.'],
            };
            if ($issues !== []) {
                $s['issues'] = array_map(fn ($x) => $prefix . $x, $issues);
                break; // sonraki işlemler öncekinin sonucuna bağlı
            }
        }
        if ($s['deletes'] > (int) config('agent.delete_per_card')) {
            $s['issues'][] = 'Bir kartta en çok ' . config('agent.delete_per_card') . ' kayıt silinir (' . $s['deletes'] . ' istendi) — böl ya da panele yönlendir.';
        }

        $balances = [];
        foreach (array_unique($s['party_ids']) as $id) {
            if ($party = Party::find($id)) {
                $balances[] = ['party_id' => $id, 'name' => (string) $party->name, 'before' => (float) ($s['before'][$id] ?? 0.0),
                    'after' => round((float) PartyStatement::build($party)['balance'], 2)];
            }
        }
        $numbers = [...$s['amounts'], ...$s['old_amounts']];
        foreach ($balances as $b) {
            array_push($numbers, abs($b['before']), abs($b['after']), abs($b['after'] - $b['before']));
        }

        return [
            'issues' => $s['issues'], 'warnings' => array_values(array_unique($s['warnings'])), 'notes' => $s['notes'], 'lines' => $s['lines'],
            'created' => $s['created'], 'created_lines' => $s['created_lines'], 'balances' => $balances,
            'party_ids' => array_values(array_unique(array_map('intval', $s['party_ids']))), 'refs' => array_values(array_unique($s['refs'])),
            'numbers' => array_values(array_unique(array_map(fn ($n) => round((float) $n, 2), $numbers))),
            'amounts' => $s['amounts'], 'deletes' => $s['deletes'],
        ];
    }

    private static function touch(array &$s, ?int $partyId): void
    {
        if ($partyId && ! array_key_exists($partyId, $s['before'])) {
            $party = Party::find($partyId);
            $s['before'][$partyId] = $party ? round((float) PartyStatement::build($party)['balance'], 2) : 0.0;
        }
        if ($partyId) {
            $s['party_ids'][] = $partyId;
        }
    }

    /** @return list<string> bulgular (boşsa yazıldı) */
    private static function applyRecord(array &$s, string $phone, array $entry, string $sourceText, ?string $linePrefix = null): array
    {
        $r = OperationAdapter::record($entry, $phone);
        if ($r['issues'] !== []) {
            return $r['issues'];
        }
        $d = $r['draft'];
        self::touch($s, ! empty($d['party_id']) ? (int) $d['party_id'] : null);

        $child = WhatsappPendingExpense::create(['phone' => $phone, 'extracted' => $d, 'summary' => DraftGaps::label($d), 'status' => 'awaiting_confirmation']);
        $done = app(WhatsappWebhookController::class)->agentCommit($child, $sourceText);
        if ($done['status'] !== 'confirmed') {
            $child->delete();

            return [trim(str_replace(['*', '❓', '✋'], '', $done['text']))];
        }
        if ($done['party_id'] && ! array_key_exists($done['party_id'], $s['before'])) {
            $s['before'][$done['party_id']] = 0.0; // bu işlemle açılan yeni kişi
        }
        if ($done['party_id']) {
            $s['party_ids'][] = (int) $done['party_id'];
        }
        $s['created'] = [...$s['created'], ...$done['created']];
        foreach ($done['created'] as [$type, $id]) {
            if ($model = RecordRefs::find($type . ':' . $id)) {
                $s['created_lines'][] = RecordRefs::line($model);
            }
        }
        $s['lines'][] = ($linePrefix ?? '') . AgentCard::recordLine($d);
        $s['amounts'][] = (float) $d['amount'];
        $s['warnings'] = [...$s['warnings'], ...$r['warnings']];

        return [];
    }

    /** @return list<string> */
    private static function applyDelete(array &$s, array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn ($r) => is_string($r) ? trim($r) : null, $refs))));
        if ($refs === []) {
            return ['refs boş — silinecek kayıtların ref\'lerini ver (records / party_overview sonucundan).'];
        }
        $issues = array_values(array_filter(array_map(fn (string $ref) => RecordRefs::blocker($ref), $refs)));
        if ($issues !== []) {
            return $issues;
        }
        foreach ($refs as $ref) {
            $model = RecordRefs::find($ref);
            self::touch($s, RecordRefs::partyId($model));
            $s['refs'][] = $ref;
            $s['lines'][] = '🗑️ Sil: ' . RecordRefs::line($model);
            $s['old_amounts'][] = (float) ($model->amount ?? $model->total_amount ?? 0);
            $model->delete();
            $s['deletes']++;
        }

        return [];
    }

    /** Düzeltme = eski kayıt silinir + doğrusu yazılır, tek işlemde. @return list<string> */
    private static function applyEdit(array &$s, string $phone, array $op, string $sourceText): array
    {
        $ref = is_string($op['ref'] ?? null) ? trim($op['ref']) : '';
        if (($blocker = RecordRefs::blocker($ref)) !== null) {
            return [$blocker];
        }
        if (! is_array($op['entry'] ?? null)) {
            return ['edit için entry (kaydın doğru hâli, tüm alanlarıyla) gerekli.'];
        }
        $model = RecordRefs::find($ref);
        $old = RecordRefs::line($model);
        self::touch($s, RecordRefs::partyId($model));
        $s['refs'][] = $ref;
        $s['old_amounts'][] = (float) ($model->amount ?? $model->total_amount ?? 0);
        $model->delete();

        // Aynı tutar/kişi/gün "çift kayıt" sayılmasın: eskisi az önce silindi.
        return self::applyRecord($s, $phone, ['allow_duplicate' => true] + $op['entry'], $sourceText, '✏️ Düzelt: ' . $old . ' → ');
    }

    /**
     * "Gürbüz'e 622.000 kaldı": kayıtla karşılaştırılır. Aynıysa yazılmaz; fark varsa ÖNCE sebebi sorulur (kullanıcı kararı 09.11) —
     * sebebe göre doğru tür kayıt (ödeme/tahsilat ya da eski borç düzeltmesi) önerilir, doğrudan "düzeltme" satırı yazılmaz.
     *
     * @return list<string>
     */
    private static function applyTarget(array &$s, string $phone, array $op, string $sourceText): array
    {
        $party = Party::find((int) ($op['party_id'] ?? 0));
        if (! $party) {
            return ['target_balance için kayıtlı kişi (party_id) gerekli. Kişi yeniyse eski borcu debt_note ile yaz.'];
        }
        $side = $op['side'] ?? null;
        $amount = round((float) ($op['amount'] ?? 0), 2);
        if (! in_array($side, ['we_owe', 'they_owe', 'closed'], true) || ($side !== 'closed' && $amount <= 0)) {
            return ['side: "we_owe" (biz ona borçluyuz) / "they_owe" (o bize borçlu) / "closed" (hesap kapalı) ve amount gerekli.'];
        }
        $target = match ($side) {
            'we_owe' => -$amount, 'they_owe' => $amount, default => 0.0,
        };
        $current = round((float) PartyStatement::build($party)['balance'], 2);
        $diff = round($target - $current, 2); // + : o bize daha borçlu / biz daha az borçluyuz
        if (abs($diff) < 0.01) {
            $s['notes'][] = $party->name . ' için kayıtlı durum zaten böyle: ' . AgentCard::phrase($current) . '. Yazılacak bir şey yok.';

            return [];
        }
        $hasHistory = PartyLedgerEntry::where('party_id', $party->id)->exists() || abs($current) >= 0.01;
        if (! $hasHistory) {
            // Hiç kaydı yok: söylenen rakam eski borcun/alacağın kendisi.
            return self::applyRecord($s, $phone, ['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'party_id' => $party->id, 'amount' => abs($target),
                'debt_side' => $target < 0 ? 'payable' : 'receivable', 'description' => 'eski borç', 'date' => $op['date'] ?? null], $sourceText);
        }
        $reason = $op['reason'] ?? null;
        if (! in_array($reason, ['payment', 'old_debt'], true)) {
            return [$party->name . ' için kayıtlı durum: ' . AgentCard::phrase($current) . '; söylenen: ' . AgentCard::phrase($target) . '; fark '
                . Money::format(abs($diff)) . ' ₺. Sebebini bilmeden yazma — konuşmadan belli değilse müşteriye sor (ask): '
                . '1) yazmadığım bir ödeme/tahsilat var · 2) eski borç aslında farklıydı · 3) emin değilim, dokunma. '
                . 'Cevaba göre reason="payment" ya da reason="old_debt" ile yeniden öner; 3 ise yazma.'];
        }
        if ($reason === 'payment') {
            // Fark kadar para hareketi: + ise biz ödedik (borcumuz azaldı), − ise biz aldık.
            return self::applyRecord($s, $phone, ['kind' => $diff > 0 ? ExpenseExtractor::KIND_PAYMENT : ExpenseExtractor::KIND_COLLECTION,
                'party_id' => $party->id, 'amount' => abs($diff), 'date' => $op['date'] ?? null, 'payment_type' => $op['payment_type'] ?? null,
                'purpose' => $op['purpose'] ?? null, 'description' => trim((string) ($op['description'] ?? ''))], $sourceText);
        }
        // Eski borç farklıydı: tek "Borç/Alacak kaydı" satırı varsa o düzeltilir; yoksa fark kadar eski borç/alacak yazılır.
        $payable = $diff < 0; // biz daha çok borçluyuz
        $carried = PartyLedgerEntry::where('party_id', $party->id)->where(fn ($q) => $q->where('description', 'like', 'Borç kaydı%')
            ->orWhere('description', 'like', 'Alacak kaydı%'))->get();
        if ($carried->count() === 1) {
            $row = $carried->first();
            $rowPayable = $row->type === PartyLedgerEntry::TYPE_PURCHASE;
            $new = round((float) $row->amount + ($rowPayable ? -$diff : $diff), 2);
            if ($new > 0.009) {
                return self::applyEdit($s, $phone, ['ref' => 'ledger:' . $row->id, 'entry' => ['kind' => ExpenseExtractor::KIND_DEBT_NOTE,
                    'party_id' => $party->id, 'amount' => $new, 'debt_side' => $rowPayable ? 'payable' : 'receivable',
                    'date' => $row->entry_date?->toDateString(), 'description' => 'eski borç', 'project_id' => $row->project_id]], $sourceText);
            }
        }

        return self::applyRecord($s, $phone, ['kind' => ExpenseExtractor::KIND_DEBT_NOTE, 'party_id' => $party->id, 'amount' => abs($diff),
            'debt_side' => $payable ? 'payable' : 'receivable', 'description' => 'eski borç düzeltmesi', 'date' => $op['date'] ?? null], $sourceText);
    }

    // ── Yardımcılar ───────────────────────────────────────────────────────────────────────────

    /** Önerinin dayandığı durumun izi: işlemler + ilgili kişilerin son satırı ve bakiyesi + silinecek/düzeltilecek kayıtların hâli. */
    public static function fingerprint(array $ops, array $partyIds, array $refs): string
    {
        $parties = [];
        foreach (array_unique(array_map('intval', $partyIds)) as $id) {
            $party = Party::find($id);
            $parties[$id] = $party ? [PartyLedgerEntry::where('party_id', $id)->max('id'), PartyLedgerEntry::where('party_id', $id)->count(),
                round((float) PartyStatement::build($party)['balance'], 2)] : null; // yeni kişi önerideyken yok
        }
        ksort($parties);
        sort($refs);

        return sha1((string) json_encode([$ops, $parties, array_map(fn ($r) => RecordRefs::stamp((string) $r), $refs)]));
    }

    private static function deletedToday(): int
    {
        return (int) WhatsappPendingExpense::where('status', WhatsappPendingExpense::AGENT_CONFIRMED)
            ->where('updated_at', '>=', now('Europe/Istanbul')->startOfDay()->setTimezone(config('app.timezone')))
            ->get(['extracted'])->sum(fn ($p) => (int) ($p->extracted['deleted_count'] ?? 0));
    }

    private static function no(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }
}
