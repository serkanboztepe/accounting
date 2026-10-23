<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\TurnTrace;
use Illuminate\Console\Command;

/**
 * WhatsApp teşhis izlerinin temizliği (TurnTrace): eski gün klasörleri silinir, ardından KALAN turların hiçbirinin kullanmadığı
 * bloblar silinir. Yazım sürerken (bir tur blob'larını yazmış ama tur dosyası henüz yok) blob silinmesin diye firma başına ortak
 * kilit: yazıcılar paylaşımlı, temizlik özel kilit alır; kilit alınamazsa o firma bu sefer atlanır. Ayrıca 1 günden yeni sahipsiz
 * blob silinmez (kilitsiz kalan yarım yazımlara pay).
 */
class WhatsappTracePrune extends Command
{
    protected $signature = 'whatsapp:trace-prune {--days=30 : Kaç günlük iz kalsın} {--wait=30 : Kilit için en çok kaç sn beklensin}';

    protected $description = 'WhatsApp teşhis izlerini ve artık kullanılmayan blobları siler';

    private const ORPHAN_GRACE_SECONDS = 86400;

    public function handle(): int
    {
        $root = TurnTrace::root();
        if (! is_dir($root)) {
            $this->line('İz klasörü yok.');

            return self::SUCCESS;
        }
        $cutoff = now('Europe/Istanbul')->subDays(max(0, (int) $this->option('days')))->toDateString();
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $firmDir) {
            $lock = TurnTrace::lock($firmDir . '/.gc.lock', LOCK_EX, (float) $this->option('wait'));
            if ($lock === null) {
                $this->warn(basename($firmDir) . ': iz yazımı sürüyor, temizlik atlandı');

                continue;
            }
            try {
                [$days, $blobs] = $this->pruneFirm($firmDir, $cutoff);
                $this->line(basename($firmDir) . ": {$days} gün klasörü, {$blobs} blob silindi");
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return self::SUCCESS;
    }

    /** @return array{0:int,1:int} */
    private function pruneFirm(string $firmDir, string $cutoff): array
    {
        $deletedDays = 0;
        $referenced = [];
        foreach (glob($firmDir . '/*', GLOB_ONLYDIR) ?: [] as $dayDir) {
            $day = basename($dayDir);
            if ($day === 'blobs' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            if ($day < $cutoff) {
                foreach (array_merge(glob($dayDir . '/*') ?: [], glob($dayDir . '/.*') ?: []) as $f) {
                    if (is_file($f)) {
                        unlink($f);
                    }
                }
                @rmdir($dayDir);
                $deletedDays++;

                continue;
            }
            foreach (glob($dayDir . '/*.json') ?: [] as $file) {
                if (preg_match_all('/"\$blob":\s*"([0-9a-f]{64})"/', (string) file_get_contents($file), $m)) {
                    foreach ($m[1] as $hash) {
                        $referenced[$hash] = true;
                    }
                }
            }
        }
        $deletedBlobs = 0;
        $graceUntil = time() - self::ORPHAN_GRACE_SECONDS;
        foreach (glob($firmDir . '/blobs/*') ?: [] as $blob) {
            $name = basename($blob);
            $tmp = str_ends_with($name, '.tmp');
            if (! $tmp && isset($referenced[$name])) {
                continue;
            }
            if (filemtime($blob) < $graceUntil) {
                unlink($blob);
                $deletedBlobs++;
            }
        }

        return [$deletedDays, $deletedBlobs];
    }
}
