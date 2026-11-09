<?php

namespace App\Services\Agent;

use Illuminate\Http\Request;

/**
 * Ajan turu (plan v5 §1): "ajan canlı" firmada gelen her mesaj buraya gelir.
 *
 * Dönüş: müşteriye gidecek cevap(lar) ya da null. null = "bu turu ben almadım, eski yol işlesin" (ajan hazır değil,
 * hata, sınır aşıldı) — müşteri hiçbir durumda cevapsız kalmaz.
 *
 * Aşama A: yalnız kanca. Döngü (AgentRunner), araçlar ve öneri/onay Aşama B–D'de gelir; o zamana kadar her tur null.
 */
class AgentTurn
{
    /** @return string|array{0:string,1:?string}|null */
    public function handle(Request $request, string $phone, string $body, int $numMedia): string|array|null
    {
        return null;
    }
}
