<?php

namespace App\Services\Agent\Ops;

use App\Models\Party;
use App\Models\Project;
use App\Services\Agent\Tools\FindParty;
use App\Services\Whatsapp\DraftGaps;
use App\Services\Whatsapp\DraftWarnings;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Services\Whatsapp\ProjectChoice;
use App\Support\Money;
use App\Support\PartyStatement;
use Illuminate\Support\Carbon;

/**
 * Ajanın önerdiği işlem → mevcut kayıt yolunun beklediği taslak (plan v5 §2, OperationAdapter). Kayıt kurallarını YENİDEN YAZMAZ:
 * taslak eski yolun kapılarından (DraftGaps, ProjectChoice) geçer; kapıların sorduğu şey ajana "bulgu" olarak döner — ajan okuyarak
 * ya da kullanıcıya sorarak çözer ve yeniden önerir.
 *
 * Belirsizliği sessizce çözen eski bayraklar burada YOK: ödeme amacı, ödendi/veresiye, borç yönü, benzer adlı kişi — hepsi açıkça
 * verilmek zorunda (bakiyeyi değiştiren belirsizlik sorulur; kullanıcı kararı 16.10 / 09.11).
 */
final class OperationAdapter
{
    public const KINDS = [
        ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_COLLECTION,
        ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_DEBT_NOTE,
    ];

    /**
     * Eski asistanın kendi taslak sınıflarıyla yazılan türler (TradeDraft, EmployeeDraft, RecurringDraft, hatırlatma): ajan alanları
     * eski şemadaki adlarıyla verir; temizlik, ürün eşleştirme, maaş hesabı, hatırlatma saati, kart ve kayıt O sınıflardan gelir.
     */
    public const EXTENDED_KINDS = [
        ExpenseExtractor::KIND_TRADE_SALE, ExpenseExtractor::KIND_TRADE_PURCHASE, ExpenseExtractor::KIND_STOCK_COUNT,
        ExpenseExtractor::KIND_STOCK_SETTING, ExpenseExtractor::KIND_PRODUCT_ADD, ExpenseExtractor::KIND_PRODUCTION,
        ExpenseExtractor::KIND_SALARY, ExpenseExtractor::KIND_EMPLOYEE_SETTING,
        ExpenseExtractor::KIND_REMINDER, ExpenseExtractor::KIND_RECURRING_EXPENSE, ExpenseExtractor::KIND_RECURRING_STOP,
    ];

    /** Bu türlerde ajanın doldurabildiği eski şema alanları (tanımları ExpenseExtractor şemasından aynen alınır). */
    public const EXTENDED_FIELDS = [
        'items', 'cash_amount', 'card_amount', 'sale_paid_said', 'product_id', 'product_name', 'unit', 'counted_quantity', 'stock_items',
        'daily_usage', 'reorder_level', 'purchase_price', 'salary_amount', 'salary_day', 'salary_deduction', 'first_salary', 'first_salary_amount',
        'is_staff', 'month', 'event_date', 'event_time', 'lead_minutes', 'is_alarm', 'notify_time', 'updates_reminder_id', 'repeat',
        'recurring_frequency', 'recurring_day', 'recurring_time', 'recurring_start',
    ];

    public const PAYMENT_TYPES = ['cash', 'eft', 'bank_transfer', 'check', 'promissory_note', 'other'];

    public const PURPOSES = ['debt', 'advance', 'loan', 'on_behalf'];

    /**
     * @return array{draft: ?array, issues: list<string>, warnings: list<string>}
     */
    public static function record(array $e, string $phone): array
    {
        $issues = [];
        $warnings = [];
        $kind = (string) ($e['kind'] ?? '');
        if (! in_array($kind, self::KINDS, true)) {
            return self::fail('kind şunlardan biri olmalı: ' . implode(', ', self::KINDS) . '.');
        }
        $amount = round((float) ($e['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return self::fail('Tutar yok ya da sıfır — tutarı kullanıcıdan öğren.');
        }
        $date = self::date($e['date'] ?? null);
        if ($date === null) {
            return self::fail('date YYYY-MM-DD olmalı (söylenmediyse boş bırak, bugün yazılır).');
        }

        $d = [
            'kind' => $kind,
            'amount' => $amount,
            'date' => $date,
            'due_date' => self::date($e['due_date'] ?? null, null),
            'description' => mb_strimwidth(trim((string) ($e['description'] ?? '')), 0, 200, '…'),
            'party_id' => null, 'party_name' => null,
            'project_id' => null, 'project_name' => null,
            'category_id' => ! empty($e['category_id']) ? (int) $e['category_id'] : null,
            'category_name' => self::text($e['category_name'] ?? null),
            'paid' => isset($e['paid']) ? (bool) $e['paid'] : null,
            'payment_type' => in_array($e['payment_type'] ?? null, self::PAYMENT_TYPES, true) ? $e['payment_type'] : null,
            'items' => [],
            'confidence' => 'high', 'question' => null, 'is_new_entry' => true,
            'party_confirmed' => true, 'party_certain' => true,
            'source' => 'agent',
        ];

        // ── Kişi ─────────────────────────────────────────────────────────
        $needsParty = $kind !== ExpenseExtractor::KIND_EXPENSE;
        if (! empty($e['party_id'])) {
            if (! Party::whereKey((int) $e['party_id'])->exists()) {
                return self::fail('party_id ' . (int) $e['party_id'] . ' kayıtlı değil — find_party ile bul.');
            }
            $d['party_id'] = (int) $e['party_id'];
        } elseif (($name = self::text($e['party_name'] ?? null)) !== null) {
            if ($same = DraftGaps::exactParty($name)) {
                $d['party_id'] = $same->id; // adı birebir tutan tek kişi
            } elseif (empty($e['new_party'])) {
                $near = collect(FindParty::run(['name' => $name])['adaylar'] ?? [])->whereIn('güç', ['güçlü', 'orta'])->take(3);
                if ($near->isNotEmpty()) {
                    $issues[] = '"' . $name . '" adına benzer kayıtlı kişi var: ' . $near->map(fn ($r) => $r['ad'] . ' (id ' . $r['id'] . ')')->implode(', ')
                        . '. Aynı kişiyse party_id ver; farklı ve YENİ biriyse new_party=true ile öner (emin değilsen kullanıcıya sor).';
                }
                $d['party_name'] = $name;
            } else {
                $d['party_name'] = $name;
            }
        } elseif ($needsParty) {
            $issues[] = 'Bu işlem bir kişiyle/firmayla ilgili ama kişi verilmedi (party_id ya da party_name).';
        }
        if ($kind === ExpenseExtractor::KIND_EXPENSE && ($d['party_id'] || $d['party_name'])) {
            return self::fail('Gider kişiye bağlanmaz. Kişiden alınan iş/mal → kind "purchase" (paid ile); kişiye verilen para → kind "payment".');
        }

        // ── Yer / proje ──────────────────────────────────────────────────
        if (! empty($e['project_id'])) {
            if (! Project::whereKey((int) $e['project_id'])->exists()) {
                return self::fail('project_id ' . (int) $e['project_id'] . ' kayıtlı değil.');
            }
            $d['project_id'] = (int) $e['project_id'];
        } elseif (($project = self::text($e['project_name'] ?? null)) !== null) {
            $d['project_name'] = $project;
        } elseif (! empty($e['no_project'])) {
            $d['project_choice_done'] = true; // kullanıcı "genel" dedi
        }

        // ── Türe özel: belirsizlik açıkça çözülmüş olmalı ─────────────────
        $party = $d['party_id'] ? Party::find($d['party_id']) : null;
        $balance = $party ? (float) PartyStatement::build($party)['balance'] : 0.0; // − biz borçluyuz
        $purpose = in_array($e['purpose'] ?? null, self::PURPOSES, true) ? $e['purpose'] : null;

        if ($kind === ExpenseExtractor::KIND_PAYMENT) {
            if (! empty($e['agreement_id'])) {
                $d['agreement_id'] = (int) $e['agreement_id'];
                $d['agreement_choice_done'] = true;
            }
            $owed = max(0.0, -$balance);
            if ($purpose === null && empty($d['agreement_id'])) {
                if ($owed >= $amount - 0.01) {
                    $purpose = 'debt'; // kayıtlı borcu var ve ödeme ona sığıyor
                } else {
                    $issues[] = ($party ? $party->name . ' için kayıtlı borç ' . Money::format($owed) . ' ₺' : 'Bu kişi yeni, kayıtlı borç yok')
                        . '; ' . Money::format($amount) . ' ₺ ödeme bunu aşıyor. purpose ver: "advance" (önden/avans — iş/mal sonra), "loan" (borç verdim), "on_behalf" (onun adına başkasına ödedim — harç, fatura; hesabına yazılır), '
                        . '"debt" (eski/kayıtsız borcun ödemesi — o zaman ÖNCE eski borcu debt_note ile aynı öneride yaz). İş/mal şimdi alındıysa '
                        . 'payment değil kind "purchase" + paid=true. Kullanıcı söylemediyse sor.';
                }
            }
            if ($purpose === 'debt' && empty($d['agreement_id'])) {
                // Kayıtlı borcu aşan "borç ödemesi" eski yolda sessizce eksik borcu yazıyordu — ajan yolunda açıkça önerilir.
                $d['debt_repayment'] = true;
            } elseif ($purpose === 'on_behalf') {
                // Müşteri adına üçüncü kişiye ödeme (harç, fatura): o kişinin hesabına ödeme; maliyet değil, avans değil (OnBehalfChoice).
                $d = array_merge($d, \App\Services\Whatsapp\OnBehalfChoice::onBehalf(), ['on_behalf_done' => true]);
            } elseif ($purpose !== null) {
                $d['payment_purpose'] = $purpose;
                $d['advance'] = true;
            }
        }
        if ($kind === ExpenseExtractor::KIND_COLLECTION) {
            if (! empty($e['agreement_id'])) {
                $d['agreement_id'] = (int) $e['agreement_id'];
                $d['agreement_choice_done'] = true;
            }
            if ($purpose === 'advance') {
                $d['payment_purpose'] = 'advance';
            }
        }
        if (in_array($kind, [ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_PURCHASE], true) && $d['paid'] === null) {
            $issues[] = 'paid verilmedi: parası ödendi mi (true) yoksa veresiye mi (false)? Bakiyeyi değiştirir — kullanıcı söylemediyse sor.';
        }
        if ($kind === ExpenseExtractor::KIND_DEBT_NOTE) {
            if (! in_array($e['debt_side'] ?? null, ['payable', 'receivable'], true)) {
                $issues[] = 'debt_side verilmedi: "payable" (biz ona borçluyuz) ya da "receivable" (o bize borçlu).';
            }
            $d['debt_side'] = $e['debt_side'] ?? null;
            $d['work_status'] = null;
        }
        if ($kind === ExpenseExtractor::KIND_EXPENSE && $d['paid'] === null) {
            $d['paid'] = true;
        }
        if ($kind === ExpenseExtractor::KIND_SALE && is_array($e['items'] ?? null)) {
            $d['items'] = array_values(array_filter(array_map(fn ($i) => is_array($i) && (float) ($i['amount'] ?? 0) > 0
                ? ['description' => mb_substr(trim((string) ($i['description'] ?? '')), 0, 200), 'amount' => round((float) $i['amount'], 2)] : null, $e['items'])));
            if ($d['items'] !== [] && abs(array_sum(array_column($d['items'], 'amount')) - $amount) > 0.01) {
                $issues[] = 'items toplamı (' . Money::format(array_sum(array_column($d['items'], 'amount'))) . ') amount ile (' . Money::format($amount) . ') tutmuyor.';
            }
        }

        // Yeni kuraldaki personele verilen para maaş ödemesidir (en eski borçtan düşer) — eski yolla aynı adım.
        if ($kind === ExpenseExtractor::KIND_PAYMENT && $issues === []) {
            $payroll = \App\Services\Whatsapp\EmployeeDraft::fromPayment($d);
            if (\App\Services\Whatsapp\EmployeeDraft::handles($payroll)) {
                return self::finish($payroll, $phone);
            }
        }

        // ── Eski yolun kuralları: modüle uydur, kapılardan geçir ───────────
        $d = ExpenseExtractor::fitToModules($d);
        if (! in_array($d['kind'] ?? null, self::KINDS, true) && ($d['kind'] ?? null) !== ExpenseExtractor::KIND_HELP) {
            // Stoklu işletme: ürün kartı tutan satış/alış belgeye döndü — o ailenin kapıları ve kartı.
            return self::finish(ProjectChoice::prepare($d + ['source' => 'agent'], $phone), $phone, $issues);
        }
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_HELP) {
            return self::fail(trim((string) ($d['reply'] ?? 'Bu tür kayıt bu işletmede açık değil.')));
        }
        $d = ProjectChoice::prepare($d, $phone);
        if (! empty($d['debt_repayment']) && ($missing = DraftGaps::unrecordedDebt($d)) > 0) {
            $issues[] = 'Bu ödeme kayıtlı borçtan ' . Money::format($missing) . ' ₺ fazla. Eski/kayıtsız borç varsa onu debt_note (payable) olarak '
                . 'AYNI öneride, ödemeden ÖNCE ver; yoksa purpose "advance" yap.';
        }
        if ($issues === []) {
            $gap = DraftGaps::reason($d);
            if ($gap['code'] !== DraftGaps::CONFIRM) {
                $issues[] = self::plain($gap['ask']);
            } elseif ($question = ProjectChoice::blocker($d)) {
                $issues[] = self::plain($question) . ' → project_id ver, yeni yer ise project_name, kullanıcı "genel" dediyse no_project=true. '
                    . 'Mesajda yer geçmiyorsa TAHMİN ETME, sor. Yerler: '
                    . (Project::whereNotIn('status', ['cancelled'])->orderBy('id')->take(30)->get(['id', 'name'])->map(fn ($p) => $p->name . ' (id ' . $p->id . ')')->implode(', ') ?: 'henüz yok');
            }
        }

        // ── Çift kayıt: aynı kişi + aynı tutar yakın günlerde (29.10 çift ödeme) ─────
        if ($issues === [] && ($hit = DraftWarnings::moneyDuplicate($d)) && empty($e['allow_duplicate'])) {
            $issues[] = $hit->entry_date?->format('d.m.Y') . ' günü aynı kişiye aynı tutarda (' . Money::format((float) $hit->amount) . ' ₺) kayıt zaten var (ledger:'
                . $hit->id . ($hit->description ? ', "' . mb_substr((string) $hit->description, 0, 60) . '"' : '') . '). Kullanıcı aynı ödemeyi mi anlatıyor '
                . '(o zaman YAZMA, zaten kayıtlı de) yoksa ayrı, yeni bir ödeme mi? Konuşmadan belli değilse sor; ayrıysa allow_duplicate=true ile öner.';
        }
        foreach (DraftWarnings::for($d) as $w) {
            $warnings[] = self::plain($w);
        }

        return ['draft' => $issues === [] ? $d : null, 'issues' => $issues, 'warnings' => $warnings];
    }

    /**
     * Eski taslak sınıflarıyla yazılan tür (ürünlü satış/alış, kasa, stok, maaş, personel, hatırlatma, düzenli gider).
     *
     * @return array{draft: ?array, issues: list<string>, warnings: list<string>, card?: string}
     */
    public static function extended(array $e, string $phone): array
    {
        $kind = (string) ($e['kind'] ?? '');
        if (! in_array($kind, self::EXTENDED_KINDS, true)) {
            return self::fail('Bu tür burada yazılamaz: ' . $kind);
        }
        if (! in_array($kind, ExpenseExtractor::allowedKinds(), true)) {
            return self::fail('Bu işletmede "' . $kind . '" açık değil (özelliği kapalı). Ürünsüz satış/alış için sale / purchase, masraf için expense kullan.');
        }
        $issues = [];
        $raw = array_intersect_key($e, array_flip(self::EXTENDED_FIELDS)) + [
            'kind' => $kind,
            'amount' => round((float) ($e['amount'] ?? 0), 2),
            'description' => mb_strimwidth(trim((string) ($e['description'] ?? '')), 0, 200, '…'),
            'paid' => isset($e['paid']) ? (bool) $e['paid'] : null,
            'payment_type' => in_array($e['payment_type'] ?? null, self::PAYMENT_TYPES, true) ? $e['payment_type'] : null,
            'confidence' => 'high', 'work_status_known' => null, 'question' => null, 'is_new_entry' => true,
            'category_id' => ! empty($e['category_id']) ? (int) $e['category_id'] : null,
            'category_name' => self::text($e['category_name'] ?? null),
        ];
        if ($kind !== ExpenseExtractor::KIND_REMINDER) {
            if (($date = self::date($e['date'] ?? null)) === null) {
                return self::fail('date YYYY-MM-DD olmalı (söylenmediyse boş bırak, bugün yazılır).');
            }
            $raw['date'] = $date;
        }
        // Kişi: kayıtlı id ya da ad (adı birebir tutan tek kişi varsa ona bağlanır; benzer ad varsa sorulur).
        if (! empty($e['party_id'])) {
            if (! Party::whereKey((int) $e['party_id'])->exists()) {
                return self::fail('party_id ' . (int) $e['party_id'] . ' kayıtlı değil — find_party ile bul.');
            }
            $raw['party_id'] = (int) $e['party_id'];
        } elseif (($name = self::text($e['party_name'] ?? null)) !== null) {
            if ($same = DraftGaps::exactParty($name)) {
                $raw['party_id'] = $same->id;
            } else {
                if (empty($e['new_party'])) {
                    $near = collect(FindParty::run(['name' => $name])['adaylar'] ?? [])->whereIn('güç', ['güçlü', 'orta'])->take(3);
                    if ($near->isNotEmpty()) {
                        $issues[] = '"' . $name . '" adına benzer kayıtlı kişi var: ' . $near->map(fn ($r) => $r['ad'] . ' (id ' . $r['id'] . ')')->implode(', ')
                            . '. Aynı kişiyse party_id ver; farklı ve YENİ biriyse new_party=true ile öner (emin değilsen sor).';
                    }
                }
                $raw['party_name'] = $name;
            }
        }
        if (! empty($e['project_id'])) {
            if (! Project::whereKey((int) $e['project_id'])->exists()) {
                return self::fail('project_id ' . (int) $e['project_id'] . ' kayıtlı değil.');
            }
            $raw['project_id'] = (int) $e['project_id'];
        } elseif (($project = self::text($e['project_name'] ?? null)) !== null) {
            $raw['project_name'] = $project;
        }

        // Eski asistanın temizliği ve yönlendirmesi: kalemler, ürün eşleştirme, kapalı türün uygun yola çevrilmesi.
        $d = app(ExpenseExtractor::class)->normalizeEntry($raw);
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_HELP) {
            return self::fail(trim((string) ($d['reply'] ?? 'Bu kayıt bu işletmede yazılamıyor.')));
        }
        if (in_array($d['kind'] ?? null, self::KINDS, true)) {
            // Ürün kartı tutmadı / stok kapalı: düz gider/alış/satış oldu — onun kurallarıyla (ödendi mi, yer, amaç) yeniden değerlendirilir.
            return self::record(['kind' => $d['kind']] + $e, $phone);
        }
        $d += ['party_confirmed' => true, 'party_certain' => true, 'source' => 'agent'];
        if (! empty($e['no_project'])) {
            $d['project_choice_done'] = true;
        }

        return self::finish($d, $phone, $issues);
    }

    /** Eski taslak ailesi: son hazırlık + kapılar (bulgu) + kart (o ailenin kendi özeti). */
    private static function finish(array $d, string $phone, array $issues = []): array
    {
        if (\App\Services\Whatsapp\EmployeeDraft::handles($d)) {
            $d = \App\Services\Whatsapp\EmployeeDraft::withFingerprint($d); // kart = kayıt: onayda yeniden hesaplanır
        }
        $d = ProjectChoice::prepare($d, $phone);
        $reminder = null;
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_REMINDER) {
            $target = \App\Support\Reminders::target($d, $phone);
            if (! $target) {
                $d['updates_reminder_id'] = null;
            }
            if (empty($d['event_date']) && ! $target) {
                $issues[] = 'Ne zaman hatırlatılacağı yok (event_date) — müşteriye sor.';
            } else {
                $reminder = $target ? \App\Support\Reminders::updated($target, $d) : \App\Support\Reminders::fromDraft($d, $phone);
                if (! $reminder->next_fire_at) {
                    $issues[] = 'Bu zaman geçmiş görünüyor — ne zaman hatırlatılacağını sor.';
                }
            }
        }
        if ($issues === []) {
            $gap = DraftGaps::reason($d);
            if ($gap['code'] !== DraftGaps::CONFIRM) {
                $issues[] = self::plain($gap['ask']) . ' (Konuşmada/kayıtta cevabı varsa alanı doldurup yeniden öner; yoksa müşteriye sor.)';
            } elseif ($question = ProjectChoice::blocker($d)) {
                $issues[] = self::plain($question) . ' → project_id / project_name ver ya da müşteri "genel" dediyse no_project=true. Yerler: '
                    . (Project::whereNotIn('status', ['cancelled'])->orderBy('id')->take(30)->get(['id', 'name'])->map(fn ($p) => $p->name . ' (id ' . $p->id . ')')->implode(', ') ?: 'henüz yok');
            }
        }
        if ($issues !== []) {
            return ['draft' => null, 'issues' => $issues, 'warnings' => []];
        }

        $card = match (true) {
            \App\Services\Whatsapp\TradeDraft::handles($d) => \App\Services\Whatsapp\TradeDraft::summary($d),
            \App\Services\Whatsapp\EmployeeDraft::handles($d) => \App\Services\Whatsapp\EmployeeDraft::summary($d),
            \App\Services\Whatsapp\RecurringDraft::handles($d) => \App\Services\Whatsapp\RecurringDraft::summary($d),
            $reminder !== null => isset($target) && $target ? \App\Support\Reminders::changeSummary($target, $reminder) : \App\Support\Reminders::summary($reminder),
            default => DraftGaps::label($d),
        };
        if ($lines = ProjectChoice::lines($d)) {
            $lines = array_filter($lines, fn ($l) => ! str_contains($l, 'Hangi')); // menü satırı değil, yalnız seçilen yer
            $card .= $lines ? "\n" . implode("\n", $lines) : '';
        }

        return ['draft' => $d, 'issues' => [], 'warnings' => array_map([self::class, 'plain'], DraftWarnings::for($d)), 'card' => trim($card)];
    }

    private static function fail(string $issue): array
    {
        return ['draft' => null, 'issues' => [$issue], 'warnings' => []];
    }

    /** Geçerli tarih ya da null; boşsa $default (varsayılan: bugün, İstanbul). */
    private static function date(mixed $v, ?string $default = 'today'): ?string
    {
        if ($v === null || $v === '') {
            return $default === 'today' ? now('Europe/Istanbul')->toDateString() : $default;
        }
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $v)->toDateString() === $v ? $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v !== '' ? mb_substr($v, 0, 120) : null;
    }

    /** WhatsApp biçim işaretlerini at (ajana düz cümle). */
    private static function plain(string $text): string
    {
        return trim(str_replace(['*', '⚠️', '❓'], '', $text));
    }
}
