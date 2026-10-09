<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * /hub istekleri merkezde çalışır:
 *  - Varsayılan giriş = hub yöneticisi. Aynı tarayıcıda /admin'e de girilmişse Laravel (veritabanı
 *    oturum kaydının user_id'si vb.) 'web' kullanıcısını firma seçilmeden yüklemeye çalışıp 500 veriyordu.
 *  - Varsayılan veritabanı = merkez. Filament'in kendi işlemleri (ör. iki adımlı giriş kurulumundaki
 *    DB::transaction) varsayılan bağlantıyı kullanıyor; o "firma seçilmedi" olunca kayıt reddediliyordu.
 *    Hub'ın firma verisine dokunduğu yerler Tenancy::run ile gider ve sonunda buraya (merkez) döner.
 */
class UseHubGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('hub');
        config(['database.default' => 'central']);
        DB::setDefaultConnection('central');

        return $next($request);
    }
}
