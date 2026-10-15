<?php

namespace App\Support;

/**
 * Proje / Şube: aynı özellik (kayıtların hangi yere ait olduğu — project_id), firmaya göre yalnız ADI değişir.
 * Ad: hub firma ayarı `project_label` (config app.project_label); boşsa sektöre göre — mimar/müteahhit "Proje",
 * ticaret ve alacak-verecek "Şube".
 */
class Labels
{
    public static function project(): string
    {
        $label = trim((string) config('app.project_label'));
        if ($label !== '') {
            return $label;
        }

        return in_array(config('app.profile'), ['trade', 'alacak_verecek'], true) ? 'Şube' : 'Proje';
    }

    /** "Şubeler" / "Projeler" / "Ahırlar" (serbest adda -ler/-lar). */
    public static function projects(): string
    {
        $one = self::project();
        $vowels = ['a' => 'lar', 'ı' => 'lar', 'o' => 'lar', 'u' => 'lar', 'e' => 'ler', 'i' => 'ler', 'ö' => 'ler', 'ü' => 'ler'];
        $last = null;
        foreach (array_reverse(mb_str_split(mb_strtolower($one))) as $ch) {
            if (isset($vowels[$ch])) {
                $last = $vowels[$ch];
                break;
            }
        }

        return $one . ($last ?? 'ler');
    }

    /** Büyük harf başlık (WhatsApp bağlamı): "ŞUBELER" / "PROJELER". */
    public static function projectsUpper(): string
    {
        return mb_strtoupper(strtr(self::projects(), ['i' => 'İ', 'ı' => 'I']));
    }
}
