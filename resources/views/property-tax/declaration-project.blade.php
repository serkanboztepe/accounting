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
</body>
</html>
