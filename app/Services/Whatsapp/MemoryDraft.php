<?php

namespace App\Services\Whatsapp;

use App\Models\AssistantMemory;
use App\Models\Party;
use App\Models\Product;
use App\Support\Phone;

/**
 * İşletme hafızası (Faz 2): "bundan sonra kalfa deyince Mehmet'i anla", "Recep'le peynir 280'den anlaştık",
 * "bana kısa yaz". Sessiz öğrenme YOK — her bilgi özet + *evet* ile kaydedilir. Firmanın ortak hafızası; tercih
 * (preference) yalnız öğreten numaraya. Aynı konudaki yeni bilgi eskisini "replaced" yapar (geçmiş kalır).
 *
 *   remember     → "🧠 Hatırlayayım mı?" + onay
 *   forget       → "🧠 Unutayım mı?" + onay
 *   memory_list  → hemen cevap (kayıt yok)
 */
final class MemoryDraft
{
    public const KINDS = [ExpenseExtractor::KIND_REMEMBER, ExpenseExtractor::KIND_FORGET, ExpenseExtractor::KIND_MEMORY_LIST];

    public static function handles(array $d): bool
    {
        return in_array($d['kind'] ?? null, self::KINDS, true);
    }

    public static function isAnswer(array $d): bool
    {
        return ($d['kind'] ?? null) === ExpenseExtractor::KIND_MEMORY_LIST;
    }

    /** "neleri hatırlıyorsun" */
    public static function listText(string $phone): string
    {
        $rows = AssistantMemory::activeFor(Phone::normalize($phone))->orderBy('kind')->orderBy('subject')->limit(40)->get();
        if ($rows->isEmpty()) {
            return "🧠 Henüz bana öğrettiğin bir şey yok.\nÖrnek: *bundan sonra kalfa deyince Mehmet Kaya'yı anla* ya da *Recep'le peynirin kilosunu 280'den anlaştık*.";
        }

        return "🧠 *Hatırladıklarım:*\n" . $rows->map(fn (AssistantMemory $m) => $m->line())->implode("\n")
            . "\n\nYanlış olanı *X'i unut* diye yazabilirsin; panelde *Asistan hafızası*ndan da düzeltebilirsin.";
    }

    /** Unutulacaklar: konu ya da bilgide aranan kelime geçen etkin kayıtlar. */
    public static function matches(array $d, string $phone)
    {
        $needle = trim((string) ($d['memory_subject'] ?? ''));
        if ($needle === '') {
            return collect();
        }
        $like = (new AssistantMemory)->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return AssistantMemory::activeFor(Phone::normalize($phone))
            ->where(fn ($q) => $q->where('subject', $like, '%' . $needle . '%')->orWhere('fact', $like, '%' . $needle . '%'))
            ->limit(10)->get();
    }

    public static function blocker(array $d, string $phone): ?string
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_FORGET) {
            return self::matches($d, $phone)->isEmpty() ? 'Böyle bir şey hatırlamıyorum. Neyi unutayım? (*neleri hatırlıyorsun* yazarsan listeyi gösteririm)' : null;
        }

        if (trim((string) ($d['memory_fact'] ?? '')) === '') {
            return 'Neyi hatırlayayım? Kısaca yazar mısın?';
        }
        // Hafıza bir şeyi kendiliğinden YAPMAZ (Çiftlik 18.10: "her gün ekmek gideri ekle" hafızaya söz olarak yazıldı, hiç girilmedi).
        if (self::promisesAction((string) $d['memory_fact'])) {
            return 'Bunu not olarak hatırlarım ama kendiliğimden her gün kayıt açamam. '
                . 'Her gün/ay tekrarlanan bir gider için *her gün 34,60 ekmek gideri yaz* gibi yazarsan düzenli gider olarak kurarım; '
                . 'stoktan her gün düşmek için *yem günde 16 gidiyor* yazman yeterli.';
        }

        return null;
    }

    /** Not, gelecekte kendiliğinden yapılacak bir iş vaat ediyor mu ("her gün gider olarak kaydedilecek", "her ay hatırlat"). */
    public static function promisesAction(string $fact): bool
    {
        $t = mb_strtolower(strtr($fact, ['İ' => 'i', 'I' => 'ı']), 'UTF-8');

        return (bool) preg_match('/(her ?gün|her sabah|her akşam|her hafta|her ay|hergün|günlük olarak|haftalık olarak|aylık olarak)/u', $t)
            && (bool) preg_match('/(ekle|eklen|kaydet|kaydedil|kayda geç|yaz(ıl|ıla|acak)?|gider olarak|düş(ül)?|hatırlat|gönder|mesaj at|öde(n|necek))/u', $t);
    }

    public static function summary(array $d, string $phone): string
    {
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_FORGET) {
            return "🧠 *Bunları unutayım mı?*\n" . self::matches($d, $phone)->map(fn (AssistantMemory $m) => $m->line())->implode("\n");
        }
        $line = (AssistantMemory::ICONS[self::kind($d)] ?? '•') . ' ' . trim((string) $d['memory_fact']);
        $old = self::existing($d, $phone);

        return "🧠 *Hatırlayayım mı?*\n" . $line
            . ($old ? "\n_(eskisi: " . $old->fact . ' — yenisi geçerli olur)_' : '')
            . (self::kind($d) === 'preference' ? "\n_(yalnız senin numaran için)_" : '');
    }

    /** @return array{0:string,1:?int} */
    public static function commit(array $d, string $phone): array
    {
        $phone = Phone::normalize($phone);
        if (($d['kind'] ?? null) === ExpenseExtractor::KIND_FORGET) {
            $rows = self::matches($d, $phone);
            AssistantMemory::whereKey($rows->pluck('id'))->update(['status' => 'deleted']);

            return ['🧠 Tamam, unuttum: ' . $rows->map(fn ($m) => $m->fact)->implode('; ') . '.', null];
        }
        $kind = self::kind($d);
        $old = self::existing($d, $phone);
        $memory = AssistantMemory::create([
            'kind' => $kind,
            'subject' => mb_substr(trim((string) ($d['memory_subject'] ?? '')) ?: trim((string) $d['memory_fact']), 0, 120),
            'fact' => trim((string) $d['memory_fact']),
            'party_id' => ! empty($d['party_id']) && Party::whereKey($d['party_id'])->exists() ? (int) $d['party_id'] : null,
            'product_id' => ! empty($d['product_id']) && Product::whereKey($d['product_id'])->exists() ? (int) $d['product_id'] : null,
            'phone' => $kind === 'preference' ? $phone : null,
            'taught_by' => $phone,
            'source' => mb_substr((string) ($d['memory_source'] ?? ''), 0, 1000) ?: null,
        ]);
        $old?->update(['status' => 'replaced', 'replaced_by_id' => $memory->id]);

        return ['🧠 Tamam, hatırlayacağım: ' . $memory->fact . ($kind === 'preference' ? '' : "\nTüm numaralarda geçerli. *Neleri hatırlıyorsun* yazarsan listeyi gösteririm."),
            $memory->party_id];
    }

    private static function kind(array $d): string
    {
        return array_key_exists($d['memory_kind'] ?? '', AssistantMemory::KINDS) ? $d['memory_kind'] : 'note';
    }

    /** Aynı konuda etkin eski bilgi (yenisi onu değiştirir). */
    private static function existing(array $d, string $phone): ?AssistantMemory
    {
        $subject = mb_strtolower(trim((string) ($d['memory_subject'] ?? '')));
        if ($subject === '') {
            return null;
        }

        return AssistantMemory::activeFor(Phone::normalize($phone))->where('kind', self::kind($d))->get()
            ->first(fn (AssistantMemory $m) => mb_strtolower(trim($m->subject)) === $subject);
    }

    /** Hafızada bu cari için öğretilmiş takma ad mı ("kalfa" → Mehmet Kaya) — "aynı kişi mi?" sorusu gereksiz. */
    public static function isKnownAlias(int $partyId, ?string $said): bool
    {
        $said = mb_strtolower(trim((string) $said));
        if ($said === '' || ! \Illuminate\Support\Facades\Schema::hasTable('assistant_memories')) {
            return false;
        }

        return AssistantMemory::where('status', 'active')->where('kind', 'alias')->where('party_id', $partyId)->get()
            ->contains(fn (AssistantMemory $m) => ($s = mb_strtolower(trim($m->subject))) !== '' && (str_contains($said, $s) || str_contains($s, $said)));
    }

    /** AI bağlamı: firmanın ortak hafızası + bu numaranın tercihleri (en çok 50 satır). */
    public static function contextLines(?string $phone): array
    {
        // Tablo henüz yoksa (migration öncesi) asistan yine çalışsın — sorgu atmadan bak (PG'de hatalı sorgu işlemi bozar).
        if (! \Illuminate\Support\Facades\Schema::hasTable('assistant_memories')) {
            return [];
        }
        $rows = AssistantMemory::activeFor($phone ? Phone::normalize($phone) : null)->latest('id')->limit(50)->get();

        return $rows->map(fn (AssistantMemory $m) => '  ' . $m->line() . ($m->party_id ? " [cari {$m->party_id}]" : '')
            . ($m->product_id ? " [ürün {$m->product_id}]" : '') . ' (' . $m->updated_at?->format('d.m.Y') . ')')->all();
    }
}
