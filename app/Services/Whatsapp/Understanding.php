<?php

namespace App\Services\Whatsapp;

use App\Models\Party;
use App\Support\Money;
use App\Support\PartyStatement;

/**
 * "Önce anla, sonra kaydet" (plan once-anla-sonra-kaydet.md, 16.10).
 *
 * Kişiyle para/borç kaydında AI olayı anlar: `meaning` (düz cümle), `work_status` (iş/mal ne durumda) ve bunu
 * BİLİYOR mu (`work_status_known`), kişi eşleşmesi kesin mi (`party_certain`). Bu sınıf:
 *  - apply(): bilinen iş durumunu kayda çevirir — AI "henüz başlamadı" anlayıp borç yazmasın (Taner/Abdurrahman);
 *  - gap(): kaydı değiştirecek bir şey tahminse soru döner → taslak onaya HAZIR DEĞİL (kod zorlar, tahmin yok);
 *  - checkWarnings(): çek vadesi takvimde yoksa (31 Şubat) uyarı.
 * Satış tarafı (müşteri) şimdilik eski kurallarda; burası tedarikçi/usta tarafı.
 */
final class Understanding
{
    public const DONE = 'done';

    public const NOT_YET = 'not_yet';

    public const PARTIAL = 'partial';

    public const AGREEMENT = 'agreement_only';

    public const NO_WORK = 'no_work';

    public const OLD_DEBT = 'old_debt';

    public const STATUSES = [self::DONE, self::NOT_YET, self::PARTIAL, self::AGREEMENT, self::NO_WORK, self::OLD_DEBT];

    /** Prompt kuralı — sohbet asistanı ve belge okuyucu aynı ilkeyle çalışsın. */
    public const RULES = <<<'TXT'
    ÖNCE ANLA, SONRA KAYDET (en önemli kural — yanlış kayıttansa soru):
    - Kişiyle para/borç içeren her kayıtta (ödeme, alış, borç, carili gider) önce olayı anla: kime, para ne oldu, İŞ/MAL ne durumda.
      `meaning`: olayın günlük dille anlamı, kişinin adıyla ("Osman'a asansör parasını ödedin, asansör henüz takılmadı";
      "Abdurrahman Usta sıvayı bitirdi, 115.000 ₺ parasını henüz vermedin"). Muhasebe kelimesi KULLANMA (alış, avans, gider, kayıt, cari).
    - `work_status`: done (iş bitti / mal geldi) · not_yet (iş/mal sonra; para ÖNCEDEN verildi — peşin, kapora, bağlantı) · partial ·
      agreement_only (yalnız fiyat/iş anlaşıldı, iş başlamadı, para verilmedi → BORÇ DEĞİL) · no_work (iş yok, düz para: borç verdim/aldım) ·
      old_debt (daha önceki bir borcun ödemesi: "borcum ödendi", "borç iade").
      `work_status_known`: true YALNIZ kullanıcı söylediyse ya da işin doğası kesin gösteriyorsa ("sıvayı bitirdi", "beton geldi", "un aldım",
      fiş/fatura = mal alındı, "avans/kapora/peşin", inşaatta "bağlantı ödendi" = fiyatı sabit peşin mal alımı, dekontta "borç iade").
      Değilse TAHMİN ETME: false yap ve `question`'a TEK kısa soru yaz.
    - Bunlar işin bittiğini SÖYLEMEZ → sor: "X bedeli ödendi / proje bedeli / asansör bedeli", "X'e ödedim" (ne için belli değil),
      ustaya/firmaya "borcum var" (iş bitti de para mı verilmedi, yapacağı işe mi anlaşıldı, borç para mı?),
      "alınan / aldığımız / sipariş edilen X'e ödenen" (satın almayı anlatır, TESLİMİ/MONTAJI değil — asansör, kapı, pencere,
      dolap, iskele gibi sonra gelen/takılan işlerde mutlaka sor), vadeli çekle yapılan ödeme (çek teslim anlamına gelmez).
      known=true için mesajda ya da belgede teslimi/bitişi gösteren bir şey olmalı ("takıldı", "teslim aldım", "geldi", "bitirdi",
      fiş/fatura/irsaliye); yalnız ödeme belgesi (çek, dekont) teslimi kanıtlamaz.
    - Soru: yalnız kaydı DEĞİŞTİRECEK şeyi, tek soruda, kişinin ve işin adıyla, akıllıca sor ("Asansör takıldı mı, yoksa takılmadan
      peşin mi ödüyorsunuz?"). Malın cinsi, ödeme şekli, toplam bedel gibi kaydı değiştirmeyenleri SORMA. Açık mesajda soru YOK.
    - Kişi: yazılan ad listedeki biriyle aynı değilse ve aynı kişi olduğu kesin değilse (ör. "Sinan usta" ↔ CARİLER'de "Sinan Sele")
      `party_certain`=false ve sor ("Sinan Sele mi, başka bir Sinan mı?"). Aynı ad ya da hafızadaki takma ad → true.
      Listede benzeri olmayan YENİ kişi işiyle anılıyorsa ("boyacı usta", "kaynakçı") o adla yeni kişi aç (`party_name`), adını SORMA —
      kaydı değiştirmez; party_certain=true.
    - Bir kişiye (usta, firma, tedarikçi) yapılan ödeme/iş o kişinin hesabına yazılır (`party_name`); gider yalnız kişisiz harcamadır
      (yakıt fişi, kira, market).
    - BELGE mesajdan üstündür: çek görüntüsünde her çeki `checks`'e yaz (no, vade YAZILDIĞI GİBİ, tutar) — ödeme çekle yapılmıştır;
      dekontta gönderen/alıcı/açıklama esastır. Mesajla belge çelişirse sor.
    TXT;

    /** Şema alanları (propose_entry / save_entry). */
    public static function schema(): array
    {
        return [
            'meaning' => ['type' => ['string', 'null'], 'description' => 'Plain everyday Turkish sentence of what HAPPENED, with names (no accounting words). Required for every money/debt record.'],
            'work_status' => ['type' => ['string', 'null'], 'enum' => [...self::STATUSES, null], 'description' => 'Money/debt with a person: state of the work/goods behind it (see ÖNCE ANLA). null if not applicable.'],
            'work_status_known' => ['type' => ['boolean', 'null'], 'description' => 'true only if the user said it or it is certain from the trade meaning; false if guessing → write `question`.'],
            'party_certain' => ['type' => ['boolean', 'null'], 'description' => 'false if matched to an existing party with a DIFFERENT name that may be another person; true if same name / memory alias; null if no person.'],
            'checks' => ['type' => ['array', 'null'], 'description' => 'Only from a cheque image/document: each cheque as written.', 'items' => [
                'type' => 'object',
                'properties' => [
                    'number' => ['type' => ['string', 'null']],
                    'due_date_text' => ['type' => ['string', 'null'], 'description' => 'Due date exactly as written (e.g. 31-02-2027)'],
                    'amount' => ['type' => ['number', 'null']],
                ],
            ]],
        ];
    }

    /** normalize(): AI girdisinden güvenli alanlar. */
    public static function normalize(array $input): array
    {
        $checks = [];
        foreach ((array) ($input['checks'] ?? []) as $c) {
            if (is_array($c) && (! empty($c['number']) || ! empty($c['due_date_text']) || (float) ($c['amount'] ?? 0) > 0)) {
                $checks[] = ['number' => trim((string) ($c['number'] ?? '')) ?: null, 'due_date_text' => trim((string) ($c['due_date_text'] ?? '')) ?: null,
                    'amount' => (float) ($c['amount'] ?? 0) > 0 ? round((float) $c['amount'], 2) : null];
            }
        }

        return [
            'meaning' => is_string($input['meaning'] ?? null) && trim($input['meaning']) !== '' ? mb_substr(trim($input['meaning']), 0, 300) : null,
            'work_status' => in_array($input['work_status'] ?? null, self::STATUSES, true) ? $input['work_status'] : null,
            'work_status_known' => isset($input['work_status_known']) ? (bool) $input['work_status_known'] : null,
            'party_certain' => isset($input['party_certain']) ? (bool) $input['party_certain'] : null,
            'checks' => $checks ?: null,
        ];
    }

    /** Bu kayıt "kişiyle para/borç" mu — anlama kapısı yalnız bunlarda. */
    public static function applies(array $d): bool
    {
        $kind = $d['kind'] ?? null;
        $hasParty = ! empty($d['party_id']) || ! empty($d['party_name']);
        if (! $hasParty || (float) ($d['amount'] ?? 0) <= 0) {
            return false;
        }
        // Belirli yollar zaten kesin: personel/maaş, eski borcun ödemesi, ödenmemiş giderin kapanması, sözleşme.
        if (! empty($d['is_staff']) || ! empty($d['staff_hint']) || ! empty($d['debt_repayment']) || ! empty($d['settles_expense_id'])
            || ! empty($d['contract_id'])) {
            return false;
        }

        return match ($kind) {
            ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_PURCHASE, ExpenseExtractor::KIND_EXPENSE => true,
            ExpenseExtractor::KIND_DEBT_NOTE => ($d['debt_side'] ?? null) === 'payable',
            default => false,
        };
    }

    /**
     * Bilinen iş durumunu kayda çevir (AI'ın anladığı = kayıt). Bilinmiyorsa dokunma (gap() sorar).
     */
    public static function apply(array $d): array
    {
        // Anlaşma tutarsız da gelebilir (AI "borç değil" diye 0 yazar): kişi varsa yine anlaşma olarak hafızaya.
        $agreement = ($d['work_status'] ?? null) === self::AGREEMENT && ! empty($d['work_status_known'])
            && (! empty($d['party_id']) || ! empty($d['party_name']))
            && in_array($d['kind'] ?? null, [ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_PURCHASE,
                ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_DEBT_NOTE], true);
        if (! $agreement && (! self::applies($d) || empty($d['work_status_known']) || empty($d['work_status']))) {
            return $d;
        }
        $kind = $d['kind'];
        $status = $d['work_status'];
        $paidNow = $kind === ExpenseExtractor::KIND_PAYMENT || ! empty($d['paid']);

        // Yalnız anlaşma: iş başlamadı, para verilmedi → borç YOK. Hafızaya "anlaşma" olarak önerilir (onaylı).
        if ($status === self::AGREEMENT || ($status === self::NOT_YET && ! $paidNow)) {
            $name = self::partyName($d);

            return ['kind' => ExpenseExtractor::KIND_REMEMBER, 'memory_kind' => 'agreement', 'memory_subject' => mb_substr($name . ' anlaşması', 0, 120),
                'memory_fact' => mb_substr($d['meaning'] ?? ($name . ' ile ' . ((float) ($d['amount'] ?? 0) > 0 ? Money::format((float) $d['amount']) . ' ₺ ' : '')
                    . 'anlaşıldı (henüz başlamadı)'), 0, 500),
                'party_id' => $d['party_id'] ?? null, 'amount' => 0, 'agreement_from' => $kind] + $d;
        }
        // Para önceden verildi, iş/mal sonra → avans (o bize iş/mal borçlu).
        if ($status === self::NOT_YET && $paidNow) {
            return array_merge($d, ['kind' => ExpenseExtractor::KIND_PAYMENT, 'payment_purpose' => 'advance', 'advance' => true, 'paid' => null]);
        }
        // Düz para verildi (iş yok) → borç verdim.
        if ($status === self::NO_WORK && $kind === ExpenseExtractor::KIND_PAYMENT) {
            return array_merge($d, ['payment_purpose' => 'loan', 'advance' => true]);
        }
        // İş bitti + para verildi → alış + ödeme (bakiye değişmez). "Ne için verdin?" kalıbı sorulmaz.
        if ($status === self::DONE && $kind === ExpenseExtractor::KIND_PAYMENT) {
            return array_merge($d, ['kind' => ExpenseExtractor::KIND_PURCHASE, 'paid' => true, 'payment_purpose' => null, 'advance' => false]);
        }
        // Eski borcun ödemesi → borçtan düşer.
        if ($status === self::OLD_DEBT && $kind === ExpenseExtractor::KIND_PAYMENT) {
            return array_merge($d, ['debt_repayment' => true, 'payment_purpose' => null, 'advance' => false]);
        }

        return $d;
    }

    /**
     * Kaydı değiştirecek belirsizlik → sorulacak soru (AI'ın sorusu; yoksa yedek). null = onaya hazır.
     */
    public static function gap(array $d): ?string
    {
        if (! self::applies($d)) {
            return null;
        }
        $name = self::partyName($d);
        // Kişi eşleşmesi belirsiz: "Sinan usta" → "Sinan Sele" sessizce olmasın.
        if (($d['party_certain'] ?? null) === false && ! empty($d['party_id'])) {
            return self::question($d) ?? ('"' . ($d['party_said'] ?? $name) . '" dediğiniz ' . $name . ' mi, yoksa başka biri mi?');
        }
        // Yalnız AI açıkça "bilmiyorum" dediyse (false). Alan yoksa (eski taslak / eski yol) eski davranış sürer;
        // gerçek AI'da alan şemada zorunlu — her kayıtta doldurur.
        if (($d['work_status_known'] ?? null) !== false && ($d['work_status'] ?? null) !== self::PARTIAL) {
            return null;
        }
        // Ödeme + açık borcu olan kişi: ödeme o borca gider (eski davranış) — sorulmaz.
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_PAYMENT && ($party = self::party($d))
            && PartyStatement::build($party)['balance'] < -0.01) {
            return null;
        }

        return self::question($d) ?? match ($d['kind'] ?? null) {
            ExpenseExtractor::KIND_DEBT_NOTE => $name . ' işi bitirdi de parası mı verilmedi, yoksa yapacağı iş için mi anlaştınız?',
            default => $name . ' işi bitirdi / mal geldi mi, yoksa önceden mi ödediniz?',
        };
    }

    /** Çek vadeleri: takvimde olmayan gün (31 Şubat) ya da okunamayan tarih → ⚠️ satırları. */
    public static function checkWarnings(array $d): array
    {
        $out = [];
        foreach ((array) ($d['checks'] ?? []) as $c) {
            $text = (string) ($c['due_date_text'] ?? '');
            if ($text === '' || ! preg_match('/(\d{1,2})\s*[.\/-]\s*(\d{1,2})\s*[.\/-]\s*(\d{2,4})/', $text, $m)) {
                continue;
            }
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            if (! checkdate((int) $m[2], (int) $m[1], $year)) {
                $out[] = '⚠️ ' . ($c['number'] ? 'Çek ' . $c['number'] . ': ' : 'Çek: ') . 'vade ' . $text
                    . ' — takvimde böyle bir gün yok, bankada sorun çıkabilir.';
            }
        }

        return $out;
    }

    /** Çekleri açıklamaya ekle (çek kaydı ayrı fazda) — vade ve numara kayıtta kaybolmasın. */
    public static function checksNote(array $d): ?string
    {
        $parts = [];
        foreach ((array) ($d['checks'] ?? []) as $c) {
            $parts[] = trim(($c['number'] ?? '') . ' · vade ' . ($c['due_date_text'] ?? '?')
                . (! empty($c['amount']) ? ' · ' . Money::format((float) $c['amount']) . ' ₺' : ''), ' ·');
        }

        return $parts ? count($parts) . ' çek: ' . implode('; ', $parts) : null;
    }

    private static function question(array $d): ?string
    {
        $q = trim((string) ($d['question'] ?? ''));

        return $q !== '' ? $q : null;
    }

    private static function party(array $d): ?Party
    {
        return ! empty($d['party_id']) ? Party::find($d['party_id']) : null;
    }

    private static function partyName(array $d): string
    {
        return self::party($d)?->name ?? (string) ($d['party_name'] ?? $d['party_said'] ?? 'Kişi');
    }
}
