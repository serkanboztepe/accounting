<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Satış Özeti</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 13px; }
        .sheet { max-width: 820px; margin: 0 auto; }
        .letterhead { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 6px; }
        .letterhead .name { font-size: 18px; font-weight: bold; }
        .letterhead .contact { color: #555; font-size: 11px; margin-top: 3px; }
        .doc-type { text-align: right; font-size: 12px; color: #666; margin-bottom: 6px; }
        .doc-type strong { color: #111; font-size: 14px; }
        table.st { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.st th, table.st td { border: 1px solid #999; padding: 5px 8px; }
        table.st th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; text-align: left; }
        table.st td.num, table.st th.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.st tbody tr:nth-child(even) { background: #fafafa; }
        table.st tfoot td { font-weight: bold; border-top: 2px solid #333; background: #f0f0f0; }
        .toolbar { max-width: 820px; margin: 0 auto 16px; text-align: right; }
        .btn { font-size: 13px; padding: 8px 16px; border: 0; border-radius: 8px; background: #b45309; color: #fff; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } @page { size: A4; margin: 14mm; } }
    </style>
</head>
<body>
    <div class="toolbar"><button class="btn" onclick="window.print()">Yazdır / PDF Kaydet</button></div>

    <div class="sheet">
        <div class="letterhead">
            <div class="name">{{ $company->title ?: 'Firma Ünvanı (Ayarlar’dan girin)' }}</div>
            <div class="contact">
                @if ($company->address){{ $company->address }}@endif
                @if ($company->phone) · Tel: {{ $company->phone }}@endif
            </div>
        </div>

        <div class="doc-type"><strong>SATIŞ ÖZETİ</strong> ({{ $groupLabel }} bazında)<br>{{ now()->format('d.m.Y') }}</div>

        <table class="st">
            <thead>
                <tr>
                    <th>{{ $groupLabel }}</th>
                    <th class="num">Brüt Satış</th>
                    <th class="num">İade</th>
                    <th class="num">Net</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $r)
                    <tr>
                        <td>{{ $r['name'] }}</td>
                        <td class="num">{{ \App\Support\Money::format($r['gross']) }}</td>
                        <td class="num">{{ \App\Support\Money::format($r['ret']) }}</td>
                        <td class="num">{{ \App\Support\Money::format($r['net']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:#888">Satış yok.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td>Toplam Net</td>
                    <td colspan="2"></td>
                    <td class="num">{{ \App\Support\Money::format(collect($rows)->sum('net')) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</body>
</html>
