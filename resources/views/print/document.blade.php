<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ $company->title ?: 'Firma' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #111; margin: 0; padding: 24px; font-size: 13px; }
        .sheet { max-width: 800px; margin: 0 auto; }
        .letterhead { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 6px; }
        .letterhead .name { font-size: 18px; font-weight: bold; letter-spacing: .3px; }
        .letterhead .contact { color: #555; font-size: 11px; margin-top: 3px; }
        .doc-type { text-align: right; font-size: 12px; color: #666; margin-bottom: 18px; }
        .doc-type strong { color: #111; font-size: 14px; }
        .body { line-height: 1.6; }
        table.doc-table { width: 100%; border-collapse: collapse; margin: 10px 0 6px; }
        table.doc-table th, table.doc-table td { border: 1px solid #999; padding: 6px 8px; }
        table.doc-table th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        table.doc-table tbody tr:nth-child(even) { background: #fafafa; }
        table.doc-table tfoot td { border-top: 2px solid #333; background: #f0f0f0; }
        table.doc-sign { width: 100%; margin-top: 56px; border-collapse: collapse; }
        table.doc-sign td { width: 50%; text-align: center; font-size: 12px; padding: 0 24px; vertical-align: bottom; }
        table.doc-sign .sign-line { border-top: 1px solid #333; margin-bottom: 6px; height: 40px; }
        .toolbar { max-width: 800px; margin: 0 auto 16px; text-align: right; }
        .btn { font-size: 13px; padding: 8px 16px; border: 0; border-radius: 8px; background: #b45309; color: #fff; cursor: pointer; }
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
        <div class="letterhead">
            <div class="name">{{ $company->title ?: 'Firma Ünvanı (Ayarlar’dan girin)' }}</div>
            <div class="contact">
                @if ($company->address){{ $company->address }}@endif
                @if ($company->phone) · Tel: {{ $company->phone }}@endif
                @if ($company->email) · {{ $company->email }}@endif
                @if ($company->tax_number) · VN: {{ trim(($company->tax_office ?? '') . ' ' . $company->tax_number) }}@endif
            </div>
        </div>

        <div class="doc-type"><strong>{{ $title }}</strong></div>

        <div class="body">{!! $bodyHtml !!}</div>
    </div>
</body>
</html>
