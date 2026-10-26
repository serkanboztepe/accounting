<?php

namespace App\Support\Forms;

use App\Models\Party;
use App\Models\PartyLedgerEntry;
use App\Support\AgreementBalances;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\Radio;
use Filament\Schemas\Components\Utilities\Get;

/**
 * "Hangisi için?" — ödeme / tahsilat penceresinde açık liste (kullanıcı 26.10: hep sorsun, açılır kutu değil). Carinin
 * arşivlenmemiş anlaşması yoksa alan görünmez. Her seçeneğin yanında tutara göre sonuç cümlesi (AgreementBalances::sentence);
 * tutar alanı onBlur canlı olmalı ki cümleler güncellensin. Değer: anlaşma id'si ya da OTHER (anlaşma dışı).
 */
final class AgreementPaymentChoice
{
    public const OTHER = 'other';

    /**
     * @param  Closure(Get): ?Party  $party
     * @param  Closure(Get): ?string|string  $ledgerType  ödeme / tahsilat tipi
     * @param  Closure(mixed $record): ?PartyLedgerEntry|null  $editing  kayıtlı ödemeyi bağlarken / düzenlerken o satır
     *         ($record = formun kaydı: cari sayfasında cari, Çekler ekranında çek)
     */
    public static function field(Closure $party, Closure|string $ledgerType, string $amountField = 'amount', ?Closure $editing = null): Radio
    {
        $choices = function (Get $get, mixed $record) use ($party, $ledgerType, $editing): array {
            $p = $party($get);
            $type = is_string($ledgerType) ? $ledgerType : $ledgerType($get);

            return $p && $type ? AgreementBalances::choices($p, $type, null, $editing ? $editing($record) : null) : [];
        };

        return Radio::make('agreement_choice')
            ->label('Hangisi için?')
            ->options(function (Get $get, mixed $record = null) use ($choices, $ledgerType, $amountField): array {
                $type = is_string($ledgerType) ? $ledgerType : $ledgerType($get);
                $direction = $type ? AgreementBalances::directionFor($type) : null;
                $amount = Money::parse($get($amountField));
                $options = [];
                foreach ($choices($get, $record) as $c) {
                    $options[$c['id'] ?? self::OTHER] = $c['label'] . ': ' . AgreementBalances::sentence($c['balance'], $amount, $direction);
                }

                return $options;
            })
            ->visible(fn (Get $get, mixed $record = null): bool => $choices($get, $record) !== [])
            ->required(fn (Get $get, mixed $record = null): bool => $choices($get, $record) !== [])
            ->validationMessages(['required' => 'Hangi anlaşma için olduğunu seç.'])
            ->columnSpanFull();
    }

    /** Formdaki seçim → agreement_id (diğer / alan yok → null). Yalnız bu carinin anlaşması kabul edilir. */
    public static function agreementId(mixed $choice, ?int $partyId): ?int
    {
        if (blank($choice) || $choice === self::OTHER || ! $partyId) {
            return null;
        }

        return \App\Models\Agreement::where('party_id', $partyId)->whereKey((int) $choice)->value('id');
    }
}
