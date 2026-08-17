<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/land-share-studies/{study}/yazdir', function (\App\Models\LandShareStudy $study) {
    $data = $study->toStudyData();
    $method = (new \App\Services\LandShare\StudyValidator())->defaultMethod($data);

    abort_if($method === null, 422, 'Cetvel için veriler eksik: mevcut hisseler 1/1 ve her BB dağılımı 1/1 olmalı.');

    $result = (new \App\Services\LandShare\ShareCalculator())->calculate($data, $method);

    return view('land-share.print', [
        'study'  => $study,
        'result' => $result,
        'common' => $result->commonDenominator(),
        'method' => $method,
    ]);
})->middleware('auth')->name('land-share.print');

Route::get('/invoice-file/{filename}', function (string $filename) {
    $path = storage_path('app/public/invoices/' . $filename);

    abort_if(! file_exists($path), 404);

    return response()->file($path, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->middleware('auth')->name('invoice.file');
