<?php

namespace App\Support;

use App\Models\CompanySettings;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;

class DocumentRenderer
{
    public const TYPE_QUOTE = 'quote';
    public const TYPE_CONTRACT = 'contract';

    /**
     * Teklif/sözleşme için şablonu doldurup gövde HTML'i üretir.
     */
    public static function render(Model $doc, string $type): string
    {
        $company  = CompanySettings::current();
        $template = trim((string) ($type === self::TYPE_QUOTE
            ? $company->quote_template
            : $company->contract_template));

        if ($template === '') {
            $template = self::defaultTemplate($type);
        }

        $validUntil = $doc instanceof Quote ? $doc->valid_until : null;
        $docDate    = $doc instanceof Quote ? $doc->quote_date : ($doc->contract_date ?? null);

        $replacements = [
            '<<firma_ad>>'      => e($company->title),
            '<<firma_adres>>'   => e($company->address),
            '<<firma_telefon>>' => e($company->phone),
            '<<firma_vergi>>'   => e(trim(($company->tax_office ?? '') . ' ' . ($company->tax_number ?? ''))),
            '<<cari_ad>>'       => e($doc->party?->name),
            '<<baslik>>'        => e($doc->title),
            '<<tarih>>'         => $docDate ? e($docDate->format('d.m.Y')) : '',
            '<<gecerlilik>>'    => $validUntil ? e($validUntil->format('d.m.Y')) : '-',
            '<<proje_ad>>'      => e($doc->project?->name ?? '-'),
            '<<kalem_tablosu>>' => self::itemsTable($doc),
            '<<toplam>>'        => e(Money::format((float) $doc->reportableTotal()) . ' ₺'),
            '<<odeme_plani>>'   => self::paymentPlanTable($doc->payment_plan ?? []),
            '<<imza_alani>>'    => self::signatureBlock($company->title, $doc->party?->name),
        ];

        return nl2br(strtr($template, $replacements));
    }

    protected static function itemsTable(Model $doc): string
    {
        $rows = '';
        foreach ($doc->items as $item) {
            $unit = e($item->unit?->code ?? $item->unit?->name ?? '');
            $qty  = $item->quantity !== null ? Money::format((float) $item->quantity) : '';
            $rows .= '<tr>'
                . '<td>' . e($item->description) . '</td>'
                . '<td style="text-align:right">' . $qty . '</td>'
                . '<td>' . $unit . '</td>'
                . '<td style="text-align:right">' . ($item->unit_price !== null ? Money::format((float) $item->unit_price) . ' ₺' : '') . '</td>'
                . '<td style="text-align:right">' . Money::format((float) $item->amount) . ' ₺</td>'
                . '</tr>';
        }

        $total = Money::format((float) $doc->reportableTotal());

        return '<table class="doc-table"><thead><tr>'
            . '<th>Kalem</th><th style="text-align:right">Miktar</th><th>Birim</th>'
            . '<th style="text-align:right">Birim Fiyat</th><th style="text-align:right">Tutar</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td colspan="4" style="text-align:right"><strong>Genel Toplam</strong></td>'
            . '<td style="text-align:right"><strong>' . $total . ' ₺</strong></td></tr></tfoot>'
            . '</table>';
    }

    protected static function paymentPlanTable(array $plan): string
    {
        if (empty($plan)) {
            return '<em>—</em>';
        }

        $rows = '';
        foreach ($plan as $row) {
            $type   = ContractPayment::PAYMENT_TYPES[$row['payment_type'] ?? ''] ?? ($row['payment_type'] ?? '');
            $date   = ! empty($row['date']) ? e(date('d.m.Y', strtotime($row['date']))) : '';
            $amount = isset($row['amount']) ? Money::format(Money::parse($row['amount'])) . ' ₺' : '';
            $note   = e($row['note'] ?? '');
            $rows .= '<tr>'
                . '<td>' . $date . '</td>'
                . '<td>' . e($type) . '</td>'
                . '<td style="text-align:right">' . $amount . '</td>'
                . '<td>' . $note . '</td>'
                . '</tr>';
        }

        return '<table class="doc-table"><thead><tr>'
            . '<th>Tarih</th><th>Ödeme Tipi</th><th style="text-align:right">Tutar</th><th>Açıklama</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    protected static function signatureBlock(?string $companyTitle, ?string $partyName): string
    {
        return '<table class="doc-sign"><tr>'
            . '<td><div class="sign-line"></div>' . e($companyTitle ?: 'Firma') . '</td>'
            . '<td><div class="sign-line"></div>' . e($partyName ?: 'Karşı Taraf') . '</td>'
            . '</tr></table>';
    }

    protected static function defaultTemplate(string $type): string
    {
        if ($type === self::TYPE_QUOTE) {
            return "Sayın <<cari_ad>>,\n\n"
                . "<<baslik>> için teklifimiz aşağıdadır.\n\n"
                . "<<kalem_tablosu>>\n\n"
                . "Ödeme Planı:\n<<odeme_plani>>\n\n"
                . "Geçerlilik Tarihi: <<gecerlilik>>\n\n"
                . "<<imza_alani>>";
        }

        return "<<baslik>>\n\n"
            . "İşbu sözleşme <<tarih>> tarihinde <<firma_ad>> ile <<cari_ad>> arasında aşağıdaki kalemler için akdedilmiştir.\n\n"
            . "<<kalem_tablosu>>\n\n"
            . "Ödeme Planı:\n<<odeme_plani>>\n\n"
            . "<<imza_alani>>";
    }
}
