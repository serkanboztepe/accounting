<?php

namespace App\Support;

use App\Models\Check;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Models\Quote;
use App\Models\Sale;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Cari silinmeden önce ne olacağını söyle. Veritabanı kuralları: cari hareketleri ve direkt satışlar
 * BİRLİKTE silinir; giderler carisiz kalır; çek / sözleşme / teklif varsa silinemez (önceden
 * veritabanı hatası veriyordu). Ferhat Bey vakası: "Sil" yalnız "Emin misiniz?" diyordu, 20.000 ₺'lik
 * tahsilat cariyle birlikte uyarısız gitti. 21.10: silmek için "Eminim Sil" yazılır; silinen her şey İşlem Geçmişi'nde ayrıntısıyla kalır.
 */
class PartyDeletion
{
    /** @return list<string> silmeyi engelleyenler ("2 çek") — boşsa silinebilir */
    public static function blockers(Party $party): array
    {
        return array_values(array_filter([
            self::count(Check::where('party_id', $party->id)->count(), 'çek'),
            self::count(Contract::withoutGlobalScopes()->where('party_id', $party->id)->count(), 'sözleşme'),
            class_exists(Quote::class) ? self::count(Quote::where('party_id', $party->id)->count(), 'teklif') : null,
            // Stoğa giren/çıkan belge: cari silinince veritabanı belgeyi ve stok satırlarını aynı anda silmeye çalışıp hata
            // veriyordu (21.10 testte) — stok da bozulurdu. Önce belge Satışlar/Alışlar'dan silinir (stok geri döner).
            self::count(Sale::where('party_id', $party->id)->whereHas('lines')->count(), 'stoklu satış'),
            self::count(\App\Models\Purchase::where('party_id', $party->id)->whereHas('lines')->count(), 'stoklu alış'),
        ]));
    }

    /**
     * Cariyi sil — veritabanı yine de reddederse ekran hata vermez, kullanıcıya nedeni söylenir; işlem geçmişine yazılan
     * "silindi" satırları da geri alınır (aynı işlem). true: silindi.
     */
    public static function delete(Party $party): bool
    {
        try {
            DB::transaction(fn () => $party->delete());

            return true;
        } catch (QueryException $e) {
            report($e);
            Notification::make()->danger()->title('Cari silinemedi')
                ->body('Bu cariye bağlı kayıtlar silinmeyi engelliyor. Önce carinin satış/alış belgelerini ve çeklerini silmen gerekir; '
                    . 'hiçbir şey silinmedi.')
                ->persistent()->send();

            return false;
        }
    }

    public static function description(Party $party): string
    {
        if ($blockers = self::blockers($party)) {
            return 'Bu cari silinemez: ' . implode(', ', $blockers) . ' bağlı. Önce onları silmen ya da başka cariye taşıman gerekir'
                . (preg_grep('/stoklu/', $blockers) ? ' (stoklu satış/alış Satışlar ya da Alışlar ekranından silinir, stok geri döner).' : '.');
        }

        $deleted = array_values(array_filter([
            self::count(PartyLedgerEntry::where('party_id', $party->id)->count(), 'cari hareketi'),
            self::count(Sale::where('party_id', $party->id)->count(), 'satış'),
            self::count(\App\Models\Purchase::where('party_id', $party->id)->count(), 'alış'),
        ]));
        $expenses = Expense::where('party_id', $party->id)->count();

        if ($deleted === [] && $expenses === 0) {
            return 'Bu carinin hiç hareketi yok, güvenle silinebilir.';
        }

        $balance = PartyStatement::build($party)['balance'];
        $text = $deleted
            ? '⚠️ Bu cariyle birlikte ' . implode(' ve ', $deleted) . ' de SİLİNECEK (' . rtrim(PartyBalances::line($party->name, $balance), '.') . '). Geri alınamaz.'
            : '';
        if ($expenses) {
            $text .= ($text ? ' ' : '') . "{$expenses} gider silinmez, carisiz kalır.";
        }

        return $text;
    }

    /** Cari silmede yazılması gereken söz (kullanıcı 21.10: tek tıkla carinin tüm hareketleri gitmesin). */
    public const CONFIRM_PHRASE = 'Eminim Sil';

    /**
     * "Eminim Sil" yazıldı mı — büyük/küçük harf, Türkçe I/İ/ı ve fazla boşluk fark etmez (telefon klavyesi baş harfi büyütür,
     * "EMINIM SIL" da kabul).
     */
    public static function confirmed(?string $typed): bool
    {
        $norm = fn (string $t) => preg_replace('/\s+/u', ' ', trim(str_replace('ı', 'i', mb_strtolower(str_replace(['İ', 'I'], 'i', $t)))));

        return $norm((string) $typed) === $norm(self::CONFIRM_PHRASE);
    }

    /** Silme penceresindeki onay kutusu — iki silme yerinde (liste satırı + cari sayfası) aynı. */
    public static function confirmField(): \Filament\Forms\Components\TextInput
    {
        return \Filament\Forms\Components\TextInput::make('confirm_phrase')
            ->label('Silmek için "' . self::CONFIRM_PHRASE . '" yaz')
            ->placeholder(self::CONFIRM_PHRASE)
            ->autocomplete(false)
            ->required()
            ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                if (! self::confirmed($value)) {
                    $fail('Silmek için tam olarak "' . self::CONFIRM_PHRASE . '" yazmalısın.');
                }
            })
            ->validationMessages(['required' => 'Silmek için "' . self::CONFIRM_PHRASE . '" yazmalısın.']);
    }

    private static function count(int $n, string $label): ?string
    {
        return $n > 0 ? "{$n} {$label}" : null;
    }
}
