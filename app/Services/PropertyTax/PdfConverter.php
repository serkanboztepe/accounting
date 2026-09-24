<?php

namespace App\Services\PropertyTax;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Doldurulmuş .xlsx'i LibreOffice (soffice headless) ile birebir PDF'e çevirir.
 * "Formatlı PDF" = resmi formun aynısı + çatılı kroki (LibreOffice köşegeni çizer).
 *
 * soffice her çağrıda ayrı, yazılabilir bir çalışma dizini ister (HOME +
 * UserInstallation profili) — eşzamanlı çağrılar çakışmasın diye benzersiz dizin.
 */
class PdfConverter
{
    public function fromXlsx(string $xlsxPath): string
    {
        $soffice = config('property_tax.soffice');
        $work = sys_get_temp_dir().'/ptpdf_'.bin2hex(random_bytes(6));
        @mkdir($work, 0775, true);

        $input = $work.'/beyanname.xlsx';
        copy($xlsxPath, $input);

        $process = new Process(
            [
                $soffice, '--headless', '--convert-to', 'pdf', '--outdir', $work, $input,
                '-env:UserInstallation=file://'.$work.'/profile',
            ],
            $work,                    // CWD yazılabilir olmalı
            ['HOME' => $work],        // soffice HOME'a yazıyor
        );
        $process->setTimeout(120);
        $process->run();

        $pdf = $work.'/beyanname.pdf';
        if (! is_file($pdf)) {
            $this->rrmdir($work);
            throw new RuntimeException('LibreOffice PDF üretemedi: '.trim($process->getErrorOutput().' '.$process->getOutput()));
        }

        // PDF'i bağımsız geçici dosyaya al, çalışma dizinini sil (sızıntı olmasın)
        $out = tempnam(sys_get_temp_dir(), 'ptpdf_').'.pdf';
        copy($pdf, $out);
        $this->rrmdir($work);

        return $out;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
