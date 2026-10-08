<?php

namespace App\Http\Middleware;

use App\Models\HubFirm;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tek panel: isteğin firmasını seçer.
 *  - Oturumdan (girişte yazılır, bkz Auth\Login): panel ve yazdırma sayfaları.
 *  - İmzalı linkten (?firm=): Twilio'nun girişsiz indirdiği ekstre PDF'i gibi. İmza
 *    firm parametresini de kapsar, değiştirilemez.
 * Firma pasife alındıysa oturum kapatılır.
 */
class IdentifyTenant
{
    public const SESSION_KEY = 'tenant_id';

    /** Girişte şifresi doğrulanan firmalar — "Firma değiştir" yalnız bunlar arasında. */
    public const CHOICES_KEY = 'tenant_choices';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Tenancy::enabled() || Tenancy::current()) {
            return $next($request);
        }

        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! $id && $request->query('firm') && $request->hasValidSignature()) {
            $id = $request->query('firm');
        }

        if (! $id) {
            return $next($request);
        }

        $firm = HubFirm::find($id);

        if (! $firm || ! $firm->is_active || ! $firm->isLocal()) {
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return redirect('/admin/login');
        }

        Tenancy::activate($firm);

        return $next($request);
    }
}
