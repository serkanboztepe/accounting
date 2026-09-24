<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

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

Route::get('/cariler/{party}/ekstre', function (\App\Models\Party $party, \Illuminate\Http\Request $request) {
    $filters = array_filter([
        'date_from'  => $request->query('date_from'),
        'date_to'    => $request->query('date_to'),
        'project_id' => $request->query('project_id'),
    ], fn ($v) => filled($v));

    $projectName = ! empty($filters['project_id'])
        ? \App\Models\Project::find($filters['project_id'])?->name
        : null;

    return view('print.party-statement', [
        'party'       => $party,
        'company'     => \App\Models\CompanySettings::current(),
        'statement'   => \App\Support\PartyStatement::build($party, $filters),
        'filters'     => $filters,
        'projectName' => $projectName,
    ]);
})->middleware('auth')->name('party.statement.print');

Route::get('/stok-raporu/yazdir', function () {
    return view('print.stock-report', [
        'company' => \App\Models\CompanySettings::current(),
        'rows'    => \App\Support\StockReporting::stockRows(),
    ]);
})->middleware('auth')->name('stock-report.print');

Route::get('/satis-ozeti/yazdir', function (\Illuminate\Http\Request $request) {
    $group = $request->query('group') === 'project' ? 'project' : 'party';

    return view('print.sales-summary', [
        'company'    => \App\Models\CompanySettings::current(),
        'rows'       => \App\Support\StockReporting::salesSummary($group),
        'groupLabel' => $group === 'project' ? 'Şantiye / Proje' : 'Müşteri',
    ]);
})->middleware('auth')->name('sales-summary.print');

Route::get('/satislar/{sale}/yazdir', function (\App\Models\Sale $sale) {
    $sale->load(['party', 'project', 'lines.product', 'returns.product']);

    return view('print.sale', [
        'company' => \App\Models\CompanySettings::current(),
        'sale'    => $sale,
    ]);
})->middleware('auth')->name('sale.print');

// Emlak Beyanı — mükellef başına çıktı (Excel, dompdf PDF, LibreOffice Formatlı PDF)
Route::get('/emlak-beyani-mukellef/{taxpayer}/excel', function (\App\Models\PropertyTaxTaxpayer $taxpayer) {
    $exporter = new \App\Services\PropertyTax\DeclarationExporter();

    return response()->download($exporter->exportForTaxpayer($taxpayer), $exporter->downloadNameForTaxpayer($taxpayer))
        ->deleteFileAfterSend();
})->middleware('auth')->name('property-tax.taxpayer.excel');

Route::get('/emlak-beyani-mukellef/{taxpayer}/pdf', function (\App\Models\PropertyTaxTaxpayer $taxpayer) {
    $taxpayer->load(['project', 'allocations.unit.block.project']);
    $slug = fn (string $s) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
    $name = 'Beyanname-'.$slug($taxpayer->project?->name ?? 'proje').'-'.$slug($taxpayer->fullName()).'.pdf';

    return \Barryvdh\DomPDF\Facade\Pdf::loadView('property-tax.declaration', ['taxpayer' => $taxpayer])
        ->setPaper('a4', 'portrait')
        ->download($name);
})->middleware('auth')->name('property-tax.taxpayer.pdf');

Route::get('/emlak-beyani-mukellef/{taxpayer}/formatli-pdf', function (\App\Models\PropertyTaxTaxpayer $taxpayer) {
    $exporter = new \App\Services\PropertyTax\DeclarationExporter();
    $xlsx = $exporter->exportForTaxpayer($taxpayer);

    try {
        $pdf = (new \App\Services\PropertyTax\PdfConverter())->fromXlsx($xlsx);
    } finally {
        @unlink($xlsx);
    }

    return response()->download($pdf, str_replace('.xlsx', '.pdf', $exporter->downloadNameForTaxpayer($taxpayer)))
        ->deleteFileAfterSend();
})->middleware('auth')->name('property-tax.taxpayer.formatli-pdf');

Route::get('/invoice-file/{filename}', function (string $filename) {
    $path = storage_path('app/public/invoices/' . $filename);

    abort_if(! file_exists($path), 404);

    return response()->file($path, [
        'Content-Type'        => 'application/pdf',
        'Content-Disposition' => 'inline; filename="' . $filename . '"',
    ]);
})->middleware('auth')->name('invoice.file');
