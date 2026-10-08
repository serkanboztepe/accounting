<?php

namespace App\Http\Middleware;

use App\Support\HubSignature;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Twilio webhook imza doğrulaması (X-Twilio-Signature).
 * İmza = base64(HMAC-SHA1(auth_token, URL + sıralı POST anahtar/değerleri)).
 * Adresi bilen biri sahte mesajla kayıt açamasın / cari bakiyesi-ekstre çekemesin.
 */
class VerifyTwilioSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.twilio.verify_signature')) {
            return $next($request);
        }

        // Firma kurulumu: hub'dan imzalı iletilen mesaj (telefon hub'da kontrol edildi).
        if ($request->hasHeader(HubSignature::HEADER) && config('app.role') !== 'hub' && ! Tenancy::enabled()) {
            if (HubSignature::verify(
                $request->post(),
                (string) config('services.hub.secret'),
                (string) $request->header(HubSignature::HEADER),
                (string) $request->header(HubSignature::TIMESTAMP_HEADER, ''),
            )) {
                $request->attributes->set('via_hub', true);

                return $next($request);
            }

            Log::warning('WhatsApp webhook: geçersiz hub imzası', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        $token = (string) config('services.twilio.token');
        $given = (string) $request->header('X-Twilio-Signature', '');

        if ($token === '' || $given === '' || ! $this->matches($request, $token, $given)) {
            Log::warning('WhatsApp webhook: geçersiz Twilio imzası', ['ip' => $request->ip()]);

            return response('Forbidden', 403);
        }

        return $next($request);
    }

    private function matches(Request $request, string $token, string $given): bool
    {
        $params = $request->post();
        ksort($params);
        $payload = '';
        foreach ($params as $key => $value) {
            $payload .= $key . $value;
        }

        // Twilio'nun imzaladığı URL: istek URL'i; proxy/şema farkına karşı APP_URL tabanlısı da denenir.
        $urls = array_unique([
            $request->fullUrl(),
            rtrim((string) config('app.url'), '/') . '/' . ltrim($request->path(), '/'),
        ]);

        foreach ($urls as $url) {
            $expected = base64_encode(hash_hmac('sha1', $url . $payload, $token, true));
            if (hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }
}
