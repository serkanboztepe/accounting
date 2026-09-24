@php
    /** @var \App\Models\PropertyTaxTaxpayer $taxpayer */
    $project = $taxpayer->project;
    $allocations = $taxpayer->allocations
        ->filter(fn ($a) => $a->unit && $a->unit->block)
        ->sortBy([['unit.block.id', 'asc'], ['unit.sort_order', 'asc'], ['unit.id', 'asc']])
        ->values();
    $pages = $allocations->chunk(3)->map(fn ($c) => $c->values());

    $fmtNum = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') : '';
    $verilis = $project->filing_reason === 'change' ? 'Değişiklik' : 'İlk İktisap';

    $krokiBlock = $allocations->first()?->unit->block;
    $byFloor = [];
    $maxPos = 1;
    foreach (($krokiBlock?->units ?? collect()) as $u) {
        $byFloor[$u->floor_no ?? 0][$u->floor_position ?? 1] = $u;
        $maxPos = max($maxPos, $u->floor_position ?? 1);
    }
    krsort($byFloor);
@endphp

@forelse ($pages as $pageAllocs)
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
                <td class="lbl">Mükellefin Adı Soyadı / Ünvanı</td>
                <td>{{ $taxpayer->fullName() }}</td>
            </tr>
            <tr>
                <td class="lbl">T.C. / Vergi Kimlik No</td>
                <td>{{ $taxpayer->tax_id }}</td>
            </tr>
            <tr>
                <td class="lbl">Telefon</td>
                <td>{{ trim($taxpayer->phone_area_code.' '.$taxpayer->phone) }}</td>
            </tr>
        </table>

        <table class="bina">
            <tr>
                <th class="field">BİNAYA AİT BİLGİLER</th>
                @foreach ($pageAllocs as $i => $a)
                    <th class="val">{{ ['I','II','III'][$i] }}. BİNA</th>
                @endforeach
            </tr>
            @php
                $rows = [
                    ['Bulunduğu Mahalle', fn($a) => $a->unit->effectiveNeighborhood()],
                    ['Cadde / Sokak', fn($a) => $a->unit->effectiveStreet()],
                    ['Kapı ve Daire No', fn($a) => trim(($a->unit->block->building_door_no ?? '').' / '.$a->unit->unit_no)],
                    ['Ada / Parsel', fn($a) => $project->cadastral_parcel],
                    ['Bina Arsasının Alanı (m²)', fn($a) => $fmtNum($project->land_area)],
                    ['Arsa Payı (Oran / m²)', fn($a) => trim(($a->unit->landShareRatioText() ?? '').'  '.($a->unit->landShareArea() !== null ? $fmtNum($a->unit->landShareArea()).' m²' : ''))],
                    ['İnşaatın Türü', fn($a) => $a->unit->block->construction_type],
                    ['İnşaatın Sınıfı', fn($a) => $a->unit->effectiveConstructionClass()],
                    ['Kullanış Şekli', fn($a) => $a->unit->effectiveUsageType()],
                    ['İnşaatın Bitim Tarihi', fn($a) => $fmtDate($a->unit->block->construction_completion_date)],
                    ['İktisap Tarihi', fn($a) => $fmtDate($a->unit->block->acquisition_date)],
                    ['Kısıtlılık Hali', fn($a) => $a->unit->block->restriction_status],
                    ['Muafiyet', fn($a) => $a->unit->block->exemption_status],
                    ['İndirimli Vergi', fn($a) => $a->unit->block->reduced_tax],
                    ['Hisse Oranı', fn($a) => $a->shareText()],
                    ['Dıştan Dışa Yüzölçümü — Hisseye İsabet Eden (m²)', fn($a) => $fmtNum($a->unit->area !== null ? round((float) $a->unit->area * $a->shareFraction(), 2) : null)],
                    ['Kaloriferli', fn($a) => $a->unit->block->has_heating ? 'VAR' : 'YOK'],
                    ['Asansörlü', fn($a) => $a->unit->block->has_elevator ? 'VAR' : 'YOK'],
                ];
            @endphp
            @foreach ($rows as [$label, $getter])
                <tr>
                    <td class="field">{{ $label }}</td>
                    @foreach ($pageAllocs as $a)
                        <td class="val">{{ $getter($a) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>

        <table class="sign">
            <tr>
                <td>Bildirimi Veren: <b>{{ $taxpayer->fullName() }}</b>
                    ({{ $taxpayer->filer_role === 'proxy' ? 'Kanuni Temsilci / Vekil' : 'Mükellef' }})</td>
                <td style="text-align:right;">Tarih: {{ $fmtDate($project->declaration_date) }}<br><br>İmza:</td>
            </tr>
        </table>

        <div class="note">NOT:  1- Elbirliği (iştirak halinde) mülkiyette müşterek imzalı tek bildirim verildiğinde mükelleflerin tamamını gösterir bir liste bildirime eklenecek ve mükelleflerin tamamı bildirimi imza edeceklerdir.
2- Paylı (müşterek) mülkiyette her hissedarın kendi hissesini ayrı bildirim ile bildirmesi gerekir.
3- Belediye ve mücavir alan sınırları dışında bulunan binalar için de bildirim verilmesi gerekir.
4- Aynı çatı altındaki birden çok bağımsız birim ve dairelerin her biri ayrı ayrı bildirilecektir.
5- Bir mükellefe ait bina birimlerinin bildirimine bir bildirimin yetmemesi halinde yeteri kadar bildirim doldurularak birbirine iliştirilir.</div>
    </div>
@empty
    <div class="page"><h1>EMLAK VERGİSİ BİLDİRİMİ (BİNA)</h1><p style="text-align:center;">{{ $taxpayer->fullName() }} — atanmış daire yok.</p></div>
@endforelse

@if ($krokiBlock)
<div class="page kroki">
    <h2>{{ trim($krokiBlock->name) && $krokiBlock->name !== '-' ? $krokiBlock->name.' — ' : '' }}BİNA KROKİSİ ({{ $taxpayer->fullName() }})</h2>
    <table class="kroki-grid">
        @php $roofHalf = max(60, $maxPos * 49); $roofH = max(45, $maxPos * 30); @endphp
        <tr>
            <td class="kat"></td>
            <td colspan="{{ $maxPos }}" class="roof-cell">
                <div class="roof" style="border-width: 0 {{ $roofHalf }}px {{ $roofH }}px {{ $roofHalf }}px;"></div>
            </td>
        </tr>
        @foreach ($byFloor as $floor => $positions)
            <tr>
                <td class="kat">{{ (int) $floor === 0 ? 'ZEMİN' : $floor.'.KAT' }}</td>
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
            <td>{{ $taxpayer->fullName() }}</td>
            <td>{{ $krokiBlock->usage_type }}</td>
            <td>{{ $project->city }}</td>
            <td>{{ $project->district }}</td>
            <td>{{ $project->neighborhood }}</td>
            <td>{{ $project->cadastral_parcel }}</td>
            <td>{{ $fmtNum($krokiBlock->units->sum(fn ($u) => (float) $u->area)) }} m²</td>
        </tr>
    </table>
</div>
@endif
