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
        ExpenseExtractor::KIND_AGREEMENT, ExpenseExtractor::KIND_AGREEMENT_DELIVERY,
        ExpenseExtractor::KIND_PRODUCT_RENAME, ExpenseExtractor::KIND_FORGET,
    ];

    /** Bu türlerde ajanın doldurabildiği eski şema alanları (tanımları ExpenseExtractor şemasından aynen alınır). */
    public const EXTENDED_FIELDS = [
        'items', 'cash_amount', 'card_amount', 'sale_paid_said', 'product_id', 'product_name', 'unit', 'counted_quantity', 'stock_items',
        'daily_usage', 'reorder_level', 'purchase_price', 'salary_amount', 'salary_day', 'salary_deduction', 'first_salary', 'first_salary_amount',
        'is_staff', 'month', 'event_date', 'event_time', 'lead_minutes', 'is_alarm', 'notify_time', 'updates_reminder_id', 'repeat',
        'recurring_frequency', 'recurring_day', 'recurring_time', 'recurring_start',
        'agreement_direction', 'agreement_items', 'agreement_payments', 'agreement_notes', 'remaining_only', 'document_total', 'has_payments',
        'delivery_lines', 'delivery_candidates', 'checks', 'check_plan', 'memory_subject',
        'goods_name', 'unnamed_payee', 'payee_label', 'payee_party_id', 'future_payment',
    ];

    /** Anlaşma kartının kararları — müşteri cevapladıysa ajan bunlarla bildirir (eski yolda numaralı cevaplardı). */
    public const AGREEMENT_DECISIONS = ['total_ok', 'payments_skipped', 'replace', 'past_checks'];

    public const PAYMENT_TYPES = ['cash', 'eft', 'bank_transfer', 'check', 'promissory_note', 'other'];

    public const PURPOSES = ['debt', 'advance', 'loan', 'on_behalf'];

    /**
     * @return array{draft: ?array, issues: list<string>, warnings: list<string>}
     */
    public static function record(array $e, string $phone, string $text = ''): array
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

        // ── Eski yolun emniyetleri: kaydı sessizce çevirmek yerine ajana bulgu olarak döner ──
        if (($stop = self::guards($d, $e, $phone, $text)) !== null) {
            return self::fail($stop);
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

        // Anlaşması olan kişiye para: hangi anlaşmaya sayılacağı her seferinde sorulur (kullanıcı 26.10); proje anlaşmadan gelir.
        if (in_array($kind, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_COLLECTION], true) && $party) {
            if (! empty($e['agreement_id'])) {
                $agreement = \App\Models\Agreement::where('party_id', $party->id)->whereKey((int) $e['agreement_id'])->first();
                if (! $agreement) {
                    return self::fail('agreement_id ' . (int) $e['agreement_id'] . ' bu kişinin anlaşması değil — party_overview → anlaşmalar.');
                }
                if (empty($d['project_id']) && empty($d['project_name']) && $agreement->project_id) {
                    $d['project_id'] = (int) $agreement->project_id;
                }
                // Kartta "sana borcu" görünür ama iş daha gelmedi: neden öyle göründüğü kartta söylensin (müşteri itiraz etmesin).
                if (\App\Support\AgreementBalances::balance($agreement) < $amount - 0.01) {
                    $warnings[] = 'Bu para "' . mb_strimwidth((string) $agreement->title, 0, 40, '…') . '" anlaşmasına önden yazılır; iş/mal geldikçe ondan düşer, ayrı borç doğmaz.';
                }
            } elseif (! empty($e['no_agreement'])) {
                $d['agreement_choice_done'] = true;
            } elseif ($purpose !== 'on_behalf' && \App\Services\Whatsapp\PaymentAgreementChoice::applies($d)) {
                $type = $kind === ExpenseExtractor::KIND_PAYMENT ? \App\Models\PartyLedgerEntry::TYPE_PAYMENT : \App\Models\PartyLedgerEntry::TYPE_COLLECTION;
                $options = collect(\App\Support\AgreementBalances::choices($party, $type))
                    ->map(fn ($c) => $c['label'] . ($c['id'] ? ' (agreement_id ' . $c['id'] . ')' : ' (anlaşma dışı → no_agreement=true)'))->implode(' · ');

                return self::fail($party->name . ' ile açık anlaşma var; bu para hangisine sayılacak? ' . $options
                    . '. Müşteri söylemediyse sor, seçimine göre yeniden öner.');
            }
        }

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
            } elseif (! empty($d['agreement_id'])) {
                $d = array_merge($d, ['payment_purpose' => null, 'advance' => false]); // anlaşmanın parası: avans değil, anlaşmaya yazılır
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
        // ── Çek: tek tek yazılan çekler (checks) ya da kural ("3 çek, aylık" → check_plan; bölmeyi KOD yapar) ──
        if ((! empty($e['checks']) || ! empty($e['check_plan']))
            && in_array($kind, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_COLLECTION, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_SALE], true)) {
            $u = \App\Services\Whatsapp\Understanding::normalize(['checks' => $e['checks'] ?? null, 'check_plan' => $e['check_plan'] ?? null]);
            $d = \App\Services\Whatsapp\CheckSplit::prepare(['checks' => $u['checks'], 'check_plan' => $u['check_plan'], 'check_direction_known' => true,
                'past_ok' => ! empty($e['past_ok'])] + $d, null, $text);
            foreach (\App\Services\Whatsapp\Understanding::checkWarnings($d) as $w) {
                $warnings[] = self::plain($w);
            }
            if (($question = \App\Services\Whatsapp\CheckSplit::question($d)) !== null) {
                $issues[] = self::plain($question) . ' (Çek tutarlarını/vadelerini düzeltip yeniden öner ya da müşteriye sor.'
                    . (($d['check_choice'] ?? null) === 'past' ? ' Müşteri eski çek olduğunu söylediyse past_ok=true.' : '') . ')';
            } elseif (($note = \App\Services\Whatsapp\Understanding::checksNote($d)) && ! str_contains($d['description'], $note)) {
                $d['description'] = mb_strimwidth(trim($d['description'] . ' — ' . $note, ' —'), 0, 200, '…');
            }
            $d['payment_type'] = 'check';
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
        // Adsız usta (kullanıcı 30.10): işi yapan kayıtlı kişi varsa sorulur; yoksa 100 bin ve üstü meslek adıyla kişi, altı gider.
        if ($kind === ExpenseExtractor::KIND_EXPENSE && ! empty($e['unnamed_payee']) && self::text($e['payee_label'] ?? null) !== null) {
            $u = ExpenseExtractor::unnamedPayeeToParty(['unnamed_payee' => true, 'payee_label' => $e['payee_label'],
                'payee_party_id' => $e['payee_party_id'] ?? null] + $d, ! empty($e['payee_answered']));
            if (($u['kind'] ?? null) === ExpenseExtractor::KIND_PAYMENT) {
                return self::record(['kind' => ExpenseExtractor::KIND_PAYMENT, 'party_id' => $u['party_id'] ?? null, 'party_name' => $u['party_name'] ?? null,
                    'new_party' => true, 'unnamed_payee' => null] + $e, $phone, $text);
            }
            if (! empty($u['ask_party_id'])) {
                return self::fail(self::plain((string) $u['question']) . ' Bu kişiye gittiyse kind=payment, party_id=' . (int) $u['ask_party_id']
                    . '; ayrı bir giderse payee_answered=true. Müşteriye sor.');
            }
            $d = array_merge($d, ['unnamed_payee' => false, 'question' => null]);
        }
        // Stoklu işletmede kartı olmayan tek bir mal için para: mal elde mi, satıcıda mı, stok tutuluyor mu (kullanıcı 21.10).
        if (! empty($e['goods_name'])) {
            $d['goods_name'] = mb_substr(trim((string) $e['goods_name']), 0, 80);
            if (\App\Services\Whatsapp\StockChoice::applies($d)) {
                if (empty($e['stock_done'])) {
                    return self::fail('"' . $d['goods_name'] . '" stokta tutulan bir mal olabilir ve ürün kartı yok. Mal geldiyse kind=trade_purchase (items: description, quantity, unit; '
                        . 'product_id null — sistem kartı açar). Satıcıda duruyorsa payment + purpose=advance. Eski borçsa payment + purpose=debt. Stok tutulmuyorsa stock_done=true. Müşteriye sor.');
                }
                $d = \App\Services\Whatsapp\StockChoice::answer(['stock_choice' => true] + $d, '0')['data'] ?? $d;
            }
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
        // Önceden verilen/alınan para varsa iş gelince ondan düşülür; para ikinci kez yazılmaz (Erkan 12-13.10).
        $d = \App\Services\Whatsapp\AdvanceOffset::apply($d, $text);
        if (($used = \App\Services\Whatsapp\AdvanceOffset::used($d)) > 0 && ! empty($d['paid'])) {
            $warnings[] = Money::format($used) . ' ₺ önceden verilen paradan düşülüyor; yalnız kalanı yeni para sayılır.';
        }
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
    public static function extended(array $e, string $phone, string $text = ''): array
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
            return self::record(['kind' => $d['kind']] + $e, $phone, $text);
        }
        $d = ExpenseExtractor::guardNightTill($d, $text); // gece 00–05 girilen kasa biten iş gününe yazılır (kullanıcı 19.10)
        $d = ['party_confirmed' => true, 'party_certain' => true, 'source' => 'agent'] + $d;
        if (! empty($e['no_project'])) {
            $d['project_choice_done'] = true;
        }
        if (\App\Services\Whatsapp\AgreementDraft::handles($d)) {
            $d = \App\Services\Whatsapp\AgreementDraft::applyCheckPlan(\App\Services\Whatsapp\AgreementDraft::applyRemaining($d));
            // Müşterinin verdiği kararlar (eski yolda numaralı cevaplar): toplam teyidi, ödemesiz geç, eskisinin yerine / ayrı, geçmiş vadeli çekler.
            if (! empty($e['total_ok'])) {
                $d['total_ok'] = true;
            }
            if (! empty($e['payments_skipped'])) {
                $d['payments_skipped'] = true;
            }
            if (in_array($e['replace'] ?? null, ['replace', 'separate'], true)) {
                $d = \App\Services\Whatsapp\AgreementDraft::chooseReplace($d, $e['replace'] === 'replace' ? 1 : 2) ?? $d;
            }
            if (in_array($e['past_checks'] ?? null, ['paid', 'pending'], true)) {
                $d['past_checks'] = $e['past_checks'];
            }
            if (in_array($e['agreement_direction'] ?? null, ['purchase', 'sale'], true)) {
                $d = \App\Services\Whatsapp\AgreementDraft::chooseDirection($d, $e['agreement_direction']);
            }
        }
        if (\App\Services\Whatsapp\AgreementDeliveryDraft::handles($d)) {
            $d = \App\Services\Whatsapp\AgreementDeliveryDraft::prepare($d);
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
        if (\App\Services\Whatsapp\MemoryDraft::handles($d) && ($blocker = \App\Services\Whatsapp\MemoryDraft::blocker($d, $phone)) !== null) {
            $issues[] = self::plain($blocker);
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
            \App\Services\Whatsapp\AgreementDraft::handles($d) => \App\Services\Whatsapp\AgreementDraft::summary($d),
            \App\Services\Whatsapp\AgreementDeliveryDraft::handles($d) => \App\Services\Whatsapp\AgreementDeliveryDraft::summary($d),
            \App\Services\Whatsapp\TradeDraft::handles($d) => \App\Services\Whatsapp\TradeDraft::summary($d),
            \App\Services\Whatsapp\EmployeeDraft::handles($d) => \App\Services\Whatsapp\EmployeeDraft::summary($d),
            \App\Services\Whatsapp\RecurringDraft::handles($d) => \App\Services\Whatsapp\RecurringDraft::summary($d),
            \App\Services\Whatsapp\MemoryDraft::handles($d) => \App\Services\Whatsapp\MemoryDraft::summary($d, $phone),
            $reminder !== null => isset($target) && $target ? \App\Support\Reminders::changeSummary($target, $reminder) : \App\Support\Reminders::summary($reminder),
            default => DraftGaps::label($d),
        };
        if ($lines = ProjectChoice::lines($d)) {
            $lines = array_filter($lines, fn ($l) => ! str_contains($l, 'Hangi')); // menü satırı değil, yalnız seçilen yer
            $card .= $lines ? "\n" . implode("\n", $lines) : '';
        }

        return ['draft' => $d, 'issues' => [], 'warnings' => array_map([self::class, 'plain'], DraftWarnings::for($d)), 'card' => trim($card)];
    }

    /** Eski yolun kayıt öncesi emniyetleri. Kaydı sessizce değiştirmez: tetiklenirse ajanın düzelteceği/soracağı cümle döner. */
    private static function guards(array $d, array $e, string $phone, string $text): ?string
    {
        $kind = $d['kind'];
        $self = ExpenseExtractor::guardSelfParty($d, \App\Services\Whatsapp\ExpenseContext::selfNames($phone), $text);
        if (($self['kind'] ?? null) !== $kind || ($self['party_id'] ?? null) !== $d['party_id'] || ($self['party_name'] ?? null) !== $d['party_name']) {
            return $self['party_id'] === null && $self['party_name'] === null && ($d['party_id'] || $d['party_name'])
                ? 'Bu ad müşterinin KENDİ adı/firması; kendisi kişi olamaz. ' . self::plain((string) ($self['question'] ?? 'Karşı tarafı sor.'))
                : 'Mesaj paranın müşterinin hesabına GELDİĞİNİ söylüyor: bu kind=collection olmalı.';
        }
        if (($changed = ExpenseExtractor::guardUndeliveredSale($d, $text)) !== $d && ($changed['kind'] ?? null) !== $kind) {
            return 'Mesaj malın henüz teslim edilmediğini söylüyor: satış değil, kind=collection + purpose=advance (önden alınan para). Teslim edilince satış yazılır.';
        }
        if ($kind === ExpenseExtractor::KIND_DEBT_NOTE) {
            // "742 bin borcum VARDI, 120 bin ödedim": eski borç + ödeme birlikte anlatılıyor — borç kaydı doğrudur.
            $stated = preg_match('/bor[cç]\p{L}* var/u', mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8')) === 1;
            if (! $stated && ExpenseExtractor::guardDebtRepayment(['debt_side' => $e['debt_side'] ?? null] + $d, $text)['kind'] !== $kind) {
                return 'Mesaj borcun ÖDENDİĞİNİ söylüyor: borç kaydı değil, kind=payment + purpose=debt.';
            }
            $future = ! empty($e['future_payment']) || preg_match('/(verece[gğk]|[oö]deyece[gğk]|verice[mk]|[oö]deyice[mk])/u', mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı']), 'UTF-8')) === 1;
            if ($future && ($reminder = ExpenseExtractor::futurePaymentReminder(['future_payment' => true, 'debt_side' => $e['debt_side'] ?? null] + $d)) !== null) {
                return self::plain($reminder[1]) . ' Yeni borç YAZMA. Ödeme günü söylendiyse kind=reminder öner; söylenmediyse borcun zaten kayıtlı olduğunu söyle.';
            }
        }
        if ($kind === ExpenseExtractor::KIND_PURCHASE && empty($e['purchase_confirmed']) && \App\Services\Whatsapp\OnBehalfChoice::applies($d)) {
            return (DraftGaps::party($d)?->name ?? 'Bu kişi') . ' şimdiye kadar yalnız müşterin olmuş, ondan hiç alış yok. Onun ADINA başkasına mı ödedin (kind=payment, purpose=on_behalf), '
                . 'yoksa gerçekten ondan mal/hizmet mi aldın (purchase_confirmed=true)? Konuşmadan belli değilse sor.';
        }

        return null;
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
