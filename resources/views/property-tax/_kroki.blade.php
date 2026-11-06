@php
    /** @var \App\Models\PropertyTaxBlock $krokiBlock */
    /** @var \App\Models\PropertyTaxProject $project */
    $fmtNum = fn ($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $byFloor = [];
    $maxPos = 1;
    foreach (($krokiBlock->units ?? collect()) as $u) {
        $byFloor[$u->floor_no ?? 0][$u->floor_position ?? 1] = $u;
        $maxPos = max($maxPos, $u->floor_position ?? 1);
    }
    krsort($byFloor);
@endphp
<div class="page kroki">
    <h2>{{ trim($krokiBlock->name) && $krokiBlock->name !== '-' ? $krokiBlock->name.' — ' : '' }}BİNA KROKİSİ</h2>
    <table class="kroki-grid">
        @php
            // A4 dikey (10mm kenar) ≈ 718px; kat etiketi + kenarlıklar → kutulara ≈ 590px. Sabit 95px'te 7+ daire
            // taşıyordu (Yıldız 30.10, katta 10). Kutu daralır, yazı küçülür; çatı kutularla birlikte.
            $boxW = min(95, intdiv(590, $maxPos));
            $small = $boxW < 85;
            $roofHalf = max(60, intdiv($maxPos * ($boxW + 4), 2));
            $roofH = min(160, max(45, $maxPos * 30));
        @endphp
        <tr>
            <td class="kat"></td>
            <td colspan="{{ $maxPos }}" class="roof-cell">
                <div class="roof" style="border-width: 0 {{ $roofHalf }}px {{ $roofH }}px {{ $roofHalf }}px;"></div>
            </td>
        </tr>
        @foreach ($byFloor as $floor => $positions)
            @php
                // Kattaki daireleri konum sırasına göre al, tüm genişliğe (maxPos) EŞİT dağıt:
                // 4 genişlikte 2 daire → her biri 2 kolon; 3 daire → 2+1+1. Sola yığılmaz.
                ksort($positions);
                $floorUnits = array_values($positions);
                $k = max(1, count($floorUnits));
                $base = intdiv($maxPos, $k);
                $rem = $maxPos % $k;
            @endphp
            <tr>
                <td class="kat">{{ (int) $floor === 0 ? 'ZEMİN' : $floor.'.KAT' }}</td>
                @foreach ($floorUnits as $idx => $u)
                    @php $span = $base + ($idx < $rem ? 1 : 0); @endphp
                    <td class="dbox" colspan="{{ $span }}" style="width: {{ $boxW * $span }}px;">
                        <div class="no" @if ($small) style="font-size: 7.5px;" @endif>{{ $u->unit_no }} NOLU {{ mb_strtoupper($u->effectiveUsageType() ?: 'DAİRE') }}</div>
                        <div class="m2" @if ($small) style="font-size: 7px;" @endif>{{ $u->area !== null ? $fmtNum($u->area).' m²' : '' }}</div>
                    </td>
                @endforeach
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
            <td>{{ $owner }}</td>
            <td>{{ $krokiBlock->usage_type }}</td>
            <td>{{ $project->city }}</td>
            <td>{{ $project->district }}</td>
            <td>{{ $project->neighborhood }}</td>
            <td>{{ $project->cadastral_parcel }}</td>
            <td>{{ $fmtNum($krokiBlock->units->sum(fn ($u) => (float) $u->area)) }} m²</td>
        </tr>
    </table>
</div>
