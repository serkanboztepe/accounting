<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractDelivery;
use App\Models\Party;

/**
 * WhatsApp bakiye cevabı için: carinin aktif alım sözleşmelerinin durumu —
 * kalem kalem kalan miktar, ödeme ve "ödenip henüz teslim alınmayan" tutar.
 * Cari ekstresi sözleşme ödemelerini/teslimatlarını içermez; yalnız ona bakmak
 * "bakiye 0" gibi yanıltıcı cevap veriyordu (Kuşak Beton: 36 m³ teslim alınmamıştı).
 */
class ContractStatus
{
    /** @return list<string> boşsa carinin aktif alım sözleşmesi yok */
    public static function lines(Party $party): array
    {
        // Contract::query() 'purchase' kapsamlı — satış sözleşmeleri girmez.
        $contracts = Contract::query()
            ->where('party_id', $party->id)
            ->where('status', 'active')
            ->with(['items.unit'])
            ->get();

        $lines = [];
        foreach ($contracts as $contract) {
            $lines[] = '📑 *Sözleşme: ' . ($contract->title ?: '#' . $contract->id) . '*';

            foreach ($contract->items as $item) {
                $qty = (float) $item->quantity;
                if ($qty <= 0) {
                    continue;
                }
                $delivered = (float) ContractDelivery::where('contract_item_id', $item->id)->sum('quantity');
                $remaining = max(0, $qty - $delivered);
                $unit = $item->unit?->code ?? '';
                $line = "• {$item->description}: " . self::qty($qty) . " {$unit} anlaşma, " . self::qty($delivered) . " {$unit} geldi";
                $line .= $remaining > 0
                    ? ' → *' . self::qty($remaining) . " {$unit} kalan*" . ((float) $item->unit_price > 0 ? ' (' . Money::format($remaining * (float) $item->unit_price) . ' ₺)' : '')
                    : ' → tamamı geldi ✅';
                $lines[] = $line;
            }

            $total = $contract->reportableTotal();
            $paid = $contract->paidAmount();
            $pendingCheck = $contract->pendingCheckAmount();
            $remainingPay = $contract->remainingPaymentAmount();
            $delivered = $contract->deliveredAmount();

            $pay = '• Ödeme: ' . Money::format($paid) . ' / ' . Money::format($total) . ' ₺';
            if ($pendingCheck > 0) {
                $pay .= ' (' . Money::format($pendingCheck) . ' ₺\'si vadesi gelmemiş çek)';
            }
            $pay .= $remainingPay > 0 ? ' → kalan ödeme *' . Money::format($remainingPay) . ' ₺*' : ' → ödemesi tamam';
            $lines[] = $pay;

            // Ödenen ile teslim alınan arasındaki fark: + = mal alacağın, − = ödenmemiş teslimat.
            $diff = $paid - $delivered;
            if ($diff >= 0.01) {
                $lines[] = '• Ödediğin ama henüz gelmeyen: *' . Money::format($diff) . ' ₺*';
            } elseif ($diff <= -0.01) {
                $lines[] = '• Teslim alınıp ödenmeyen: *' . Money::format(abs($diff)) . ' ₺*';
            }
        }

        return $lines;
    }

    private static function qty(float $q): string
    {
        return Money::format($q, fmod($q, 1.0) === 0.0 ? 0 : 2);
    }
}
