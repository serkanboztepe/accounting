<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hisse Dağılım Cetveli — {{ $study->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 13px; }
        .sheet { max-width: 800px; margin: 0 auto; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 2px; letter-spacing: .5px; }
        .meta { text-align: center; color: #444; font-size: 12px; margin-bottom: 16px; }
        .meta strong { color: #111; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #999; padding: 6px 8px; }
        th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tfoot td { font-weight: bold; border-top: 2px solid #333; background: #f0f0f0; }
        .tag { font-size: 10px; background: #e5efff; color: #1d4ed8; padding: 1px 5px; border-radius: 4px; }
        .toolbar { max-width: 800px; margin: 0 auto 16px; text-align: right; }
        .btn { font-size: 13px; padding: 8px 16px; border: 0; border-radius: 8px; background: #b45309; color: #fff; cursor: pointer; }
        .foot-note { max-width: 800px; margin: 14px auto 0; color: #666; font-size: 11px; }
        .signs { display: flex; justify-content: space-between; margin-top: 48px; gap: 40px; }
        .signs div { flex: 1; text-align: center; border-top: 1px solid #333; padding-top: 6px; font-size: 12px; }
        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
            @page { size: A4; margin: 16mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Yazdır / PDF Kaydet</button>
    </div>

    <div class="sheet">
        <h1>HİSSE DAĞILIM CETVELİ</h1>
        <div class="meta">
            <strong>{{ $study->name }}</strong>
            @if ($study->ada || $study->parsel) · {{ $study->ada }} Ada / {{ $study->parsel }} Parsel @endif
            @if ($study->project) · {{ $study->project->name }} @endif
            <br>
            {{ now()->format('d.m.Y') }}
        </div>

        <table>
            <thead>
                <tr>
                    <th>Hissedar</th>
                    <th class="num">Mevcut Hisse</th>
                    <th class="num">Devredilen (Satılan)</th>
                    <th class="num">Kalan Hisse (/{{ $common }})</th>
                    <th class="num">Satış Sonu Sade</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    <tr>
                        <td>
                            {{ $row->name }}
                            @if ($row->isContractor) <span class="tag">Müteahhit</span> @endif
                        </td>
                        <td class="num">{{ $row->current }}</td>
                        <td class="num">{{ $row->sold->isZero() ? '—' : $row->sold->numeratorOver($common) . '/' . $common }}</td>
                        <td class="num">{{ $row->remaining->numeratorOver($common) }}/{{ $common }}</td>
                        <td class="num">{{ $row->remaining }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>TOPLAM</td>
                    <td class="num">—</td>
                    <td class="num">{{ $result->totalSold()->numeratorOver($common) }}/{{ $common }}</td>
                    <td class="num">{{ $result->totalRemaining()->numeratorOver($common) }}/{{ $common }}</td>
                    <td class="num">{{ $result->isBalanced() ? '1/1' : (string) $result->totalRemaining() }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="foot-note">
            Mevcut hisseler ile satış sonu kalan hisseler toplamı 1/1'dir. Devredilen sütunu, her hissedarın müteahhide / yeni hak sahibine aktardığı payı gösterir.
        </div>

        <div class="signs">
            <div>Hazırlayan</div>
            <div>Onaylayan</div>
        </div>
    </div>

    <script>
        // Yeni sekmede açılınca yazdırma penceresini otomatik aç.
        if (new URLSearchParams(location.search).get('auto') === '1') {
            window.addEventListener('load', () => setTimeout(() => window.print(), 300));
        }
    </script>
</body>
</html>
