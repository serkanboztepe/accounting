<?php

namespace App\Console\Commands;

use App\Models\AssistantLesson;
use Illuminate\Console\Command;

/**
 * Onaylı derslerin deneme vakaları → tests/Feature/Eval/cases.php'ye eklenecek biçimde yazdırır (Faz 3: hata bir daha
 * olursa deneme seti yakalasın). Beklenen sonucu (expect) geliştirici vakaya göre düzenler.
 *
 *   php artisan lessons:cases            onaylı + vakası olan dersler
 */
class LessonCases extends Command
{
    protected $signature = 'lessons:cases';

    protected $description = 'Onaylı derslerin deneme vakalarını cases.php biçiminde yazdırır';

    public function handle(): int
    {
        $rows = AssistantLesson::where('status', 'approved')->whereNotNull('example_message')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->info('Vakası olan onaylı ders yok.');

            return self::SUCCESS;
        }
        foreach ($rows as $l) {
            $this->line(sprintf("        // Ders #%d (%s): %s\n        // Beklenen: %s\n        ['id' => 'ders-%d', 'firm' => 'stok', 'group' => 'regression',\n            'steps' => [['send' => %s]],\n            'expect' => [/* TODO */]],",
                $l->id, $l->firm_name ?? 'genel', $l->rule, $l->expected ?? '-', $l->id, var_export($l->example_message, true)));
        }

        return self::SUCCESS;
    }
}
