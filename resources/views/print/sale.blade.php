<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Satış Fişi #{{ $sale->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 13px; }
        .sheet { max-width: 820px; margin: 0 auto; }
        .letterhead { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 6px; }
        .letterhead .name { font-size: 18px; font-weight: bold; }
        .letterhead .contact { color: #555; font-size: 11px; margin-top: 3px; }
        .doc-type { text-align: right; font-size: 12px; color: #666; margin-bottom: 6px; }
        .doc-type strong { color: #111; font-size: 14px; }
        .meta { font-size: 13px; margin: 10px 0; }
        .meta .cari { font-size: 15px; font-weight: bold; }
        table.st { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.st th, table.st td { border: 1px solid #999; padding: 5px 8px; }
        table.st th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; text-align: left; }
        table.st td.num, table.st th.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.st tfoot td { font-weight: bold; border-top: 2px solid #333; background: #f0f0f0; }
        .section-title { margin: 16px 0 4px; font-weight: bold; color: #92400e; }
        .totals { margin-top: 14px; text-align: right; font-size: 14px; }
        .totals strong { font-size: 16px; }
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

        <div class="doc-type"><strong>SATIŞ FİŞİ #{{ $sale->id }}</strong><br>{{ $sale->sale_date->format('d.m.Y') }}</div>

        <div class="meta">
            <div class="cari">{{ $sale->party?->name }}</div>
            @if ($sale->project)<div style="color:#555;">Şantiye: {{ $sale->project->name }}</div>@endif
        </div>

        <table class="st">
            <thead>
                <tr>
                    <th>Ürün</th>
                    <th class="num">Miktar</th>
                    <th class="num">Birim Fiyat</th>
                    <th class="num">Tutar</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sale->lines as $line)
                    <tr>
                        <td>{{ $line->product?->name ?? '—' }}</td>
                        <td class="num">{{ \App\Support\Money::format((float) $line->quantity) }}</td>
                        <td class="num">{{ $line->unit_price !== null ? \App\Support\Money::format((float) $line->unit_price) : '—' }}</td>
                        <td class="num">{{ \App\Support\Money::format((float) $line->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">Satış Toplamı</td>
                    <td class="num">{{ \App\Support\Money::format((float) $sale->total_amount) }} ₺</td>
                </tr>
            </tfoot>
        </table>

        @php $returns = $sale->returns; $returnTotal = (float) $returns->sum('amount'); @endphp
        @if ($returns->count() > 0)
            <div class="section-title">İadeler</div>
            <table class="st">
                <thead>
                    <tr>
                        <th>Ürün</th>
                        <th class="num">Miktar</th>
                        <th class="num">Tutar</th>
                        <th>Tarih</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($returns as $r)
                        <tr>
                            <td>{{ $r->product?->name ?? '—' }}</td>
                            <td class="num">{{ \App\Support\Money::format((float) $r->quantity) }}</td>
                            <td class="num">{{ \App\Support\Money::format((float) $r->amount) }}</td>
                            <td>{{ $r->movement_date->format('d.m.Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="totals">
                Satış: {{ \App\Support\Money::format((float) $sale->total_amount) }} ₺ −
                İade: {{ \App\Support\Money::format($returnTotal) }} ₺ =
                <strong>Net: {{ \App\Support\Money::format((float) $sale->total_amount - $returnTotal) }} ₺</strong>
            </div>
        @else
            <div class="totals"><strong>Toplam: {{ \App\Support\Money::format((float) $sale->total_amount) }} ₺</strong></div>
        @endif
    </div>
</body>
</html>
