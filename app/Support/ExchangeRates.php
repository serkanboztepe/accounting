<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * TCMB döviz satış kuru (USD, EUR → TL) — hub Rapor'u dolar/euro giderleri TL'ye çevirir. Geçmiş ay: ayın son iş günü
 * (hafta sonu / tatilde geriye doğru en çok 7 gün); içinde bulunulan ay: bugün. Geçmiş gün kalıcı önbellekte, bugün 6 saat.
 * TCMB'ye ulaşılamazsa son bilinen kur; o da yoksa null (Rapor "kur alınamadı" der).
 */
final class ExchangeRates
{
    /** @return array{USD: float, EUR: float, date: string}|null */
    public static function forMonth(CarbonInterface $month): ?array
    {
        $today = now('Europe/Istanbul')->startOfDay();
        $end = Carbon::parse($month, 'Europe/Istanbul')->endOfMonth()->startOfDay();
        $day = $end->gt($today) ? $today : $end;

        for ($i = 0; $i < 8; $i++) {
            $d = $day->copy()->subDays($i);
            if ($rates = self::forDay($d, $d->isSameDay($today))) {
                Cache::forever('hub-rate-last', $rates);

                return $rates;
            }
        }

        return Cache::get('hub-rate-last');
    }

    public static function toTry(float $amount, string $currency, ?array $rates): ?float
    {
        if ($currency === 'TRY') {
            return $amount;
        }

        return isset($rates[$currency]) ? $amount * $rates[$currency] : null;
    }

    private static function forDay(Carbon $day, bool $isToday): ?array
    {
        $key = 'hub-rate:' . $day->toDateString();
        if (($hit = Cache::get($key)) !== null) {
            return $hit ?: null;
        }
        $url = $isToday ? 'https://www.tcmb.gov.tr/kurlar/today.xml'
            : 'https://www.tcmb.gov.tr/kurlar/' . $day->format('Ym') . '/' . $day->format('dmY') . '.xml';
        try {
            $r = Http::timeout(6)->get($url);
            $rates = $r->successful() ? self::parse($r->body(), $day) : null;
        } catch (Throwable) {
            return null; // ağ hatası önbelleğe yazılmaz, sonra yeniden denenir
        }
        // Tatil günü (404) da hatırlanır (false) — her açılışta tekrar sorulmasın.
        $isToday ? Cache::put($key, $rates ?? false, now()->addHours(6)) : Cache::forever($key, $rates ?? false);

        return $rates;
    }

    private static function parse(string $xml, Carbon $day): ?array
    {
        $out = [];
        foreach (['USD', 'EUR'] as $code) {
            if (preg_match('/<Currency[^>]*Kod="' . $code . '"[^>]*>.*?<ForexSelling>([\d.]+)<\/ForexSelling>/s', $xml, $m)) {
                $out[$code] = (float) $m[1];
            }
        }

        return isset($out['USD'], $out['EUR']) ? $out + ['date' => $day->toDateString()] : null;
    }
}
