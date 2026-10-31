<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $project->name }} — Rapor</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 13px; }
        .sheet { max-width: 820px; margin: 0 auto; }
        .letterhead { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 6px; }
        .letterhead .name { font-size: 18px; font-weight: bold; }
        .letterhead .contact { color: #555; font-size: 11px; margin-top: 3px; }
        .doc-type { text-align: right; font-size: 12px; color: #666; margin-bottom: 10px; }
        .doc-type strong { color: #111; font-size: 14px; }
        h2 { font-size: 14px; margin: 22px 0 4px; }
        table.st { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.st th, table.st td { border: 1px solid #999; padding: 5px 8px; vertical-align: top; }
        table.st th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; text-align: left; }
        table.st td.num, table.st th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.st td.date { white-space: nowrap; width: 82px; }
        table.st tr.group td { background: #f6f6f6; font-weight: bold; }
        table.st tr.sub td { border-top: 0; color: #444; font-size: 12px; }
        table.st tr.sub td.desc { padding-left: 22px; }
        table.st tfoot td { font-weight: bold; border-top: 2px solid #333; background: #f0f0f0; }
        table.sum td { border: 1px solid #999; padding: 6px 8px; }
        table.sum tr.total td { font-weight: bold; font-size: 15px; background: #f0f0f0; }
        .warn { color: #b45309; }
        .empty { color: #888; text-align: center; }
        .toolbar { max-width: 820px; margin: 0 auto 16px; text-align: right; }
        .btn { font-size: 13px; padding: 8px 16px; border: 0; border-radius: 8px; background: #0B7350; color: #fff; cursor: pointer; }
        @media print { .toolbar { display: none; } body { padding: 0; } @page { size: A4; margin: 14mm; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="toolbar"><button class="btn" onclick="window.print()">Yazdır / PDF Kaydet</button></div>

    @php
        $m = fn ($v) => '₺' . \App\Support\Money::format((float) $v);
    @endphp

    <div class="sheet">
        <div class="letterhead">
            <div class="name">{{ $company->title ?: 'Firma Ünvanı (Ayarlar’dan girin)' }}</div>
            <div class="contact">
                @if ($company->address){{ $company->address }}@endif
                @if ($company->phone) · Tel: {{ $company->phone }}@endif
            </div>
        </div>

        <div class="doc-type"><strong>{{ mb_strtoupper($title) }}</strong><br>{{ $project->name }} · {{ now()->format('d.m.Y') }}</div>

        <table class="st sum">
            <tbody>
                <tr><td>Direkt gider</td><td class="num">{{ $m($summary['direct_expenses']) }}</td></tr>
                <tr><td>Alış (iş / malzeme)</td><td class="num">{{ $m($summary['purchase_cost']) }}</td></tr>
                @if ($withContracts)
                    <tr><td>Sözleşme teslimatı</td><td class="num">{{ $m($summary['delivery_cost']) }}</td></tr>
                @endif
                @if (($summary['unbooked_total'] ?? 0) > 0)
                    <tr><td class="warn">⚠ Alışı yazılmamış ödeme</td><td class="num warn">{{ $m($summary['unbooked_total']) }}</td></tr>
                    @foreach ($summary['unbooked_payments'] as $row)
                        <tr class="sub"><td class="desc">{{ $row['party'] }} — para verildi, alışı yazılmadı</td><td class="num">{{ $m($row['amount']) }}</td></tr>
                    @endforeach
                @endif
                <tr class="total"><td>Toplam Proje Maliyeti</td><td class="num">{{ $m($summary['total_cost']) }}</td></tr>
            </tbody>
        </table>

        <h2>Direkt Giderler</h2>
        <table class="st">
            <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
            <tbody>
                @forelse ($expenses as $group)
                    <tr class="group"><td colspan="2">{{ $group['category'] }}</td><td class="num">{{ $m($group['total']) }}</td></tr>
                    @foreach ($group['items'] as $item)
                        <tr><td class="date">{{ $item['date'] }}</td><td>{{ $item['description'] ?: '—' }}</td><td class="num">{{ $m($item['amount']) }}</td></tr>
                    @endforeach
                @empty
                    <tr><td colspan="3" class="empty">Gider yok.</td></tr>
                @endforelse
            </tbody>
            @if (count($expenses) > 0)
                <tfoot><tr><td colspan="2">Toplam</td><td class="num">{{ $m(array_sum(array_column($expenses, 'total'))) }}</td></tr></tfoot>
            @endif
        </table>

        <h2>Alışlar</h2>
        <table class="st">
            <thead><tr><th>Tarih</th><th>Açıklama / Kalem</th><th class="num">Tutar</th></tr></thead>
            <tbody>
                @forelse ($purchases as $group)
                    <tr class="group"><td colspan="2">{{ $group['party'] }}</td><td class="num">{{ $m($group['total']) }}</td></tr>
                    @foreach ($group['items'] as $item)
                        <tr><td class="date">{{ $item['date'] }}</td><td>{{ $item['description'] }}</td><td class="num">{{ $m($item['amount']) }}</td></tr>
                        @foreach ($item['lines'] as $line)
                            <tr class="sub">
                                <td></td>
                                <td class="desc"><b>{{ $line['name'] }}</b>@if ($line['detail']) · {{ $line['detail'] }}@endif</td>
                                <td class="num">@if (count($item['lines']) > 1){{ $m($line['amount']) }}@endif</td>
                            </tr>
                        @endforeach
                    @endforeach
                @empty
                    <tr><td colspan="3" class="empty">Alış yok.</td></tr>
                @endforelse
            </tbody>
            @if (count($purchases) > 0)
                <tfoot><tr><td colspan="2">Toplam</td><td class="num">{{ $m(array_sum(array_column($purchases, 'total'))) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</body>
</html>
