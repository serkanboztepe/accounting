<?php

namespace App\Support;

use App\Models\Check;
use App\Models\PartyLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Çekler ekranından girilen çek → cari hesabı (kullanıcı 23.10: "bizim hatamız, tasarım hatası" — çek cariye yansımıyordu).
 * WhatsApp ve cari sayfasındaki "Ödeme Yaptım / Ödeme Aldım" ile AYNI yapı: verilen çek = ödeme satırı, alınan çek =
 * tahsilat satırı (çek verildiği/alındığı gün para hareketi sayılır), çek o satıra bağlı; "Çeki sil" (CheckRemoval)
 * ikisini birlikte geri alır.
 *
 * Kural (kullanıcı 26.10 — seçenek YOK): cari seçiliyse yeni çekte ve düzenlemede her zaman; cari kaldırılırsa / çek silinirse
 * cariden de silinir. Carisiz çek yalnız hatırlatmadır. Sözleşme ödemesine bağlı çek cariye yazılmaz (sözleşme tutar).
 * Eski bağsız çeklere sistem kendiliğinden dokunmaz — kullanıcı açıp kaydederse kural uygulanır.
 */
final class CheckLedger
{
    public static function canWrite(Check $check): bool
    {
        return $check->party_id !== null && $check->contract_payment_id === null;
    }

    public static function typeFor(Check $check): string
    {
        return in_array($check->status, ['portfolio', 'collected'], true)
            ? PartyLedgerEntry::TYPE_COLLECTION
            : PartyLedgerEntry::TYPE_PAYMENT;
    }

    /** Çekler ekranında kayıttan sonra (yeni ve düzenleme): bağlıysa eşitle, carili ve bağsızsa bağla. */
    public static function apply(Check $check): void
    {
        $check->ledgerEntry ? self::sync($check) : self::attach($check);
    }

    /** Çeki cari satırına bağla (yoksa açar). */
    public static function attach(Check $check): ?PartyLedgerEntry
    {
        if (! self::canWrite($check) || $check->party_ledger_entry_id !== null) {
            return null;
        }

        return DB::transaction(function () use ($check) {
            $entry = PartyLedgerEntry::create(self::entryData($check));
            $check->forceFill(['party_ledger_entry_id' => $entry->id])->saveQuietly();

            return $entry;
        });
    }

    /**
     * Bağlı çek düzenlendikten sonra (save sonrası; getPrevious = eski değerler) cari satırı da düzelir. Satırda çekten başka
     * para da olabilir (WhatsApp: 60 bin çek + 40 bin nakit tek ödeme) → tutar değişince satıra yalnız FARK yansır.
     * Tek çekli satırda cari, yer, tarih ve yön de çekle gider. Sözleşmeye bağlanan / carisi kaldırılan çekin payı satırdan düşer.
     */
    public static function sync(Check $check): void
    {
        $entry = $check->ledgerEntry;
        if (! $entry || ! $check->wasChanged()) {
            return;
        }
        $prev = $check->getPrevious();
        $prevAmount = (float) ($prev['amount'] ?? $check->amount);

        DB::transaction(function () use ($check, $entry, $prevAmount) {
            if (! self::canWrite($check)) {
                $check->forceFill(['party_ledger_entry_id' => null])->saveQuietly();
                $rest = round((float) $entry->amount - $prevAmount, 2);
                $rest > 0.009 ? $entry->update(['amount' => Money::store($rest)]) : $entry->delete();

                return;
            }
            $update = [];
            if ($check->wasChanged('amount')) {
                $update['amount'] = Money::store((float) $entry->amount + (float) $check->amount - $prevAmount);
            }
            if ($entry->checks()->count() === 1) {
                $data = self::entryData($check);
                foreach (['party_id' => 'party_id', 'project_id' => 'project_id', 'issue_date' => 'entry_date', 'status' => 'type'] as $field => $column) {
                    if ($check->wasChanged($field)) {
                        $update[$column] = $data[$column];
                    }
                }
            }
            if ($update !== []) {
                $entry->update($update);
            }
        });
    }

    private static function entryData(Check $check): array
    {
        $note = trim(implode(' ', array_filter([
            'Çek',
            $check->check_number ? 'no ' . $check->check_number : null,
            $check->due_date ? 'vade ' . $check->due_date->format('d.m.Y') : null,
        ])));

        return [
            'party_id'     => $check->party_id,
            'project_id'   => $check->project_id,
            'entry_date'   => ($check->issue_date ?? now())->toDateString(),
            'type'         => self::typeFor($check),
            'payment_type' => 'check',
            'amount'       => $check->amount,
            'description'  => $check->description ?: $note,
        ];
    }
}
