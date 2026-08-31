<?php

namespace App\Http\Middleware;

use App\Models\Central\PlatformSetting;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso al panel de un hotel suspendido desde la plataforma
 * (sin borrar sus datos). En contexto central no hace nada, por lo que es
 * seguro en rutas universales (Fortify).
 *
 * En vez de un abort(403) pelón (la página fea de Laravel), devuelve una
 * página propia con el theme; las rutas stateless (api/*) siguen viendo
 * JSON porque ahí no hay sesión ni Inertia.
 */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized || ! tenant()->isSuspended()) {
            return $next($request);
        }

        $message = 'Este hotel está suspendido. Contacta al administrador de la plataforma.';

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 403);
        }

        return Inertia::render('TenantSuspended', [
            'hotel' => tenant('name'),
            'suspendedSince' => tenant()->suspended_at?->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            'platformUrl' => config('app.url'),
            'support' => [
                'email' => PlatformSetting::get('support_email'),
                'whatsapp' => self::whatsappNumber(PlatformSetting::get('support_whatsapp')),
            ],
        ])->toResponse($request)->setStatusCode(403);
    }

    /**
     * Teléfono normalizado para wa.me; lada 52 por defecto en números de 10
     * dígitos (mismo criterio que PlanProspect).
     */
    protected static function whatsappNumber(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return strlen($digits) === 10 ? '52'.$digits : $digits;
    }
}
