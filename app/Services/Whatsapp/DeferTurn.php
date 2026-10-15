<?php

namespace App\Services\Whatsapp;

/**
 * Bu tur Twilio'nun bekleme süresine (~15 sn) sığmayacak: kişiye hemen "bakıyorum" gider, tur istek kapandıktan sonra
 * süresiz yeniden çalışır ve sonuç ayrı WhatsApp mesajıyla gönderilir (Faz 1 — "önce cevap, sonra düşün").
 */
final class DeferTurn extends \RuntimeException {}
