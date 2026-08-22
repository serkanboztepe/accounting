<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cari Ekstresi — {{ $party->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 13px; }
        .sheet { max-width: 820px; margin: 0 auto; }
        .letterhead { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 6px; }
        .letterhead .name { font-size: 18px; font-weight: bold; }
        .letterhead .contact { color: #555; font-size: 11px; margin-top: 3px; }
        .doc-type { text-align: right; font-size: 12px; color: #666; margin-bottom: 6px; }
        .doc-type strong { color: #111; font-size: 14px; }
        .cari { font-size: 15px; font-weight: bold; margin: 10px 0 4px; }
        table.st { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.st th, table.st td { border: 1px solid #999; padding: 5px 8px; }
        table.st th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        table.st td.num, table.st th.num { text-align: right; font-variant-numeric: tabular-nums; }
        table.st tbody tr:nth-child(even) { background: #fafafa; }
        table.st tfoot td { font-weight: bold; border-top: 2px solid #333; background: #f0f0f0; }
        .tag { font-size: 9px; background: #fde68a; color: #92400e; padding: 1px 4px; border-radius: 3px; }
        .balance { margin-top: 14px; text-align: right; font-size: 14px; }
        .balance strong { font-size: 16px; }
        .toolbar { max-width: 820px; margin: 0 auto 16px; text-align: right; }
        .btn { font-size: 13px; padding: 8px 16px; border: 0; border-radius: 8px; background: #b45309; color: #fff; cursor: pointer; }
        @media print {
            .toolbar { display: none; }
            body { padding: 0; }
            @page { size: A4; margin: 14mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="btn" onclick="window.print()">Yazdır / PDF Kaydet</button>
    </div>

    <div class="sheet">
        <div class="letterhead">
            <div class="name">{{ $company->title ?: 'Firma Ünvanı (Ayarlar’dan girin)' }}</div>
            <div class="contact">
                @if ($company->address){{ $company->address }}@endif
                @if ($company->phone) · Tel: {{ $company->phone }}@endif
                @if ($company->tax_number) · VN: {{ trim(($company->tax_office ?? '') . ' ' . $company->tax_number) }}@endif
            </div>
        </div>

        <div class="doc-type"><strong>CARİ EKSTRESİ</strong><br>{{ now()->format('d.m.Y') }}</div>

        <div class="cari">{{ $party->name }}</div>

        @php
            $filters = $filters ?? [];
            $rangeParts = [];
            if (! empty($filters['date_from'])) { $rangeParts[] = \Illuminate\Support\Carbon::parse($filters['date_from'])->format('d.m.Y') . ' başlangıç'; }
            if (! empty($filters['date_to'])) { $rangeParts[] = \Illuminate\Support\Carbon::parse($filters['date_to'])->format('d.m.Y') . ' bitiş'; }
        @endphp
        @if (count($rangeParts) > 0 || ! empty($projectName))
            <div style="color:#555;font-size:11px;margin-bottom:4px;">
                @if (count($rangeParts) > 0)
                    Dönem: {{ implode(' – ', $rangeParts) }}
                @endif
                @if (! empty($projectName))
                    @if (count($rangeParts) > 0) · @endif Proje: {{ $projectName }}
                @endif
            </div>
        @endif

        <table class="st">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Açıklama</th>
                    <th class="num">Borç</th>
                    <th class="num">Alacak</th>
                    <th class="num">Bakiye</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($statement['rows'] as $r)
                    <tr>
                        <td>{{ $r['date'] }}</td>
                        <td>
                            {{ $r['desc'] }} <span style="color:#888">· {{ $r['label'] }}</span>
                        </td>
                        <td class="num">{{ $r['borc'] > 0 ? \App\Support\Money::format($r['borc']) : '' }}</td>
                        <td class="num">{{ $r['alacak'] > 0 ? \App\Support\Money::format($r['alacak']) : '' }}</td>
                        <td class="num">{{ \App\Support\Money::format($r['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:#888">Hareket yok.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Toplam</td>
                    <td class="num">{{ \App\Support\Money::format($statement['total_borc']) }}</td>
                    <td class="num">{{ \App\Support\Money::format($statement['total_alacak']) }}</td>
                    <td class="num">{{ \App\Support\Money::format($statement['balance']) }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="balance">
            Bakiye: <strong>₺{{ \App\Support\Money::format(abs($statement['balance'])) }}</strong>
            — {{ $statement['balance'] >= 0 ? 'cari bize borçlu' : 'biz cariye borçluyuz' }}
        </div>
    </div>
</body>
</html>
