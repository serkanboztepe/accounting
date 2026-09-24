<?php

namespace App\Services\PropertyTax;

use App\Models\PropertyTaxBlock;
use App\Models\PropertyTaxTaxpayer;
use App\Models\PropertyTaxUnitTaxpayer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Borders;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bir bloğun Emlak Vergisi Bildirimi'ni (bina) üretir.
 *
 * - Beyanname sayfaları: blok daireleri 3'erli dağıtılır (8 daire → 3 sayfa).
 *   Her sayfaya mükellef + blok ortak bilgileri basılır. Şablondaki formüller
 *   literal değerlerle ezilir; böylece Sayfa1'e bağlı artık formül kalmaz.
 * - Kroki sayfası (Sayfa1): kat_no/kattaki_sira'dan bina şeması otomatik çizilir.
 *
 * Hücre haritası şablondan (beyanname.xls) çıkarıldı.
 */
class DeclarationExporter
{
    /** Sayfa başına daire sayısı (3 bina sütunu). */
    private const UNITS_PER_SHEET = 3;

    /** Bina sütunlarının ana çıpası (I/II/III. Bina). */
    private const MAIN_COLS = ['BT', 'CN', 'DK'];

    /** İkincil sütun (kapı/daire ayrımı ve arsa payı m²). */
    private const SUB_COLS = ['CD', 'CX', 'EE'];

    private const TEMPLATE_SHEETS = ['BEYANNAME 1', 'BEYANNAME 2', 'BEYANNAME 3'];

    public function templatePath(): string
    {
        return resource_path('property-tax/template.xls');
    }

    /**
     * Bloğu doldurup geçici bir .xls dosyasına yazar, yolunu döndürür.
     */
    /**
     * Bir mükellefin beyannamesini üretir: o mükellefe atanmış daire-hisseleri
     * (bloklar arası olabilir) 3'erli sayfalara dağıtılır (5 → 3+2, 2 → 2, 1 → 1).
     * Değerler hisseye göre ayarlanır. Ayrıca ilgili blok(lar) için kroki.
     */
    public function exportForTaxpayer(PropertyTaxTaxpayer $taxpayer): string
    {
        $taxpayer->loadMissing(['project', 'allocations.unit.block.project']);
        $project = $taxpayer->project;

        $allocations = $taxpayer->allocations
            ->filter(fn ($a) => $a->unit && $a->unit->block)
            ->sortBy([
                ['unit.block.id', 'asc'],
                ['unit.sort_order', 'asc'],
                ['unit.id', 'asc'],
            ])->values();

        $reader = IOFactory::createReader('Xls');
        $ss = $reader->load($this->templatePath());

        $sheetCount = max(1, (int) ceil($allocations->count() / self::UNITS_PER_SHEET));
        $this->ensureBeyannameSheets($ss, $sheetCount);

        for ($i = 0; $i < $sheetCount; $i++) {
            $sheet = $ss->getSheetByName('BEYANNAME '.($i + 1));
            $this->normalizePageSetup($sheet);
            $this->fillHeader($sheet, $taxpayer, $project);

            for ($slot = 0; $slot < self::UNITS_PER_SHEET; $slot++) {
                $alloc = $allocations->get($i * self::UNITS_PER_SHEET + $slot);
                if ($alloc) {
                    $this->fillAllocation($sheet, $slot, $alloc);
                } else {
                    $this->clearUnit($sheet, $slot);
                }
            }
        }

        // Kroki (tek/sabit): mükellefin ilk dairesinin bloğu, tam bina.
        $firstBlock = $allocations->first()?->unit->block;
        if ($firstBlock) {
            $firstBlock->loadMissing('units');
            $this->buildKroki($ss, $firstBlock, $firstBlock->units, $taxpayer->fullName());
        } elseif ($ss->getSheetByName('Sayfa1')) {
            $ss->removeSheetByIndex($ss->getIndex($ss->getSheetByName('Sayfa1')));
        }

        $ss->setActiveSheetIndex(0);

        // .xlsx: köşegen çatı ve biçim .xlsx'te doğru render olur (.xls yazıcı köşegeni göstermiyor).
        $path = tempnam(sys_get_temp_dir(), 'property_tax_').'.xlsx';
        IOFactory::createWriter($ss, 'Xlsx')->save($path);

        return $path;
    }

    public function downloadNameForTaxpayer(PropertyTaxTaxpayer $taxpayer): string
    {
        $slug = fn (string $s) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');

        return 'Beyanname-'.$slug($taxpayer->project?->name ?? 'proje').'-'.$slug($taxpayer->fullName()).'.xlsx';
    }

    // ── Sayfa yönetimi ─────────────────────────────────────────────────────

    private function ensureBeyannameSheets(Spreadsheet $ss, int $needed): void
    {
        // Fazlaysa şablonun kullanılmayan beyanname sayfalarını sil.
        for ($n = count(self::TEMPLATE_SHEETS); $n > $needed; $n--) {
            $name = 'BEYANNAME '.$n;
            if ($ss->sheetNameExists($name)) {
                $ss->removeSheetByIndex($ss->getIndex($ss->getSheetByName($name)));
            }
        }

        // Eksikse ilk beyanname sayfasından klonla.
        $base = $ss->getSheetByName('BEYANNAME 1');
        for ($n = count(self::TEMPLATE_SHEETS) + 1; $n <= $needed; $n++) {
            $clone = clone $base;
            $clone->setTitle('BEYANNAME '.$n);
            $ss->addSheet($clone, $n - 1);
        }
    }

    /**
     * Tüm beyanname sayfalarını 1. sayfayla aynı sayfa ayarına getir.
     * Şablonda BEYANNAME 3 eksik ayarlı (gridline görünür, baskı alanı yok,
     * ölçek %100) — "arka planda ızgara" sorununu bu düzeltir.
     */
    private function normalizePageSetup(Worksheet $sheet): void
    {
        $sheet->setShowGridlines(false);
        $ps = $sheet->getPageSetup();
        $ps->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $ps->setPaperSize(PageSetup::PAPERSIZE_A4);
        // Tam 1 A4'e sığdır — en alttaki NOT bloğu (C66:EN70) dahil her şey sayfaya girsin.
        $ps->setFitToWidth(1);
        $ps->setFitToHeight(1);
        $ps->setFitToPage(true);
        // Baskı alanı içeriğin gerçek sağ kenarına (EN = III. Bina + NOT sağ kenarı) ve NOT'un son satırına.
        $ps->setPrintArea('A1:EN70');
        $ps->setHorizontalCentered(true); // tüm beyanname sayfaları aynı hizada ortalı
    }

    // ── Başlık (mükellef + ortak) ──────────────────────────────────────────

    private function fillHeader(Worksheet $sheet, PropertyTaxTaxpayer $taxpayer, $project): void
    {
        // Konum/beyan bilgisi projeden
        $sheet->setCellValue('B6', $project->city);
        $sheet->setCellValue('BB6', 'YILI………'.$project->declaration_year.'…………………………………');
        $sheet->setCellValue('B8', $project->municipality);
        $sheet->setCellValue('DI8', $project->filing_reason === 'first_acquisition' ? 'X' : '');
        $sheet->setCellValue('DS8', $project->filing_reason === 'change' ? 'X' : '');
        $this->setDate($sheet, 'CM61', $project->declaration_date);

        // Mükellef bilgisi (bu beyanname o mükellef için)
        $this->setText($sheet, 'AI11', $taxpayer->tax_id);
        $sheet->setCellValue('CR11', $taxpayer->phone_area_code);
        $sheet->setCellValue('DG11', $taxpayer->phone);
        $this->setText($sheet, 'AI13', $taxpayer->property_registry_no);
        $sheet->setCellValue('AI15', $taxpayer->surname);
        $sheet->setCellValue('AI17', $taxpayer->first_name);

        // Alt imza bloğu
        $sheet->setCellValue('T56', $taxpayer->fullName());
        $this->setText($sheet, 'CN56', $taxpayer->tax_id);
        $sheet->setCellValue('DA53', $taxpayer->filer_role === 'taxpayer' ? 'X' : '');
    }

    // ── Daire sütunu (I/II/III. Bina) ──────────────────────────────────────

    private function fillAllocation(Worksheet $sheet, int $slot, PropertyTaxUnitTaxpayer $alloc): void
    {
        $c = self::MAIN_COLS[$slot];
        $s = self::SUB_COLS[$slot];
        $unit = $alloc->unit;
        $block = $unit->block;
        $fraction = $alloc->shareFraction();

        $sheet->setCellValue($c.'29', $unit->effectiveNeighborhood());
        $sheet->setCellValue($c.'30', $unit->effectiveStreet());
        $sheet->setCellValue($c.'31', $block->building_door_no);   // Kapı/Bina no
        $this->setText($sheet, $s.'31', (string) $unit->unit_no);  // Daire no
        $this->setText($sheet, $c.'33', $block->project?->cadastral_parcel);
        $sheet->setCellValue($c.'35', $block->land_area);
        $sheet->setCellValue($c.'36', $unit->landShareRatioText()); // binaya ait arsa payı (ör. 5/120)
        $sheet->setCellValue($s.'36', $unit->landShareArea());      // m²
        $sheet->setCellValue($c.'37', $block->construction_type);
        $sheet->setCellValue($c.'38', $unit->effectiveConstructionClass());
        $sheet->setCellValue($c.'39', $unit->effectiveUsageType());
        $this->setDate($sheet, $c.'40', $block->construction_completion_date);
        $this->setDate($sheet, $c.'41', $block->acquisition_date);
        $sheet->setCellValue($c.'42', $block->restriction_status);
        $sheet->setCellValue($c.'43', $block->exemption_status);
        $sheet->setCellValue($c.'44', $block->reduced_tax);
        $sheet->setCellValue($c.'45', $alloc->shareText());        // Hisse oranı (TAM veya pay/payda)
        // Dıştan dışa yüzölçümü — hisseli ise hisseye isabet eden
        $sheet->setCellValue($c.'46', $unit->area !== null ? round((float) $unit->area * $fraction, 2) : null);
        $sheet->setCellValue($c.'47', $block->has_heating ? 'VAR' : 'YOK');
        $sheet->setCellValue($c.'48', $block->has_elevator ? 'VAR' : 'YOK');
    }

    private function clearUnit(Worksheet $sheet, int $slot): void
    {
        $c = self::MAIN_COLS[$slot];
        $s = self::SUB_COLS[$slot];
        foreach ([29, 30, 31, 33, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48] as $row) {
            $sheet->setCellValue($c.$row, '');
        }
        $sheet->setCellValue($s.'31', '');
        $sheet->setCellValue($s.'36', '');
    }

    // ── Kroki (bina şeması) ────────────────────────────────────────────────

    /**
     * Bina krokisi — ızgara düzeni (referans dosyayla birebir): kat başına N daire
     * YAN YANA (her kutu 3 sütun: B:D, E:G…), katlar üst üste (üst kat en üstte).
     * Her kutu: üst yarı (3 satır) "X NOLU DAİRE", alt yarı (3 satır) yüzölçümü.
     * Üstte /\ çatı (ev görünümü). Altta özet tablo. Solda kat etiketi.
     */
    private const KROKI_BOX_COLS = 3;   // kutu genişliği (referans: B:D)
    private const KROKI_FLOOR_ROWS = 6; // kat yüksekliği (isim 3 + m² 3)

    private function buildKroki(Spreadsheet $ss, PropertyTaxBlock $block, $units, string $ownerName = ''): void
    {
        // Sayfa1'i kroki için yeniden kullan: içeriği temizle, birleştirmeleri boz.
        $sheet = $ss->getSheetByName('Sayfa1');
        if (! $sheet) {
            $sheet = $ss->createSheet();
        }
        foreach (array_keys($sheet->getMergeCells()) as $range) {
            $sheet->unmergeCells($range);
        }
        $highest = max($sheet->getHighestRow(), 40);
        for ($r = 1; $r <= $highest; $r++) {
            foreach (range('A', 'Z') as $col) {
                $sheet->setCellValue($col.$r, null);
            }
        }
        // Şablon Sayfa1'den kalan kenarlık/köşegenleri de temizle — yoksa çatının
        // altında orijinal kroki kutularının hayalet dikdörtgenleri kalıyor.
        $clear = 'A1:AC'.$highest;
        $sheet->getStyle($clear)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
        $sheet->getStyle($clear)->getBorders()->getDiagonal()->setBorderStyle(Border::BORDER_NONE);
        $sheet->setTitle('KROKİ');

        // Katlara göre grupla
        $byFloor = [];
        $maxPos = 1;
        foreach ($units as $u) {
            $floor = $u->floor_no ?? 1;
            $pos = $u->floor_position ?? 1;
            $byFloor[$floor][$pos] = $u;
            $maxPos = max($maxPos, $pos);
        }
        if (empty($byFloor)) {
            $byFloor[1] = [];
        }
        krsort($byFloor); // üst kat en üstte

        $boxW = self::KROKI_BOX_COLS;
        $firstCol = 2; // B
        $lastCol = $firstCol + $maxPos * $boxW - 1;
        $lastLetter = $this->colLetter($lastCol);

        // Başlık ("-" veya boş blok adını gösterme)
        $name = trim((string) $block->name);
        $prefix = ($name !== '' && $name !== '-') ? $name.' — ' : '';
        $sheet->mergeCells('B1:'.$lastLetter.'1');
        $sheet->setCellValue('B1', $prefix.'BİNA KROKİSİ');
        $sheet->getStyle('B1:'.$lastLetter.'1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Çatı: /\ — köşegeni BİRLEŞTİRMESİZ, tek tek hücrelere merdiven şeklinde
        // çiziyoruz (birleşik hücre köşegenini birçok görüntüleyici çizmiyor).
        // .xlsx'te tek hücre köşegeni her yerde render olur.
        $width = $maxPos * $boxW;
        $half = max(2, intdiv($width, 2));
        $roofTop = 3;
        $roofBottom = $roofTop + $half - 1;
        for ($kk = 0; $kk < $half; $kk++) {
            // sol yamaç (/): alt-soldan yukarı-sağa
            $sheet->getStyle($this->colLetter($firstCol + $kk).($roofBottom - $kk))
                ->getBorders()->setDiagonalDirection(Borders::DIAGONAL_UP)
                ->getDiagonal()->setBorderStyle(Border::BORDER_MEDIUM);
            // sağ yamaç (\): yukarı-soldan alt-sağa
            $sheet->getStyle($this->colLetter($lastCol - $kk).($roofBottom - $kk))
                ->getBorders()->setDiagonalDirection(Borders::DIAGONAL_DOWN)
                ->getDiagonal()->setBorderStyle(Border::BORDER_MEDIUM);
        }
        for ($r = $roofTop; $r <= $roofBottom; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(26);
        }

        // Izgara: kat başına maxPos daire yan yana, katlar üst üste (çatının altından)
        $startRow = $roofBottom + 1;
        $floorIndex = 0;
        foreach ($byFloor as $floor => $positions) {
            $top = $startRow + $floorIndex * self::KROKI_FLOOR_ROWS;

            $sheet->setCellValue('A'.($top + 1), $floor.'.KAT');
            $sheet->getStyle('A'.($top + 1))->getFont()->setBold(true);

            for ($p = 1; $p <= $maxPos; $p++) {
                $u = $positions[$p] ?? null;
                if (! $u) {
                    continue; // eksik konum: boş bırak
                }
                $cs = $firstCol + ($p - 1) * $boxW;
                $c0 = $this->colLetter($cs);
                $c1 = $this->colLetter($cs + $boxW - 1);

                $labelRange = $c0.$top.':'.$c1.($top + 2);
                $areaRange = $c0.($top + 3).':'.$c1.($top + 5);
                $sheet->mergeCells($labelRange);
                $sheet->mergeCells($areaRange);

                $sheet->setCellValue($c0.$top, $u->unit_no.' NOLU DAİRE');
                $sheet->setCellValue($c0.($top + 3), $this->areaText($u->area));

                foreach ([$labelRange, $areaRange] as $rg) {
                    $sheet->getStyle($rg)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
                    $sheet->getStyle($rg)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
                }
                $sheet->getStyle($labelRange)->getFont()->setBold(true);
            }
            $floorIndex++;
        }

        $summaryRow = $startRow + count($byFloor) * self::KROKI_FLOOR_ROWS + 1;
        $this->buildKrokiSummary($sheet, $block, $units, $summaryRow, $ownerName);

        // Şablon Sayfa1'den kalan alttaki boş satırları sil
        $last = $sheet->getHighestRow();
        if ($last > $summaryRow + 1) {
            $sheet->removeRow($summaryRow + 2, $last - ($summaryRow + 1));
        }

        // Kroki tek sayfaya sığsın (LibreOffice PDF'te bölünmesin)
        $sheet->setShowGridlines(false);
        $kps = $sheet->getPageSetup();
        $kps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $kps->setFitToWidth(1);
        $kps->setFitToHeight(1);
        $kps->setFitToPage(true);

        $ss->setActiveSheetIndex($ss->getIndex($ss->getSheetByName('BEYANNAME 1')));
    }

    private function buildKrokiSummary(Worksheet $sheet, PropertyTaxBlock $block, $units, int $row, string $ownerName = ''): void
    {
        $project = $block->project;
        $totalArea = 0.0;
        foreach ($units as $u) {
            $totalArea += (float) $u->area;
        }

        $headers = ['YAPI SAHİBİ', 'KULLANIM AMACI', 'İLİ', 'İLÇESİ', 'MAHALLE', 'ADA/PARSEL', 'YAPI ALANI M2'];
        $values = [
            $ownerName,
            $block->usage_type,
            $project->city,
            $project->district,
            $project->neighborhood,
            $project->cadastral_parcel,
            $totalArea ? number_format($totalArea, 2, ',', '.') : '',
        ];

        foreach ($headers as $i => $h) {
            $letter = $this->colLetter(2 + $i * 2);   // her hücre 2 sütun geniş (B:C, D:E…)
            $next = $this->colLetter(2 + $i * 2 + 1);
            $range = $letter.$row.':'.$next.$row;
            $vrange = $letter.($row + 1).':'.$next.($row + 1);
            $sheet->mergeCells($range);
            $sheet->mergeCells($vrange);
            $sheet->setCellValue($letter.$row, $h);
            $sheet->setCellValue($letter.($row + 1), $values[$i]);
            $sheet->getStyle($range)->getFont()->setBold(true);
            $sheet->getStyle($range)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($vrange)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            foreach ([$range, $vrange] as $rg) {
                $sheet->getStyle($rg)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            }
        }
    }

    /** Yüzölçümü metni, ör. 131.00 → "131 m²", 120.50 → "120,5 m²". */
    private function areaText($area): string
    {
        if ($area === null || $area === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $area, 2, ',', '.'), '0'), ',').' m²';
    }

    // ── Yardımcılar ────────────────────────────────────────────────────────

    private function colLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    /** Metni string tipiyle yaz (VKN/TC baştaki sıfırlar ve bilimsel gösterim bozulmasın). */
    private function setText(Worksheet $sheet, string $coord, ?string $value): void
    {
        $sheet->getCell($coord)->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);
    }

    private function setDate(Worksheet $sheet, string $coord, $date): void
    {
        if (! $date) {
            $sheet->setCellValue($coord, '');

            return;
        }
        $sheet->setCellValue($coord, ExcelDate::PHPToExcel($date));
        // Şablonda bazı tarih hücreleri (ör. İktisap Tarihi) sayı biçimli (0.00) —
        // serial sayı olarak görünmesin diye tarih hücrelerine gg.aa.yyyy uygula.
        $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
    }
}
