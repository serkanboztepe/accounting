<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
@include('property-tax._styles')
</head>
<body>
@forelse ($taxpayers as $taxpayer)
    @include('property-tax._pages', ['taxpayer' => $taxpayer])
@empty
    <div class="page"><h1>EMLAK VERGİSİ BİLDİRİMİ (BİNA)</h1><p style="text-align:center;">Bu projede mükellef yok.</p></div>
@endforelse

{{-- Kroki(ler) belgenin EN ALTINDA, blok başına bir kez (mükellef başına DEĞİL). --}}
@php
    /** @var \App\Models\PropertyTaxProject $project (route'tan gelir) */
    $krokiBlocks = $taxpayers
        ->flatMap(fn ($t) => $t->allocations)
        ->map(fn ($a) => $a->unit?->block)
        ->filter()
        ->unique('id')
        ->values();
@endphp
@if ($project)
    @foreach ($krokiBlocks as $krokiBlock)
        @include('property-tax._kroki', ['krokiBlock' => $krokiBlock, 'project' => $project, 'owner' => $project->building_owner])
    @endforeach
@endif
</body>
</html>
