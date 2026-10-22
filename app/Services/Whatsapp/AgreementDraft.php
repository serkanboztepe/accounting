<?php

namespace App\Services\Whatsapp;

use App\Models\Agreement;
use App\Models\Party;
use App\Models\Product;
use App\Support\AgreementDesk;
use App\Support\Forms\AgreementForm;
use App\Support\Money;
use Carbon\Carbon;

/**
 * WhatsApp'tan anlaşma (Faz 6, 21.10): "Ali Usta'yla tesisatı 1 milyona anlaştık" ya da sözleşme / sipariş / fiyat listesi fotoğrafı
 * → kalem kalem anlaşma + ödeme planı. Borç yazmaz; ödemeler cariye normal ödeme olarak (çek/senet vadeli).
 * Kullanıcı kuralları (21.10): okunamayan yer TAHMİN EDİLMEZ, sorulur ("şurayı tam okuyamadım"); devir/takas nakit ödeme sayılır.
 * Ürün kartı ve yeni cari onaydan SONRA açılır (taslakta yalnız ad).
 */
class AgreementDraft
{
    /** "geç" — okunamayan yeri boş ver (sorulan yer önemli değil). */
    public const SKIP_WORDS = ['geç', 'gec', 'geç onu', 'boşver', 'boş ver', 'önemli değil', 'onemli degil', 'atla'];

    /** "not" — okunamayan yeri olduğu gibi anlaşmanın notuna yaz (kullanıcı 22.10: "geç dedim de üşendiğimden; notu kaydet olsa öyle yapardım"). */
    public const NOTE_WORDS = ['not', 'nota yaz', 'not al', 'notla', 'nota ekle', 'not et'];

    private const METHODS = ['cash' => 'nakit', 'eft' => 'EFT', 'check' => 'çek', 'promissory_note' => 'senet'];

    private const MAX_CARD_ITEMS = 6;

    public static function handles(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_AGREEMENT;
    }

    /** AI çıktısı → güvenli alanlar (normalizeRaw'dan çağrılır). */
    public static function sanitize(array $input): array
    {
        $items = [];
        foreach ((array) ($input['agreement_items'] ?? []) as $row) {
            $name = trim((string) ($row['description'] ?? ''));
            if ($name === '') {
                continue;
            }
            $num = fn ($v) => is_numeric($v) && (float) $v > 0 ? round((float) $v, 2) : null;
            $items[] = [
                'description' => mb_substr($name, 0, 120),
                'product_id'  => ! empty($row['product_id']) ? (int) $row['product_id'] : null,
                'quantity'    => $num($row['quantity'] ?? null),
                'unit'        => is_string($row['unit'] ?? null) && trim($row['unit']) !== '' ? mb_substr(trim($row['unit']), 0, 20) : null,
                'unit_price'  => $num($row['unit_price'] ?? null),
                'amount'      => $num($row['amount'] ?? null),
                'free'        => ! empty($row['free']),
            ];
        }

        $payments = [];
        foreach ((array) ($input['agreement_payments'] ?? []) as $row) {
            $amount = (float) ($row['amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $method = array_key_exists($row['method'] ?? '', self::METHODS) ? $row['method'] : 'cash';
            $paid = self::date($row['paid_date'] ?? null);
            $due = self::date($row['due_date'] ?? null);
            // Çek/senet yanında tek tarih = vadesi (Alim Tok 21.10: 15.07 / 20.08 "verildiği gün" sanıldı, vade bugüne düştü).
            if (self::isDeferred($method) && $due === null && $paid !== null) {
                [$due, $paid] = [$paid, null];
            }
            $payments[] = [
                'method'       => $method,
                'amount'       => round($amount, 2),
                'paid_date'    => $paid,
                'due_date'     => $due,
                'check_number' => is_string($row['check_number'] ?? null) && trim($row['check_number']) !== '' ? mb_substr(trim($row['check_number']), 0, 50) : null,
            ];
        }

        return [
            'agreement_direction' => ($input['agreement_direction'] ?? null) === Agreement::DIRECTION_SALE ? Agreement::DIRECTION_SALE : Agreement::DIRECTION_PURCHASE,
            'agreement_items'     => $items,
            'agreement_payments'  => $payments,
            // Belgede ödeme geçiyor mu (tutar okunamasa da) — liste boş kalırsa sorulur (Seramikpark 22.10: ödemeler sessizce düştü).
            'has_payments'        => ! empty($input['has_payments']),
            // Belgenin altındaki genel toplam — kalemlerin toplamıyla sağlama (22.10: KDV hariç/dahil iki sütunlu belge).
            'document_total'      => is_numeric($input['document_total'] ?? null) && (float) $input['document_total'] > 0
                ? round((float) $input['document_total'], 2) : null,
            // Belgedeki önemli şartlar (geçerlilik, fiyata dahil olanlar) — anlaşmanın notu.
            'agreement_notes'     => is_string($input['agreement_notes'] ?? null) && trim($input['agreement_notes']) !== ''
                ? mb_substr(trim($input['agreement_notes']), 0, 300) : null,
            // Karşı tarafın belgedeki telefonu / adresi — kayıttan sonra "cari kartına yazayım mı?" (PartyInfoDraft).
            'party_phone'         => is_string($input['party_phone'] ?? null) && preg_match('/\d{7,}/', preg_replace('/\D/', '', $input['party_phone']))
                ? mb_substr(trim($input['party_phone']), 0, 30) : null,
            'party_address'       => is_string($input['party_address'] ?? null) && trim($input['party_address']) !== ''
                ? mb_substr(trim($input['party_address']), 0, 150) : null,
            // Açık anlaşma kartına gelen sayfa: aynı belgenin devamı mı (true / false / null = emin değil).
            'continues_agreement' => isset($input['continues_agreement']) && is_bool($input['continues_agreement']) ? $input['continues_agreement'] : null,
            // Birlikte gönderilen sayfalar aynı belge mi (çok görselli okuma).
            'pages_related'       => isset($input['pages_related']) && is_bool($input['pages_related']) ? $input['pages_related'] : null,
            // En fazla 3 soru (kullanıcı 21.10: "o kadar ayrıntıya giremeyiz") — AI en önemlisini önce yazar.
            'unreadable'          => array_slice(array_values(array_filter(array_map(fn ($u) => is_string($u) ? mb_substr(trim($u), 0, 200) : '',
                (array) ($input['unreadable'] ?? [])))), 0, 3),
        ];
    }

    /**
     * Kaydetmeden önce sorulacak (kişi, eşleşme, kalem, fiyatı eksik kalem, okunamayan ödeme, okunamayan yer, vadesiz çek).
     * null = kart onaya hazır.
     */
    public static function question(array $d): ?string
    {
        if (empty($d['party_id']) && trim((string) ($d['party_name'] ?? '')) === '') {
            return 'Kiminle anlaştın? Kişinin ya da firmanın adını yaz.';
        }
        if (self::partyUnclear($d)) {
            return 'Belgede *' . trim((string) $d['party_said']) . '* yazıyor — carilerindeki *' . Party::find($d['party_id'])?->name
                . '* mi? *evet* ya da doğru adı yaz.';
        }
        $items = $d['agreement_items'] ?? [];
        if ($items === []) {
            return 'Ne için anlaştınız? Kalemleri fiyatıyla yaz (ör. "500 m³ beton 3.800\'den", "tesisat götürü 1 milyon").';
        }
        foreach ($items as $item) {
            if (! $item['free'] && $item['unit_price'] === null && $item['amount'] === null) {
                return '"' . $item['description'] . '" için fiyat ya da tutar ne? Yazar mısın?';
            }
        }
        if ($gap = self::totalGap($d)) {
            return self::totalQuestion($d, $gap);
        }
        // Çek planı yazıyla ("şubattan itibaren 11 çek", firma 7 22.10 — cevap anılmadan aynı soru tekrarlandı): bölme kodda.
        if (($d['check_choice'] ?? null) === 'split') {
            return CheckSplit::question(['check_choice' => 'split', 'check_plan' => $d['check_plan'], 'amount' => $d['check_base']]);
        }
        if (($d['check_choice'] ?? null) === 'amount') {
            return $d['check_plan']['count'] . ' çekin toplam tutarı ne? (ya da her biri kaç lira)';
        }
        if (self::paymentsMissing($d)) {
            return 'Belgede ödeme var ama tutarlarını çıkaramadım. Ne kadar, nasıl ödendi? (ör. "160 bin nakit, 3 senet 200\'er bin, '
                . 'vadeleri 30.07, 30.10, 30.12") Ödeme yoksa *geç* yaz.';
        }
        if (! empty($d['unreadable'])) {
            return "Şurayı tam okuyamadım:\n• " . implode("\n• ", $d['unreadable'])
                . "\nDoğrusunu yazar mısın? Önemli değilse *geç*, olduğu gibi nota yazayım dersen *not* yaz.";
        }
        if ($p = self::undated($d)) {
            return Money::format($p['amount']) . ' ₺ ' . self::METHODS[$p['method']] . 'in vadesi ne? Tarihini yaz. Bilmiyorsan *geç* yaz '
                . '(anlaşma tarihi yazılır).';
        }

        return null;
    }

    /**
     * Belge adı carinin adından farklı ve AI eşleşmeden emin değil ("Hüseyin Yılmaz" → Seramikpark, 22.10) — bir kez sorulur;
     * *evet* (party_confirmed) ya da doğru ad.
     */
    public static function partyUnclear(array $d): bool
    {
        return ! empty($d['party_id']) && ($d['party_certain'] ?? null) === false && empty($d['party_confirmed'])
            && trim((string) ($d['party_said'] ?? '')) !== '';
    }

    /** KDV oranları — belge toplamı / kalem toplamı bunlardan birine denk gelirse "fiyatlara KDV ekle" seçeneği. */
    private const VAT_RATES = [0.20, 0.10, 0.01];

    /**
     * Sağlama (kullanıcı 22.10): belgenin altındaki genel toplam ile kalemlerin toplamı %1'den fazla tutmuyorsa
     * ['sum', 'doc', 'rate'] — rate: fark tam bir KDV oranıysa (1.187.820 × 1,20 = 1.425.384) o oran, değilse null.
     * Tutuyorsa, belgede toplam yoksa, fiyat listesiyse ya da kullanıcı "kalemler doğru" dediyse null.
     */
    public static function totalGap(array $d): ?array
    {
        $doc = (float) ($d['document_total'] ?? 0);
        $sum = self::agreedTotal($d['agreement_items'] ?? []);
        if ($doc <= 0 || $sum <= 0 || ! empty($d['total_ok']) || abs($doc - $sum) <= $doc * 0.01) {
            return null;
        }
        $rate = null;
        foreach (self::VAT_RATES as $r) {
            if (abs($doc / $sum - (1 + $r)) < 0.002) {
                $rate = $r;
                break;
            }
        }

        return ['sum' => $sum, 'doc' => $doc, 'rate' => $rate];
    }

    private static function totalQuestion(array $d, array $gap): string
    {
        $head = 'Kalemleri topladım ' . Money::format($gap['sum']) . ', belgenin altında ' . Money::format($gap['doc']) . ' yazıyor';
        if ($gap['rate'] === null) {
            return $head . ' — bir satırı yanlış okumuş olabilirim. Doğru rakamı yazar mısın? Kalemler doğruysa *evet*.';
        }
        $first = collect($d['agreement_items'])->first(fn ($i) => ! $i['free'] && $i['unit_price'] !== null);
        $example = $first ? $first['description'] . ' ' . Money::format($first['unit_price']) . ' → '
            . Money::format(round($first['unit_price'] * (1 + $gap['rate']), 2)) . (count($d['agreement_items']) > 1 ? ' …' : '') . ', ' : '';

        return $head . ' — fark tam %' . (int) round($gap['rate'] * 100) . " KDV.\n"
            . '*1* Fiyatlara KDV ekle (' . $example . 'toplam ' . Money::format($gap['doc']) . ")\n"
            . '*2* Kalemler doğru, ' . Money::format($gap['sum']) . ' kalsın';
    }

    /** KDV seçeneği gösterildi mi (numara cevabı bekleniyor). */
    public static function awaitsTotalChoice(array $d): bool
    {
        return ($gap = self::totalGap($d)) !== null && $gap['rate'] !== null && self::question($d) === self::totalQuestion($d, $gap);
    }

    /** *1* fiyatlar × (1 + KDV) · *2* kalemler doğru; geçersizse null. */
    public static function chooseTotal(array $d, int $n): ?array
    {
        $gap = self::totalGap($d);
        if ($gap === null || $gap['rate'] === null || ! in_array($n, [1, 2], true)) {
            return null;
        }
        if ($n === 2) {
            return ['total_ok' => true] + $d;
        }
        $k = 1 + $gap['rate'];
        foreach ($d['agreement_items'] as $i => $item) {
            foreach (['unit_price', 'amount'] as $f) {
                if ($item[$f] !== null) {
                    $d['agreement_items'][$i][$f] = round($item[$f] * $k, 2);
                }
            }
        }

        return ['total_ok' => true] + $d;
    }

    /** Toplam uyuşmazlığı (KDV'siz) — *evet* "kalemler doğru" demek. */
    public static function totalUnclear(array $d): bool
    {
        return ($gap = self::totalGap($d)) !== null && $gap['rate'] === null && ! self::partyUnclear($d);
    }

    /**
     * Yazıyla çek planı (`check_plan`: kaç çek, ilk ay, gün, "100'er") → çek ödemeleri. Taban: anlaşılan toplam − diğer ödemeler.
     * Eşit bölüm küsuratlıysa numaralı seçenek (check_choice=split, CheckSplit::options); toplam yoksa (fiyat listesi) tutar sorulur.
     */
    public static function applyCheckPlan(array $d): array
    {
        $d['check_choice'] = null;
        $plan = CheckSplit::normalize($d['check_plan'] ?? null);
        $d['check_plan'] = $plan;
        // Toplam sağlaması bitmeden bölünmez (taban yanlış olur) — plan bekler.
        if ($plan === null || self::totalGap($d) !== null) {
            return $d;
        }
        $other = array_values(array_filter($d['agreement_payments'] ?? [], fn ($p) => $p['method'] !== 'check'));
        $base = round(self::agreedTotal($d['agreement_items'] ?? []) - array_sum(array_column($other, 'amount')), 2);
        if ($base <= 0 && $plan['each_amount'] !== null) {
            $base = round($plan['each_amount'] * $plan['count'], 2);
        }
        if ($base <= 0) {
            $d['check_choice'] = 'amount';

            return $d;
        }
        if ($plan['each_amount'] === null && count(CheckSplit::options($base, $plan['count'])) > 1) {
            $d['check_choice'] = 'split';
            $d['check_base'] = $base;

            return $d;
        }
        $checks = array_map(fn ($c) => ['method' => 'check', 'amount' => (float) $c['amount'], 'paid_date' => null,
            'due_date' => $c['due_date_text'] ? Carbon::createFromFormat('d.m.Y', $c['due_date_text'])->toDateString() : null,
            'check_number' => null], CheckSplit::build($base, $plan));
        $d['agreement_payments'] = [...$other, ...$checks];
        $d['check_plan'] = null; // uygulandı — sonraki turda yeniden bölünmez

        return $d;
    }

    /** Bölme seçeneğine numara cevabı → çekler; geçersizse null. */
    public static function chooseSplit(array $d, int $n): ?array
    {
        $chosen = CheckSplit::choose(['check_choice' => 'split', 'check_plan' => $d['check_plan'], 'amount' => $d['check_base'] ?? 0], $n);

        return $chosen === null ? null : self::applyCheckPlan(['check_plan' => $chosen['check_plan']] + $d);
    }

    public static function awaitsSplit(array $d): bool
    {
        return ($d['check_choice'] ?? null) === 'split';
    }

    /** "geç" ile atlanabilen soru mu (okunamayan yer / okunamayan ödeme / vadesiz çek). */
    public static function skippable(array $d): bool
    {
        return ! self::partyUnclear($d) && (! empty($d['unreadable']) || self::paymentsMissing($d) || self::undated($d) !== null
            || ($d['check_choice'] ?? null) === 'amount');
    }

    private static function paymentsMissing(array $d): bool
    {
        return ! empty($d['has_payments']) && empty($d['payments_skipped']) && empty($d['agreement_payments']);
    }

    private static function undated(array $d): ?array
    {
        foreach ($d['agreement_payments'] ?? [] as $p) {
            if (self::isDeferred($p['method']) && empty($p['due_date'])) {
                return $p;
            }
        }

        return null;
    }

    /**
     * İleri tarihli nakit / EFT = ödeme PLANI, ödeme değil (Kamuran 22.10: "30.05.2027 … 600 bin nakit" × 9 — bugünkü kod 5,4 milyon
     * "ödendi" yazardı). Kayıtta cariye yazılmaz; her tarih için hatırlatma kurulur. Çek/senet hariç (vadeli çek = verilmiş çek).
     */
    public static function isPlanned(array $p): bool
    {
        $date = $p['paid_date'] ?? $p['due_date'] ?? null;

        return ! self::isDeferred($p['method']) && $date !== null && $date > now()->toDateString();
    }

    /** @return array{0: list<array>, 1: list<array>} [şimdi yazılacak ödemeler, planlı (hatırlatma)] */
    public static function splitPlanned(array $payments): array
    {
        $planned = array_values(array_filter($payments, fn ($p) => self::isPlanned($p)));

        return [array_values(array_filter($payments, fn ($p) => ! self::isPlanned($p))), $planned];
    }

    /**
     * Açık karta aynı belgenin yeni sayfası: kalemler eklenir (aynı adlı kalem yeni sayfadakiyle güncellenir), ödemeler eklenir
     * (aynısı iki kez yazılmaz), notlar birleşir, kişi/tarih/belge toplamı boşsa yeni sayfadan; okunamayan sorular yeni sayfanınki.
     */
    public static function appendPage(array $d, array $page): array
    {
        $key = fn (array $i) => mb_strtolower(trim($i['description']));
        $items = [];
        foreach ([...($d['agreement_items'] ?? []), ...($page['agreement_items'] ?? [])] as $item) {
            $items[$key($item)] = array_merge($items[$key($item)] ?? [], array_filter($item, fn ($v) => $v !== null));
        }
        $payKey = fn (array $p) => $p['method'] . '|' . $p['amount'] . '|' . ($p['paid_date'] ?? '') . '|' . ($p['due_date'] ?? '');
        $payments = [];
        foreach ([...($d['agreement_payments'] ?? []), ...($page['agreement_payments'] ?? [])] as $p) {
            $payments[$payKey($p)] ??= $p;
        }
        $notes = array_unique(array_filter([trim((string) ($d['agreement_notes'] ?? '')), trim((string) ($page['agreement_notes'] ?? ''))]));
        $hasParty = ! empty($d['party_id']) || trim((string) ($d['party_name'] ?? '')) !== '';

        return array_merge($d, [
            'agreement_items'    => array_values(array_map(fn ($i) => $i + ['product_id' => null, 'quantity' => null, 'unit' => null,
                'unit_price' => null, 'amount' => null, 'free' => false], $items)),
            'agreement_payments' => array_values($payments),
            'agreement_notes'    => $notes ? mb_substr(implode("\n", $notes), 0, 600) : null,
            'has_payments'       => ! empty($d['has_payments']) || ! empty($page['has_payments']),
            'document_total'     => $d['document_total'] ?? $page['document_total'] ?? null,
            'party_id'           => $hasParty ? ($d['party_id'] ?? null) : ($page['party_id'] ?? null),
            'party_name'         => $hasParty ? ($d['party_name'] ?? null) : ($page['party_name'] ?? null),
            'party_phone'        => $d['party_phone'] ?? $page['party_phone'] ?? null,
            'party_address'      => $d['party_address'] ?? $page['party_address'] ?? null,
            'check_plan'         => $page['check_plan'] ?? $d['check_plan'] ?? null,
            'unreadable'         => $page['unreadable'] ?? [],
            'pages'              => (int) ($d['pages'] ?? 1) + 1,
            'question'           => null,
        ]);
    }

    public static function partyLabel(array $d): string
    {
        return ! empty($d['party_id']) && ($p = Party::find($d['party_id'])) ? $p->name : trim((string) ($d['party_name'] ?? ''));
    }

    /** "not" cevabı: okunamayan maddeler olduğu gibi anlaşma notuna, sonra "geç" gibi. */
    public static function noteUnreadable(array $d): array
    {
        $d['agreement_notes'] = trim(trim((string) ($d['agreement_notes'] ?? '')) . "\n" . implode("\n", $d['unreadable'] ?? []));

        return self::skipUnreadable($d);
    }

    private static function isDeferred(string $method): bool
    {
        return in_array($method, ['check', 'promissory_note'], true);
    }

    /**
     * Düzeltme turu: AI yalnız değişen kalemi döndürebilir ("çimento torba") — tam liste değilse öncekiler korunur, aynı adlı kalem
     * güncellenir, yenisi eklenir. Tam liste (en az önceki kadar) gelirse o geçerli.
     */
    public static function mergeItems(array $previous, array $new): array
    {
        if ($new === [] || count($new) >= count($previous)) {
            return $new === [] ? $previous : $new;
        }
        $key = fn (array $i) => mb_strtolower(trim($i['description']));
        $merged = [];
        foreach ($previous as $item) {
            $merged[$key($item)] = $item;
        }
        foreach ($new as $item) {
            // Yalnız söylenen alan değişir ("çimento torba" → birim; fiyat önceki gibi kalır).
            $prev = $merged[$key($item)] ?? null;
            $changes = array_filter($item, fn ($v) => $v !== null);
            if ($prev !== null) {
                unset($changes['description']); // belgedeki yazılış kalsın ("Çimento", "çimento" değil)
            }
            $merged[$key($item)] = array_merge($prev ?? [], $changes);
        }

        return array_values($merged);
    }

    /** "geç" cevabı: okunamayan yerler boş verilir (ödeme yok sayılır, vadesiz çek anlaşma tarihli), kart onaya gelir. */
    public static function skipUnreadable(array $d): array
    {
        $d['unreadable'] = [];
        $d['question'] = null;
        $d['payments_skipped'] = true;
        $d['check_plan'] = $d['check_choice'] = null;
        foreach ($d['agreement_payments'] ?? [] as $i => $p) {
            if (self::isDeferred($p['method']) && empty($p['due_date'])) {
                $d['agreement_payments'][$i]['due_date'] = self::date($d['date'] ?? null) ?? now()->toDateString();
            }
        }

        return $d;
    }

    /** Kısa kart (kalemler en çok 6 satır, kalanı "+N kalem"). */
    public static function summary(array $d): string
    {
        $isSale = ($d['agreement_direction'] ?? null) === Agreement::DIRECTION_SALE;
        $party = self::partyName($d);
        $lines = ['🤝 *Anlaşma* — ' . $party . ($isSale ? ' (satış)' : '') . ((int) ($d['pages'] ?? 1) > 1 ? ' · 📄 ' . $d['pages'] . ' sayfa' : '')];

        $items = $d['agreement_items'] ?? [];
        foreach (array_slice($items, 0, self::MAX_CARD_ITEMS) as $item) {
            $lines[] = '• ' . self::itemLine($item);
        }
        if (count($items) > self::MAX_CARD_ITEMS) {
            $lines[] = '• +' . (count($items) - self::MAX_CARD_ITEMS) . ' kalem daha';
        }

        $total = self::agreedTotal($items);
        if ($total > 0) {
            $lines[] = 'Anlaşılan: *' . Money::format($total) . ' ₺*';
        }
        // Ödeme satırı hep görünür — yoksa "yok" (22.10: ödeme düştüğünde kartta fark edilmiyordu). İleri tarihli nakit ayrı: plan.
        [$now, $planned] = self::splitPlanned($d['agreement_payments'] ?? []);
        if ($now !== [] || $planned === []) {
            $lines[] = self::paymentsLine($now, $isSale);
        }
        if ($planned !== []) {
            $lines[] = '📅 Plan: ' . self::groupsText($planned, 'paid') . ' — hatırlatma kurulur';
        }
        if (! empty($d['agreement_notes'])) {
            $lines[] = '📝 ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $d['agreement_notes']), 0, 90, '…');
        }
        $lines[] = 'Borç yazılmaz; ' . ($isSale ? 'teslim ettikçe' : 'mal/iş geldikçe') . ' anlaşmadan düşülür.';

        return implode("\n", $lines);
    }

    /**
     * Onay: cari (yoksa açılır) → ürün kartları (yoksa açılır) → AgreementDesk::create → ödemeler.
     *
     * @return array{0:string, 1:Agreement}
     */
    public static function commit(array $d, ?string $phone = null): array
    {
        $party = ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
        $party ??= DraftGaps::exactParty($d['party_name'] ?? null) ?? Party::create(['name' => trim((string) $d['party_name'])]);
        $isSale = ($d['agreement_direction'] ?? null) === Agreement::DIRECTION_SALE;

        $lines = [];
        foreach ($d['agreement_items'] ?? [] as $item) {
            $productId = ! empty($item['product_id']) && Product::whereKey($item['product_id'])->exists()
                ? (int) $item['product_id']
                : AgreementForm::quickProduct($item['description'], ['unit_name' => $item['unit']], ! $isSale);
            $lines[] = match (true) {
                $item['free']                                      => ['product_id' => $productId, 'quantity' => $item['quantity'] ?? 1, 'free' => true],
                $item['quantity'] !== null && $item['unit_price'] !== null => ['product_id' => $productId, 'quantity' => $item['quantity'], 'unit_price' => $item['unit_price']],
                $item['quantity'] !== null                         => ['product_id' => $productId, 'quantity' => $item['quantity'], 'amount' => $item['amount']],
                $item['unit_price'] !== null && $item['amount'] === null => ['product_id' => $productId, 'unit_price' => $item['unit_price']],
                default                                            => ['product_id' => $productId, 'amount' => $item['amount'] ?? $item['unit_price']],
            };
        }

        $agreement = AgreementDesk::create($party, [
            'direction'      => $isSale ? Agreement::DIRECTION_SALE : Agreement::DIRECTION_PURCHASE,
            'agreement_date' => self::date($d['date'] ?? null) ?? now()->toDateString(),
            'project_id'     => $d['project_id'] ?? null,
            'title'          => self::title($d),
            'lines'          => $lines,
            'notes'          => $d['agreement_notes'] ?? null,
        ]);
        [$now, $planned] = self::splitPlanned($d['agreement_payments'] ?? []);
        AgreementDesk::recordPayments($agreement, $now);
        // Planlı nakit: tutarsız hatırlatma (tutarlı hatırlatma "düzenli gider" sayılıp o gün gider yazar — Reminder::isExpense).
        if ($phone !== null) {
            foreach ($planned as $p) {
                \App\Support\Reminders::fromDraft([
                    'description' => $party->name . ($isSale ? '\'dan ' : '\'e ') . Money::format($p['amount']) . ' ₺ ' . self::METHODS[$p['method']]
                        . ($isSale ? ' tahsilat' : ' ödeme') . ' (anlaşma: ' . $agreement->title . ')',
                    'party_id' => $party->id,
                    'event_date' => $p['paid_date'] ?? $p['due_date'],
                ], $phone)->save();
            }
        }

        $paid = array_sum(array_column($now, 'amount'));
        $text = '✅ Anlaşma kaydedildi: *' . $agreement->title . '*'
            . ($paid > 0 ? "\n💸 " . Money::format($paid) . ' ₺ ödeme ' . $party->name . ($isSale ? ' hesabından düşüldü.' : ' hesabına yazıldı.') : '')
            . ($planned !== [] && $phone !== null ? "\n⏰ " . count($planned) . ' ödeme hatırlatması kuruldu (ilki '
                . Carbon::parse(min(array_map(fn ($p) => $p['paid_date'] ?? $p['due_date'], $planned)))->format('d.m.Y') . ').' : '')
            . "\nPanelde carinin sayfasında kalem kalem görünür.";

        return [$text, $agreement];
    }

    /** Anlaşılan toplam: miktarlı + götürü (fiyat listesi ve bedelsiz girmez). */
    public static function agreedTotal(array $items): float
    {
        return round(array_sum(array_map(fn ($i) => match (true) {
            $i['free'] => 0,
            $i['quantity'] !== null && $i['unit_price'] !== null => $i['quantity'] * $i['unit_price'],
            $i['quantity'] === null && $i['amount'] === null => 0, // fiyat listesi
            default => (float) $i['amount'],
        }, $items)), 2);
    }

    private static function itemLine(array $i): string
    {
        $unit = $i['unit'] ? ' ' . $i['unit'] : '';
        $qty = fn () => rtrim(rtrim(number_format((float) $i['quantity'], 2, ',', '.'), '0'), ',');

        return match (true) {
            $i['free'] => $i['description'] . ($i['quantity'] !== null ? ' ' . $qty() . $unit : '') . ' — bedelsiz',
            $i['quantity'] !== null && $i['unit_price'] !== null => $i['description'] . ' ' . $qty() . $unit . ' × ' . Money::format($i['unit_price'])
                . ' = ' . Money::format($i['quantity'] * $i['unit_price']),
            $i['quantity'] === null && $i['unit_price'] !== null && $i['amount'] === null => $i['description'] . ' · ' . Money::format($i['unit_price']) . ' ₺'
                . ($i['unit'] ? '/' . $i['unit'] : '') . ' (fiyat listesi)',
            default => $i['description'] . ($i['quantity'] !== null ? ' ' . $qty() . $unit : ' · götürü') . ' ' . Money::format((float) ($i['amount'] ?? $i['unit_price'])) . ' ₺',
        };
    }

    /** "💸 Ödeme: 160.000 nakit · 3 senet 600.000 (30.07 · 30.10 · 30.12)". */
    private static function paymentsLine(array $payments, bool $isSale): string
    {
        return '💸 ' . ($isSale ? 'Alınan' : 'Ödeme') . ': ' . ($payments === [] ? 'yok' : self::groupsText($payments, 'due'));
    }

    /** Yönteme göre gruplu: "9 çek 6.750.000 (vade 30.05.2027 → 30.01.2028)"; $dateKind 'due' = vade, 'paid' = ödeme tarihi. */
    private static function groupsText(array $payments, string $dateKind): string
    {
        $groups = [];
        foreach ($payments as $p) {
            $groups[$p['method']][] = $p;
        }
        $parts = [];
        foreach ($groups as $method => $rows) {
            $sum = Money::format(array_sum(array_column($rows, 'amount')));
            // Belgede iki sütunlu plan (Kamuran: 05, 06, 07, 11, 01 / 08, 09, 10, 12) — tarih sırasıyla.
            $dates = array_filter(array_map(fn ($r) => $dateKind === 'due' ? $r['due_date'] : ($r['paid_date'] ?? $r['due_date']), $rows));
            sort($dates);
            $dates = array_map(fn ($dt) => Carbon::parse($dt)->format('d.m.Y'), $dates);
            // Çok tarihte kısa: "(vade 28.02.2027 → 31.12.2027)" (kart ≈4 satır kuralı).
            $dates = count($dates) > 4 ? [reset($dates) . ' → ' . end($dates)] : $dates;
            $parts[] = (count($rows) > 1 ? count($rows) . ' ' : '') . self::METHODS[$method] . ' ' . $sum
                . ($dates ? ' (' . ($dateKind === 'due' ? 'vade ' : '') . implode(' · ', $dates) . ')' : '');
        }

        return implode(' · ', $parts);
    }

    private static function partyName(array $d): string
    {
        if (! empty($d['party_id']) && ($p = Party::find($d['party_id']))) {
            return $p->name;
        }

        return trim((string) ($d['party_name'] ?? '')) . ' (yeni cari)';
    }

    private static function title(array $d): ?string
    {
        $desc = trim((string) ($d['description'] ?? ''));

        return $desc !== '' ? mb_substr($desc, 0, 80) : null; // boşsa AgreementDesk kalemlerden ad koyar
    }

    private static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))
            ? $v : null;
    }
}
