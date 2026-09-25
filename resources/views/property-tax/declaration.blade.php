<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
@include('property-tax._styles')
</head>
<body>
@include('property-tax._pages', ['taxpayer' => $taxpayer])

{{-- Kroki en altta, blok başına bir kez (mükellefin atandığı bloklar). YAPI SAHİBİ = proje.building_owner --}}
@php
    $project = $taxpayer->project;
    $krokiBlocks = $taxpayer->allocations
        ->map(fn ($a) => $a->unit?->block)
        ->filter()
        ->unique('id')
        ->values();
@endphp
@foreach ($krokiBlocks as $krokiBlock)
    @include('property-tax._kroki', ['krokiBlock' => $krokiBlock, 'project' => $project, 'owner' => $project->building_owner])
@endforeach
</body>
</html>
