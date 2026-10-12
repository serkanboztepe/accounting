<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel yanıt başlıkları (LiteSpeed .htaccess "Header" satırlarını uygulamıyor — burada):
 *  - X-Robots-Tag: panel (giriş, hub, ekstre linkleri) arama motorlarında çıkmasın.
 *  - HSTS (yalnız canlı): tarayıcı bir kez gördükten sonra hep https ile gelsin; http → https
 *    yönlendirmesi public/.htaccess'te.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
