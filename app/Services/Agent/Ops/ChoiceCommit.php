<?php

namespace App\Services\Agent\Ops;

use App\Models\Party;
use App\Models\WhatsappMessage;
use App\Services\Agent\Tools\FindParty;
use App\Services\Whatsapp\ExpenseExtractor;
use App\Support\Phone;

/**
 * Numaralı seçim onaydır (20.11): ajan "9.000 kepçe hangi projeye? *1* … *3* Yeşilyurt" ya da "7.500 mi yazayım? *1* Evet" diye sordu,
 * müşteri "3" / "1" dedi → arkasından bir de "Kaydedeyim mi?" kartı gelmez, kayıt bu turda yazılır. Müşteri seçince işin bittiğini
 * sanıyor; ikinci onay beklenirken kayıt havada kalıyordu (Muhasebe 11.11 9.000 kepçe, Tok 19.11 7.500 ekskavatör).
 *
 * Karar KODUN: yalnız müşterinin soruda gördüğü kayıt doğrudan yazılır. Şunlardan biri varsa kart eskisi gibi gösterilir:
 * mesaj bir seçenek değil · bir önceki mesaj ajanın numaralı sorusu değil · bir tutar soruda geçmiyor · kişi soruda geçmiyor ya da
 * yeni kişi açılıyor · basit para/iş kaydı dışında bir işlem (silme, düzeltme, iade, çek, maaş, ürünlü, anlaşma, hatırlatma…) ·
 * sistem uyarısı · belgeden okunan büyük tutar.
 */
final class ChoiceCommit
{
    public const KINDS = [
        ExpenseExtractor::KIND_EXPENSE, ExpenseExtractor::KIND_PAYMENT, ExpenseExtractor::KIND_COLLECTION,
        ExpenseExtractor::KIND_SALE, ExpenseExtractor::KIND_PURCHASE,
    ];

    /** Soru ile cevap arası en çok bu kadar (WhatsApp serbest metin penceresiyle aynı). */
    private const QUESTION_HOURS = 23;

    /**
     * @param  list<array<string, mixed>>  $ops  önerilen işlemler
     * @param  array<string, mixed>  $result  AgentProposal::propose sonucu (ok, warnings, meta)
     */
    public static function applies(string $phone, string $rawMessage, array $ops, array $result): bool
    {
        $meta = (array) ($result['meta'] ?? []);
        if (empty($result['ok']) || ! empty($result['already_shown']) || ($result['warnings'] ?? []) !== []
            || ! empty($meta['new_party']) || ! empty($meta['family_card']) || ! empty($meta['large']) || (int) ($meta['deletes'] ?? 0) > 0) {
            return false;
        }
        if (! self::simpleRecords($ops)) {
            return false;
        }
        $question = self::lastQuestion($phone);
        if ($question === null) {
            return false;
        }
        $chosen = self::chosenOption($rawMessage, $question['options']);
        if ($chosen === null) {
            return false;
        }

        // Müşteri neyi seçtiğini görmüş olmalı: her tutar ve her kişi soruda (seçenekler dahil) geçiyor.
        $amounts = array_filter(array_map('floatval', (array) ($meta['amounts'] ?? [])), fn ($a) => $a > 0);
        if ($amounts === []) {
            return false;
        }
        foreach ($amounts as $amount) {
            if (! Numbers::contains($question['text'], $amount)) {
                return false;
            }
        }
        $folded = ' ' . FindParty::fold($question['text']) . ' ';
        foreach (array_unique(array_map('intval', (array) ($meta['party_ids'] ?? []))) as $id) {
            $name = FindParty::fold((string) Party::whereKey($id)->value('name'));
            $first = (string) (array_values(array_filter(explode(' ', $name), fn ($w) => mb_strlen($w) >= 3))[0] ?? '');
            if ($name === '' || (! str_contains($folded, ' ' . $name . ' ') && ($first === '' || ! str_contains($folded, ' ' . $first . ' ')))) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<array<string, mixed>>  $ops */
    private static function simpleRecords(array $ops): bool
    {
        if ($ops === []) {
            return false;
        }
        foreach ($ops as $op) {
            $entry = (array) ($op['entry'] ?? $op);
            if (($op['type'] ?? null) !== 'record' || ! in_array($entry['kind'] ?? null, self::KINDS, true)
                || ! empty($entry['checks']) || ! empty($entry['check_plan']) || ! empty($entry['items'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bu numaraya giden son mesaj ajanın numaralı sorusuysa ve ondan sonra yalnız şimdiki mesaj geldiyse: soru metni ve seçenekleri.
     *
     * @return array{text: string, options: list<string>}|null
     */
    private static function lastQuestion(string $phone): ?array
    {
        $normalized = Phone::normalize($phone);
        $out = WhatsappMessage::where('phone', $normalized)->where('direction', 'out')
            ->where('created_at', '>=', now()->subHours(self::QUESTION_HOURS))->latest('id')->first(['id', 'body']);
        if (! $out || ! str_contains((string) $out->body, '❓')) { // ajanın kısa cümlesi sorunun önüne eklenebilir
            return null;
        }
        // Sorudan sonra tek gelen mesaj şimdiki cevap olmalı (araya başka konuşma girdiyse seçim o soruya ait olmayabilir).
        if (WhatsappMessage::where('phone', $normalized)->where('direction', 'in')->where('id', '>', $out->id)->count() > 1) {
            return null;
        }
        preg_match_all('/^\*(\d{1,2})\*\s+(.+)$/mu', (string) $out->body, $m, PREG_SET_ORDER);
        if ($m === []) {
            return null;
        }
        $options = [];
        foreach ($m as $row) {
            $options[(int) $row[1]] = trim($row[2]);
        }

        return ['text' => (string) $out->body, 'options' => $options];
    }

    /** Müşterinin mesajı bir seçenek mi: "3", "*3*", "3." ya da seçeneğin metni. @param  array<int, string>  $options */
    private static function chosenOption(string $rawMessage, array $options): ?string
    {
        $raw = trim($rawMessage, " \t\n\r*.)");
        if (ctype_digit($raw) && isset($options[(int) $raw])) {
            return $options[(int) $raw];
        }
        $folded = FindParty::fold($raw);
        foreach ($options as $option) {
            if ($folded !== '' && FindParty::fold($option) === $folded) {
                return $option;
            }
        }

        return null;
    }
}
