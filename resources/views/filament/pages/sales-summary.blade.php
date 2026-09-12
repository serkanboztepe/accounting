<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $groupLabel = $groupBy === 'project' ? 'Şantiye / Proje' : 'Müşteri';
        $totalNet = collect($rows)->sum('net');
    @endphp

    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:10px;flex-wrap:wrap;">
        <label style="font-size:13px;">
            Gruplama:
            <select wire:model.live="groupBy" style="padding:6px 10px;border:1px solid #ccc;border-radius:6px;">
                <option value="party">Müşteriye göre</option>
                <option value="project">Şantiyeye / projeye göre</option>
            </select>
        </label>
        <a href="{{ route('sales-summary.print', ['group' => $groupBy]) }}" target="_blank"
           style="display:inline-block;padding:8px 16px;border-radius:8px;background:#b45309;color:#fff;font-size:13px;text-decoration:none;">
            Yazdır / PDF
        </a>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f0f0f0;text-align:left;">
                    <th style="border:1px solid #ccc;padding:6px 10px;">{{ $groupLabel }}</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;text-align:right;">Brüt Satış</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;text-align:right;">İade</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;text-align:right;">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td style="border:1px solid #ccc;padding:6px 10px;">{{ $r['name'] }}</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;">{{ \App\Support\Money::format($r['gross']) }} ₺</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;color:#b45309;">{{ \App\Support\Money::format($r['ret']) }} ₺</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;font-weight:bold;">{{ \App\Support\Money::format($r['net']) }} ₺</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:#888;padding:12px;">Satış yok.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr style="background:#f7f7f7;font-weight:bold;">
                    <td style="border:1px solid #ccc;padding:6px 10px;">Toplam Net</td>
                    <td colspan="2" style="border:1px solid #ccc;"></td>
                    <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;">{{ \App\Support\Money::format($totalNet) }} ₺</td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament-panels::page>
