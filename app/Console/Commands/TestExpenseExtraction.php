<?php

namespace App\Console\Commands;

use App\Services\Whatsapp\ExpenseExtractor;
use Illuminate\Console\Command;

/**
 * WhatsApp'a bağlanmadan AI beynini test et — "okuyor mu" ispatı.
 *
 *   php artisan whatsapp:test-extract --text="Ahmet Beton'a 3500 tl yakıt aldım"
 *   php artisan whatsapp:test-extract --image=/tmp/fis.jpg
 *   php artisan whatsapp:test-extract --text="..." --image=/tmp/dekont.png
 */
class TestExpenseExtraction extends Command
{
    protected $signature = 'whatsapp:test-extract {--text= : Serbest metin} {--image= : Görsel dosya yolu}';

    protected $description = 'Metin/fotoğraftan gider çıkarmayı WhatsApp olmadan test eder';

    public function handle(ExpenseExtractor $extractor): int
    {
        $text = $this->option('text');
        $imagePath = $this->option('image');

        if (! $text && ! $imagePath) {
            $this->error('En az --text veya --image ver.');

            return self::FAILURE;
        }

        $image = null;
        if ($imagePath) {
            if (! is_file($imagePath)) {
                $this->error("Dosya bulunamadı: {$imagePath}");

                return self::FAILURE;
            }
            $mime = mime_content_type($imagePath) ?: 'image/jpeg';
            $image = ['media_type' => $mime, 'data' => base64_encode((string) file_get_contents($imagePath))];
        }

        $modelKey = $image !== null ? 'vision_model' : 'model';
        $this->info('AI çağrılıyor (' . config('services.anthropic.' . $modelKey) . ')...');

        try {
            $data = $extractor->extract($text, $image);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
