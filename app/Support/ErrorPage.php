<?php

namespace App\Support;

use App\Models\Central\PlatformSetting;
use Illuminate\Http\Request;

/**
 * Arma lo que se le muestra al usuario cuando una petición termina en
 * error: el texto de config/error-pages.php más el contexto de a dónde
 * puede volver y a quién escribirle.
 *
 * Lo consumen los dos caminos de bootstrap/app.php — la página Inertia con
 * el theme y, si esa no se puede armar, el respaldo Blade — para que digan
 * exactamente lo mismo.
 */
class ErrorPage
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(Request $request, int $status): array
    {
        $copy = array_merge(
            config('error-pages.default'),
            config("error-pages.statuses.{$status}", []),
        );

        $user = static::user($request);

        // Los consejos de panel (buscador, menú, pedirle permisos a la
        // gerencia) solo se le dan a quien tiene sesión: al huésped lo
        // confundirían más que el error mismo.
        $hints = array_values(array_merge(
            $copy['hints'] ?? [],
            $user ? ($copy['hints_panel'] ?? []) : [],
        ));

        return [
            'status' => $status,
            'icon' => $copy['icon'],
            'tone' => $copy['tone'],
            'badge' => $copy['badge'],
            'title' => $copy['title'],
            'body' => $copy['body'],
            'hints' => $hints,
            'folio' => static::folio($status),
            'home' => static::home($user !== null),
            'support' => static::support(),
            'appName' => static::appName(),
        ];
    }

    /**
     * Igual que payload(), pero a prueba de todo: es la que usan las vistas
     * Blade de respaldo, que se pintan justo cuando algo tan básico como la
     * base de datos puede estar caído.
     *
     * @return array<string, mixed>
     */
    public static function safePayload(int $status): array
    {
        try {
            return static::payload(request(), $status);
        } catch (\Throwable) {
            $copy = array_merge(
                (array) config('error-pages.default', []),
                (array) config("error-pages.statuses.{$status}", []),
            );

            return [
                'status' => $status,
                'icon' => $copy['icon'] ?? 'TriangleAlert',
                'tone' => $copy['tone'] ?? 'dark',
                'badge' => $copy['badge'] ?? 'Error',
                'title' => $copy['title'] ?? 'No pudimos mostrar esta página',
                'body' => $copy['body'] ?? '',
                'hints' => array_values($copy['hints'] ?? []),
                'folio' => static::folio($status),
                'home' => ['url' => '/', 'label' => 'Ir al inicio'],
                'support' => ['email' => null, 'whatsapp' => null],
                'appName' => (string) config('app.name', 'KuiraReserve'),
            ];
        }
    }

    /**
     * El folio solo tiene sentido cuando hay algo que rastrear en el log.
     * En un 404 o un 403 no hay incidente, y en mantenimiento (503) no se
     * registra ninguna excepción: un folio ahí mandaría a soporte a buscar
     * una línea que no existe.
     */
    protected static function folio(int $status): ?string
    {
        return $status >= 500 && $status !== 503 ? ErrorReference::current() : null;
    }

    /**
     * El usuario en sesión, si lo hay. En mantenimiento o sin sesión
     * arrancada, user() revienta; ahí se trata como visitante y no se
     * arrastra el error.
     */
    protected static function user(Request $request): mixed
    {
        try {
            return $request->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A dónde ofrecemos volver. Al personal del hotel lo mandamos a su
     * panel; al huésped que andaba reservando, a la portada del hotel.
     *
     * @return array{url: string, label: string}
     */
    protected static function home(bool $withSession): array
    {
        $inTenant = tenancy()->initialized;

        if ($withSession) {
            return $inTenant
                ? ['url' => '/dashboard', 'label' => 'Ir al panel del hotel']
                : ['url' => '/admin', 'label' => 'Ir al panel de la plataforma'];
        }

        return $inTenant
            ? ['url' => '/', 'label' => 'Ir al inicio del hotel']
            : ['url' => '/', 'label' => 'Ir al inicio'];
    }

    /**
     * Contacto de la plataforma. Se consulta con tolerancia a fallos: estas
     * pantallas salen justamente cuando algo está roto, y una consulta que
     * reviente aquí dejaría al usuario otra vez con la página gris.
     *
     * @return array{email: string|null, whatsapp: string|null}
     */
    protected static function support(): array
    {
        try {
            $digits = preg_replace('/\D+/', '', (string) PlatformSetting::get('support_whatsapp')) ?? '';

            return [
                'email' => PlatformSetting::get('support_email'),
                // Misma normalización que EnsureTenantIsActive: lada 52 en
                // los números de 10 dígitos.
                'whatsapp' => $digits === '' ? null : (strlen($digits) === 10 ? '52'.$digits : $digits),
            ];
        } catch (\Throwable) {
            return ['email' => null, 'whatsapp' => null];
        }
    }

    protected static function appName(): string
    {
        try {
            return (string) PlatformSetting::get('app_name', config('app.name', 'KuiraReserve'));
        } catch (\Throwable) {
            return (string) config('app.name', 'KuiraReserve');
        }
    }
}
