<?php

namespace App\Services\PropertyTax;

use App\Models\PropertyTaxBlock;
use App\Models\PropertyTaxUnit;
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
    public function export(PropertyTaxBlock $block): string
    {
        $block->loadMissing(['project', 'units']);
        $project = $block->project;
        $units = $block->units->values();

        $reader = IOFactory::createReader('Xls');
        $ss = $reader->load($this->templatePath());

        $sheetCount = max(1, (int) ceil($units->count() / self::UNITS_PER_SHEET));
        $this->ensureBeyannameSheets($ss, $sheetCount);

        for ($i = 0; $i < $sheetCount; $i++) {
            $sheet = $ss->getSheetByName('BEYANNAME '.($i + 1));
            $this->normalizePageSetup($sheet);
            $this->fillHeader($sheet, $project);

            for ($slot = 0; $slot < self::UNITS_PER_SHEET; $slot++) {
                $unit = $units->get($i * self::UNITS_PER_SHEET + $slot);
                if ($unit) {
                    $this->fillUnit($sheet, $slot, $unit);
                } else {
                    $this->clearUnit($sheet, $slot);
                }
            }
        }

        $this->buildKroki($ss, $block, $units);

        $ss->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'property_tax_').'.xls';
        IOFactory::createWriter($ss, 'Xls')->save($path);

        return $path;
    }

    public function downloadName(PropertyTaxBlock $block): string
    {
        $project = $block->project?->name ?? 'proje';
        $slug = fn (string $s) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');

        return 'Beyanname-'.$slug($project).'-'.$slug($block->name).'.xls';
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
    }

    // ── Başlık (mükellef + ortak) ──────────────────────────────────────────

    private function fillHeader(Worksheet $sheet, $project): void
    {
        $sheet->setCellValue('B6', $project->city);
        $sheet->setCellValue('BB6', 'YILI………'.$project->declaration_year.'…………………………………');
        $sheet->setCellValue('B8', $project->municipality);

        // Veriliş nedeni kutuları (İlk İktisap / Değişiklik)
        $sheet->setCellValue('DI8', $project->filing_reason === 'first_acquisition' ? 'X' : '');
        $sheet->setCellValue('DS8', $project->filing_reason === 'change' ? 'X' : '');

        $this->setText($sheet, 'AI11', $project->tax_id);
        $sheet->setCellValue('CR11', $project->phone_area_code);
        $sheet->setCellValue('DG11', $project->phone);
        $this->setText($sheet, 'AI13', $project->property_registry_no);
        $sheet->setCellValue('AI15', $project->taxpayer_surname);
        $sheet->setCellValue('AI17', $project->taxpayer_first_name);

        // Alt imza bloğu
        $fullName = trim($project->taxpayer_surname.' '.$project->taxpayer_first_name);
        $sheet->setCellValue('T56', $fullName);
        $this->setText($sheet, 'CN56', $project->tax_id);
        $this->setDate($sheet, 'CM61', $project->declaration_date);
        $sheet->setCellValue('DA53', $project->filer_role === 'taxpayer' ? 'X' : '');
    }

    // ── Daire sütunu (I/II/III. Bina) ──────────────────────────────────────

    private function fillUnit(Worksheet $sheet, int $slot, PropertyTaxUnit $unit): void
    {
        $c = self::MAIN_COLS[$slot];
        $s = self::SUB_COLS[$slot];
        $block = $unit->block;

        $sheet->setCellValue($c.'29', $unit->effectiveNeighborhood());
        $sheet->setCellValue($c.'30', $unit->effectiveStreet());
        $sheet->setCellValue($c.'31', $block->building_door_no);   // Kapı/Bina no
        $this->setText($sheet, $s.'31', (string) $unit->unit_no);  // Daire no
        $this->setText($sheet, $c.'33', $block->project?->cadastral_parcel);
        $sheet->setCellValue($c.'35', $block->land_area);
        $sheet->setCellValue($c.'36', $unit->landShareRatioText()); // ör. 1/8
        $sheet->setCellValue($s.'36', $unit->landShareArea());      // m²
        $sheet->setCellValue($c.'37', $block->construction_type);
        $sheet->setCellValue($c.'38', $unit->effectiveConstructionClass());
        $sheet->setCellValue($c.'39', $unit->effectiveUsageType());
        $this->setDate($sheet, $c.'40', $block->construction_completion_date);
        $this->setDate($sheet, $c.'41', $block->acquisition_date);
        $sheet->setCellValue($c.'42', $block->restriction_status);
        $sheet->setCellValue($c.'43', $block->exemption_status);
        $sheet->setCellValue($c.'44', $block->reduced_tax);
        $sheet->setCellValue($c.'45', $unit->effectiveShareRatio());
        $sheet->setCellValue($c.'46', $unit->area);
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
     * Bina krokisi — "ev görünümü": tüm daireler TEK SÜTUNDA alt alta (üst kat
     * en üstte), üstünde köşegen kenarlıklı çatı üçgeni (/\). Her kutu dar:
     * üst satır "X NOLU DAİRE", alt satır yüzölçümü. Altta özet tablo.
     */
    private const KROKI_BOX_COLS = 6;   // tek sütun kutu genişliği
    private const KROKI_FLOOR_ROWS = 2; // kutu yüksekliği (isim 1 + m² 1)
    private const KROKI_ROOF_ROWS = 6;  // çatı yüksekliği (satır)

    private function buildKroki(Spreadsheet $ss, PropertyTaxBlock $block, $units): void
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
        $sheet->setTitle('KROKİ');

        // Daireleri sırala: üst kat en üstte, aynı katta soldan sağa
        $ordered = $units->sort(function ($a, $b) {
            $fa = $a->floor_no ?? 0;
            $fb = $b->floor_no ?? 0;
            if ($fa !== $fb) {
                return $fb <=> $fa; // üst kat önce
            }

            return ($a->floor_position ?? 0) <=> ($b->floor_position ?? 0);
        })->values();

        $boxW = self::KROKI_BOX_COLS;
        $firstCol = 2; // B
        $lastCol = $firstCol + $boxW - 1;
        $lastLetter = $this->colLetter($lastCol);

        // Başlık ("-" veya boş blok adını gösterme)
        $name = trim((string) $block->name);
        $prefix = ($name !== '' && $name !== '-') ? $name.' — ' : '';
        $sheet->mergeCells('B1:'.$lastLetter.'1');
        $sheet->setCellValue('B1', $prefix.'BİNA KROKİSİ');
        $sheet->getStyle('B1:'.$lastLetter.'1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Çatı: sol yarı köşegen yukarı (/), sağ yarı köşegen aşağı (\) → /\
        $roofTop = 3;
        $roofBottom = $roofTop + self::KROKI_ROOF_ROWS - 1;
        $mid = $firstCol + intdiv($boxW, 2);
        $leftRoof = $this->colLetter($firstCol).$roofTop.':'.$this->colLetter($mid - 1).$roofBottom;
        $rightRoof = $this->colLetter($mid).$roofTop.':'.$lastLetter.$roofBottom;
        $sheet->mergeCells($leftRoof);
        $sheet->mergeCells($rightRoof);
        $sheet->getStyle($leftRoof)->getBorders()->setDiagonalDirection(Borders::DIAGONAL_UP)
            ->getDiagonal()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle($rightRoof)->getBorders()->setDiagonalDirection(Borders::DIAGONAL_DOWN)
            ->getDiagonal()->setBorderStyle(Border::BORDER_MEDIUM);
        for ($r = $roofTop; $r <= $roofBottom; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(15);
        }

        // Daireler: tek sütun, alt alta (çatının hemen altından)
        $row = $roofBottom + 1;
        $prevFloor = null;
        foreach ($ordered as $u) {
            $floor = $u->floor_no;
            if ($floor !== null && $floor !== $prevFloor) {
                $sheet->setCellValue('A'.$row, $floor.'.KAT');
                $sheet->getStyle('A'.$row)->getFont()->setBold(true);
                $prevFloor = $floor;
            }

            $labelRange = $this->colLetter($firstCol).$row.':'.$lastLetter.$row;
            $areaRange = $this->colLetter($firstCol).($row + 1).':'.$lastLetter.($row + 1);
            $boxRange = $this->colLetter($firstCol).$row.':'.$lastLetter.($row + 1);
            $sheet->mergeCells($labelRange);
            $sheet->mergeCells($areaRange);

            $sheet->setCellValue($this->colLetter($firstCol).$row, $u->unit_no.' NOLU DAİRE');
            $sheet->setCellValue($this->colLetter($firstCol).($row + 1), $this->areaText($u->area));

            $sheet->getStyle($boxRange)->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle($boxRange)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle($labelRange)->getFont()->setBold(true);

            $row += self::KROKI_FLOOR_ROWS;
        }

        $this->buildKrokiSummary($sheet, $block, $units, $row + 1);

        $ss->setActiveSheetIndex($ss->getIndex($ss->getSheetByName('BEYANNAME 1')));
    }

    private function buildKrokiSummary(Worksheet $sheet, PropertyTaxBlock $block, $units, int $row): void
    {
        $project = $block->project;
        $totalArea = 0.0;
        foreach ($units as $u) {
            $totalArea += (float) $u->area;
        }

        $headers = ['YAPI SAHİBİ', 'KULLANIM AMACI', 'İLİ', 'İLÇESİ', 'MAHALLE', 'ADA/PARSEL', 'YAPI ALANI M2'];
        $values = [
            trim($project->taxpayer_surname.' '.$project->taxpayer_first_name),
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
