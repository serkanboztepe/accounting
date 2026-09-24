<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
@include('property-tax._styles')
</head>
<body>
@include('property-tax._pages', ['taxpayer' => $taxpayer])
</body>
</html>
