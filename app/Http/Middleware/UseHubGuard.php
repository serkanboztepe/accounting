<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * /hub isteklerinde varsayılan giriş = hub yöneticisi. Aynı tarayıcıda /admin'e de girilmişse
 * Laravel (veritabanı oturum kaydının user_id'si vb.) varsayılan 'web' kullanıcısını yüklemeye
 * çalışıyordu; hub'da firma seçili olmadığından firma veritabanına giden sorgu 500 veriyordu.
 */
class UseHubGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('hub');

        return $next($request);
    }
}
