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

Route::get('/teklifler/{quote}/yazdir', function (\App\Models\Quote $quote) {
    $quote->load(['party', 'project', 'items.unit']);

    return view('print.document', [
        'title'    => 'TEKLİF',
        'company'  => \App\Models\CompanySettings::current(),
        'bodyHtml' => \App\Support\DocumentRenderer::render($quote, \App\Support\DocumentRenderer::TYPE_QUOTE),
    ]);
})->middleware('auth')->name('quote.print');

Route::get('/sozlesmeler/{contract}/yazdir', function (string $contract) {
    // Satış sözleşmeleri global scope ile gizli — çıktı için scope'suz getir.
    $model = \App\Models\Contract::withoutGlobalScope('purchase')
        ->with(['party', 'project', 'items.unit'])
        ->findOrFail($contract);

    return view('print.document', [
        'title'    => 'SÖZLEŞME',
        'company'  => \App\Models\CompanySettings::current(),
        'bodyHtml' => \App\Support\DocumentRenderer::render($model, \App\Support\DocumentRenderer::TYPE_CONTRACT),
    ]);
})->middleware('auth')->name('contract.print');

Route::get('/cariler/{party}/ekstre', function (\App\Models\Party $party) {
    return view('print.party-statement', [
        'party'     => $party,
        'company'   => \App\Models\CompanySettings::current(),
        'statement' => \App\Support\PartyStatement::build($party),
    ]);
})->middleware('auth')->name('party.statement.print');

Route::get('/invoice-file/{filename}', function (string $filename) {
    $path = storage_path('app/public/invoices/' . $filename);

    abort_if(! file_exists($path), 404);

    return response()->file($path, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->middleware('auth')->name('invoice.file');
