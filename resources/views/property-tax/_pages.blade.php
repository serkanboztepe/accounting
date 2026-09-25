@php
    /** @var \App\Models\PropertyTaxTaxpayer $taxpayer */
    $project = $taxpayer->project;
    $allocations = $taxpayer->allocations
        ->filter(fn ($a) => $a->unit && $a->unit->block)
        ->values();
    // Her blok AYRI beyanname (bloklar aynı sayfada karışmaz); blok içinde daire no'ya
    // göre (id değil — ekle/çıkar olunca kaymaz); her blok kendi içinde 3'erli sayfalara bölünür.
    $pages = $allocations
        ->groupBy(fn ($a) => $a->unit->property_tax_block_id)
        ->sortBy(fn ($group) => (string) $group->first()->unit->block->name, SORT_NATURAL | SORT_FLAG_CASE)
        ->flatMap(fn ($group) => $group
            ->sortBy(fn ($a) => (string) $a->unit->unit_no, SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->chunk(3)
            ->map(fn ($c) => $c->values()))
        ->values();

    $fmtNum = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $fmtDate = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d.m.Y') : '';
    $verilis = $project->filing_reason === 'change' ? 'Değişiklik' : 'İlk İktisap';
    // Kroki artık burada değil — her beyannamenin sonunda değil, belgenin EN ALTINDA
    // (blok başına bir kez) basılıyor. Bkz. declaration-project / declaration blade + _kroki.
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
                    ['Arsa Payı (Oran / m²)', fn($a) => implode(' - ', array_filter([
                        $a->unit->landShareRatioText(),
                        $a->unit->landShareArea() !== null ? $fmtNum($a->unit->landShareArea()).' m²' : null,
                    ]))],
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
