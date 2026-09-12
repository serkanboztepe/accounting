<x-filament-panels::page>
    @php $rows = $this->getRows(); @endphp

    <div style="text-align:right;margin-bottom:10px;">
        <a href="{{ route('stock-report.print') }}" target="_blank"
           style="display:inline-block;padding:8px 16px;border-radius:8px;background:#b45309;color:#fff;font-size:13px;text-decoration:none;">
            Yazdır / PDF
        </a>
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#f0f0f0;text-align:left;">
                    <th style="border:1px solid #ccc;padding:6px 10px;">Ürün</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;">Birim</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;text-align:right;">Giren</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;text-align:right;">Çıkan</th>
                    <th style="border:1px solid #ccc;padding:6px 10px;text-align:right;">Mevcut</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td style="border:1px solid #ccc;padding:6px 10px;">{{ $r['name'] }}</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;">{{ $r['unit'] }}</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;">{{ \App\Support\Money::format($r['in']) }}</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;">{{ \App\Support\Money::format($r['out']) }}</td>
                        <td style="border:1px solid #ccc;padding:6px 10px;text-align:right;font-weight:bold;color:{{ $r['current'] < 0 ? '#b91c1c' : '#111' }};">
                            {{ \App\Support\Money::format($r['current']) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:12px;">Stoklu ürün yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
