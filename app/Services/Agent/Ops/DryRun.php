<?php

namespace App\Services\Agent\Ops;

use Illuminate\Support\Facades\DB;

/**
 * Deneme kaydı (plan v5 §2): işlemler GERÇEK kayıt yolundan geçirilir ama her zaman geri alınır. Kartın ve ajanın gördüğü etki
 * (oluşacak satırlar, bakiye önce → sonra) bu yüzden tahmin değil, kaydın kendisidir.
 */
final class DryRun
{
    private static int $depth = 0;

    /** Şu an deneme kaydı içinde miyiz — veritabanı DIŞI yan etkiler (mesaj, dosya, önbellek) bunu görürse susar. */
    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(callable $work): mixed
    {
        DB::beginTransaction();
        self::$depth++;
        try {
            return $work();
        } finally {
            self::$depth--;
            DB::rollBack();
        }
    }
}
