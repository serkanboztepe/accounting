<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $project->name }} — Rapor</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 15px; }
        /* Yazı boyu sırası (3.11): firma 22 › rapor + proje adı = bölüm başlığı 19 › cari/kategori 16 › satır 15 › alt kalem 14. */
        .sheet { max-width: 820px; margin: 0 auto; }
        .letterhead { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 6px; }
        .letterhead .name { font-size: 22px; font-weight: bold; }
        .letterhead .contact { color: #555; font-size: 13px; margin-top: 3px; }
        .doc-type { text-align: right; margin-bottom: 12px; }
        .doc-type .t { color: #111; font-size: 19px; font-weight: bold; line-height: 1.35; }
        .doc-type .d { color: #666; font-size: 13px; margin-top: 2px; }
        h2 { font-size: 19px; margin: 26px 0 6px; }
        table.st { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.st th, table.st td { border: 1px solid #999; padding: 6px 9px; vertical-align: top; }
        table.st th { background: #f0f0f0; font-size: 12px; text-transform: uppercase; letter-spacing: .3px; text-align: left; }
        table.st td.num, table.st th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        table.st td.date { white-space: nowrap; width: 82px; }
        table.st tr.group td { background: #f6f6f6; font-weight: bold; font-size: 16px; }
        table.st tr.sub td { border-top: 0; color: #444; font-size: 14px; }
        table.st tr.sub td.desc { padding-left: 22px; }
        /* Tutar sütunu her satırda aynı boy ve biraz büyük (PDF'te okunacak, 3.11). */
        table.st td.num, table.st tr.sub td.num { font-size: 15px; color: #111; }
        table.st tr.foot td { font-weight: bold; border-top: 2px solid #333; background: #f0f0f0; }
        /* Bir carinin / kategorinin satırları sayfa arasında bölünmesin (başlık bir sayfada, kalemler öbüründe kalmasın). */
        /* Küçük grup (≤ 8 satır) bölünmez; büyük grup bölünebilir — yoksa sığmayan büyük grup sayfanın yarısını boş bırakıyordu (3.11).
           Grup başlığı ve bölüm başlığı sayfa sonunda tek başına kalmaz. */
        table.st tbody.keep { break-inside: avoid; page-break-inside: avoid; }
        table.st tr.group { break-after: avoid; page-break-after: avoid; }
        h2 { break-after: avoid; page-break-after: avoid; }
        table.sum td { border: 1px solid #999; padding: 6px 8px; }
        table.sum tr.total td { font-weight: bold; font-size: 19px; background: #f0f0f0; }
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

        <div class="doc-type">
            <div class="t">{{ mb_strtoupper($title) }}</div>
            <div class="t">{{ $project->name }}</div>
            <div class="d">{{ now()->format('d.m.Y') }}</div>
        </div>

        <table class="st sum">
            <tbody>
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

        <h2>Giderler</h2>
        <table class="st">
            <thead><tr><th>Tarih</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
            @forelse ($expenses as $group)
                <tbody @class(['keep' => count($group['items']) <= 8])>
                    <tr class="group"><td colspan="3">{{ $group['category'] }}</td></tr>
                    @foreach ($group['items'] as $item)
                        <tr><td class="date">{{ $item['date'] }}</td><td>{{ $item['description'] ?: '—' }}</td><td class="num">{{ $m($item['amount']) }}</td></tr>
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="3" class="empty">Gider yok.</td></tr></tbody>
            @endforelse
            {{-- Bölüm toplamı yok (3.11 kullanıcı: kafa karıştırıyor) — tek rakam üstteki Toplam Proje Maliyeti. --}}
        </table>

        <h2>Alışlar</h2>
        <table class="st">
            <thead><tr><th>Tarih</th><th>Açıklama / Kalem</th><th class="num">Tutar</th></tr></thead>
            @forelse ($purchases as $group)
                @php
                    $rowCount = array_sum(array_map(fn ($i) => 1 + count($i['sub_lines']), $group['items']));
                @endphp
                <tbody @class(['keep' => $rowCount <= 8])>
                    <tr class="group"><td colspan="3">{{ $group['party'] }}</td></tr>
                    @foreach ($group['items'] as $item)
                        <tr><td class="date">{{ $item['date'] }}</td><td>@if ($item['title_line'])<b>{{ $item['title_line']['name'] }}</b>@if ($item['title_line']['detail']) · {{ $item['title_line']['detail'] }}@endif @else{{ $item['title'] }}@endif</td><td class="num">@if ($item['title_amount'] !== null){{ $m($item['title_amount']) }}@endif</td></tr>
                        @foreach ($item['sub_lines'] as $line)
                            {{-- Tarih satırına çıkan kalemin devamıysa (aynı ad) o satırla birebir aynı görünüm; değilse başlık altı kalem. --}}
                            <tr @class(['sub' => ! $item['title_line']])>
                                <td></td>
                                <td @class(['desc' => ! $item['title_line']])><b>{{ $line['name'] }}</b>@if ($line['detail']) · {{ $line['detail'] }}@endif</td>
                                <td class="num">{{ $m($line['amount']) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            @empty
                <tbody><tr><td colspan="3" class="empty">Alış yok.</td></tr></tbody>
            @endforelse
        </table>
    </div>
</body>
</html>
