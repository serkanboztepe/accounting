@php
    /** @var \App\Models\PropertyTaxBlock $block */
    $project = $block->project;
    $units = $block->units;
    $pages = $units->chunk(3)->map(fn ($c) => $c->values());

    $fmtNum = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') : '';
    $verilis = $project->filing_reason === 'change' ? 'Değişiklik' : 'İlk İktisap';

    // Kroki: kat başına daireler yan yana (ızgara), üst kat en üstte
    $byFloor = [];
    $maxPos = 1;
    foreach ($units as $u) {
        $byFloor[$u->floor_no ?? 1][$u->floor_position ?? 1] = $u;
        $maxPos = max($maxPos, $u->floor_position ?? 1);
    }
    krsort($byFloor);
@endphp
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<style>
    @page { size: A4 portrait; margin: 10mm; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 0; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    h1 { text-align: center; font-size: 14px; margin: 0 0 8px; }
    .muted { color: #555; }
    .top { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
    .top td { padding: 2px 4px; vertical-align: top; }
    .kv { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .kv td { border: 1px solid #999; padding: 3px 5px; }
    .kv .lbl { background: #f2f2f2; font-weight: bold; width: 30%; }
    table.bina { width: 100%; border-collapse: collapse; }
    table.bina th, table.bina td { border: 1px solid #999; padding: 3px 5px; font-size: 9px; }
    table.bina th { background: #f2f2f2; }
    table.bina .field { text-align: left; width: 40%; }
    table.bina .val { text-align: center; }
    .sign { margin-top: 10px; width: 100%; }
    .sign td { padding: 6px; vertical-align: bottom; }
    .note { margin-top: 10px; font-size: 8px; color: #333; white-space: pre-line; border-top: 1px solid #ccc; padding-top: 4px; }

    /* Kroki (ızgara: kat başına daireler yan yana) */
    .kroki { text-align: center; }
    .kroki h2 { font-size: 13px; margin: 0 0 14px; }
    .kroki-grid { border-collapse: collapse; margin: 0 auto; }
    .kroki-grid .kat { font-weight: bold; font-size: 10px; padding: 0 10px 0 0; border: none; white-space: nowrap; }
    .kroki-grid .dbox { border: 1.5px solid #333; width: 95px; height: 48px; text-align: center; vertical-align: middle; padding: 2px; }
    .kroki-grid .dbox .no { font-weight: bold; font-size: 10px; }
    .kroki-grid .dbox .m2 { font-size: 9px; color: #333; }
    .kroki-grid .empty { border: none; width: 95px; }
    .ozet { margin: 16px auto 0; border-collapse: collapse; }
    .ozet td { border: 1px solid #999; padding: 4px 8px; font-size: 9px; }
    .ozet .h { background: #f2f2f2; font-weight: bold; }
</style>
</head>
<body>

@foreach ($pages as $pageUnits)
    <div class="page">
        <h1>EMLAK VERGİSİ BİLDİRİMİ (BİNA)</h1>

        <table class="top">
            <tr>
                <td><b>{{ $project->municipality }}</b> BELEDİYE BAŞKANLIĞINA</td>
                <td style="text-align:right;">Yılı: <b>{{ $project->declaration_year }}</b></td>
            </tr>
            <tr>
                <td>İl / İlçe: {{ $project->city }} / {{ $project->district }}</td>
                <td style="text-align:right;">Veriliş Nedeni: <b>{{ $verilis }}</b></td>
            </tr>
        </table>

        <table class="kv">
            <tr>
                <td class="lbl">Mükellefin Soyadı (Unvanı) / Adı</td>
                <td>{{ trim($project->taxpayer_surname.' '.$project->taxpayer_first_name) }}</td>
            </tr>
            <tr>
                <td class="lbl">T.C. / Vergi Kimlik No</td>
                <td>{{ $project->tax_id }}</td>
            </tr>
            <tr>
                <td class="lbl">Telefon</td>
                <td>{{ trim($project->phone_area_code.' '.$project->phone) }}</td>
            </tr>
        </table>

        <table class="bina">
            <tr>
                <th class="field">BİNAYA AİT BİLGİLER</th>
                @foreach ($pageUnits as $i => $u)
                    <th class="val">{{ ['I','II','III'][$i] }}. BİNA</th>
                @endforeach
            </tr>
            @php
                $rows = [
                    ['Bulunduğu Mahalle', fn($u) => $u->effectiveNeighborhood()],
                    ['Cadde / Sokak', fn($u) => $u->effectiveStreet()],
                    ['Kapı ve Daire No', fn($u) => trim(($u->block->building_door_no ?? '').' / '.$u->unit_no)],
                    ['Ada / Parsel', fn($u) => $project->cadastral_parcel],
                    ['Bina Arsasının Alanı (m²)', fn($u) => $fmtNum($u->block->land_area)],
                    ['Arsa Payı (Oran / m²)', fn($u) => trim(($u->landShareRatioText() ?? '').'  '.($u->landShareArea() !== null ? $fmtNum($u->landShareArea()).' m²' : ''))],
                    ['İnşaatın Türü', fn($u) => $u->block->construction_type],
                    ['İnşaatın Sınıfı', fn($u) => $u->effectiveConstructionClass()],
                    ['Kullanış Şekli', fn($u) => $u->effectiveUsageType()],
                    ['İnşaatın Bitim Tarihi', fn($u) => $fmtDate($u->block->construction_completion_date)],
                    ['İktisap Tarihi', fn($u) => $fmtDate($u->block->acquisition_date)],
                    ['Kısıtlılık Hali', fn($u) => $u->block->restriction_status],
                    ['Muafiyet', fn($u) => $u->block->exemption_status],
                    ['İndirimli Vergi', fn($u) => $u->block->reduced_tax],
                    ['Hisse Oranı', fn($u) => $u->effectiveShareRatio()],
                    ['Dıştan Dışa Yüzölçümü (m²)', fn($u) => $fmtNum($u->area)],
                    ['Kaloriferli', fn($u) => $u->block->has_heating ? 'VAR' : 'YOK'],
                    ['Asansörlü', fn($u) => $u->block->has_elevator ? 'VAR' : 'YOK'],
                ];
            @endphp
            @foreach ($rows as [$label, $getter])
                <tr>
                    <td class="field">{{ $label }}</td>
                    @foreach ($pageUnits as $u)
                        <td class="val">{{ $getter($u) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>

        <table class="sign">
            <tr>
                <td>Bildirimi Veren: <b>{{ trim($project->taxpayer_surname.' '.$project->taxpayer_first_name) }}</b>
                    ({{ $project->filer_role === 'proxy' ? 'Kanuni Temsilci / Vekil' : 'Mükellef' }})</td>
                <td style="text-align:right;">Tarih: {{ $fmtDate($project->declaration_date) }}<br><br>İmza:</td>
            </tr>
        </table>

        <div class="note">NOT:  1- Elbirliği (iştirak halinde) mülkiyette müşterek imzalı tek bildirim verildiğinde mükelleflerin tamamını gösterir bir liste bildirime eklenecek ve mükelleflerin tamamı bildirimi imza edeceklerdir.
2- Paylı (müşterek) mülkiyette her hissedarın kendi hissesini ayrı bildirim ile bildirmesi gerekir.
3- Belediye ve mücavir alan sınırları dışında bulunan binalar için de bildirim verilmesi gerekir.
4- Aynı çatı altındaki birden çok bağımsız birim ve dairelerin her biri ayrı ayrı bildirilecektir.
5- Bir mükellefe ait bina birimlerinin bildirimine bir bildirimin yetmemesi halinde yeteri kadar bildirim doldurularak birbirine iliştirilir.</div>
    </div>
@endforeach

{{-- KROKİ: ev görünümü --}}
<div class="page kroki">
    <h2>{{ trim($block->name) && $block->name !== '-' ? $block->name.' — ' : '' }}BİNA KROKİSİ</h2>
    <table class="kroki-grid">
        @foreach ($byFloor as $floor => $positions)
            <tr>
                <td class="kat">{{ $floor }}.KAT</td>
                @for ($p = 1; $p <= $maxPos; $p++)
                    @php $u = $positions[$p] ?? null; @endphp
                    @if ($u)
                        <td class="dbox">
                            <div class="no">{{ $u->unit_no }} NOLU DAİRE</div>
                            <div class="m2">{{ $u->area !== null ? $fmtNum($u->area).' m²' : '' }}</div>
                        </td>
                    @else
                        <td class="empty"></td>
                    @endif
                @endfor
            </tr>
        @endforeach
    </table>

    <table class="ozet">
        <tr>
            <td class="h">YAPI SAHİBİ</td>
            <td class="h">KULLANIM</td>
            <td class="h">İL</td>
            <td class="h">İLÇE</td>
            <td class="h">MAHALLE</td>
            <td class="h">ADA/PARSEL</td>
            <td class="h">YAPI ALANI</td>
        </tr>
        <tr>
            <td>{{ trim($project->taxpayer_surname.' '.$project->taxpayer_first_name) }}</td>
            <td>{{ $block->usage_type }}</td>
            <td>{{ $project->city }}</td>
            <td>{{ $project->district }}</td>
            <td>{{ $project->neighborhood }}</td>
            <td>{{ $project->cadastral_parcel }}</td>
            <td>{{ $fmtNum($units->sum(fn ($u) => (float) $u->area)) }} m²</td>
        </tr>
    </table>
</div>

</body>
</html>
