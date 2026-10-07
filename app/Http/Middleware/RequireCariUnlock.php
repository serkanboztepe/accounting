<?php

namespace App\Http\Middleware;

use App\Filament\Pages\CariUnlock;
use App\Support\CariLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cariler sayfaları + ekstre yazdır: kilitliyse şifre sayfasına yönlendirir,
 * açıksa süreyi yeniler. Livewire güncellemelerinde (sayfa içi işlemler) sadece
 * süreyi yeniler — sayfa açıkken yapılan iş "işlem" sayılır.
 */
class RequireCariUnlock
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! CariLock::enabled()) {
            return $next($request);
        }

        if (CariLock::isOpen()) {
            CariLock::touch();

            return $next($request);
        }

        if ($request->hasHeader('X-Livewire')) {
            abort(403, 'Cariler kilitlendi. Sayfayı yenileyip şifrenizi girin.');
        }

        // panel açıkça verilir: ekstre yazdır rotası panel dışında.
        return redirect()->to(CariUnlock::getUrl(['redirect' => $request->fullUrl()], panel: 'admin'));
    }
}
