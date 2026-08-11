<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Contexto del panel: null en el dominio central; datos del hotel
            // en sus subdominios (el menú lateral cambia según esto).
            'tenant' => tenancy()->initialized ? [
                'id' => tenant('id'),
                'name' => tenant('name'),
                'plan' => tenant('plan'),
            ] : null,
            // Contexto del PANEL para layout/menú. OJO: no usar 'tenant' para
            // esto — las páginas del admin pasan props llamados 'tenant' (datos
            // del hotel consultado) y pisarían al compartido, disfrazando al
            // admin de panel de hotel (bug real que ya ocurrió).
            'panelTenant' => tenancy()->initialized ? [
                'id' => tenant('id'),
                'name' => tenant('name'),
                'plan' => tenant('plan'),
                // Logo del hotel (el mismo del wizard) para el encabezado
                // del menú lateral; null = icono genérico.
                'logo_url' => ($logo = ($property = \App\Models\Property::query()->first())?->getFirstMedia('wizard_logo'))
                    ? '/fotos/logo?v='.$logo->id
                    : null,
                // Tema del panel por hotel (/ajustes/general/apariencia):
                // RazeLayout pisa las variables CSS del theme con esto.
                // Null u omitido = colores default de Kuira.
                'colors' => [
                    'primary' => ($property?->settings ?? [])['panel_primary'] ?? null,
                    'menu_from' => ($property?->settings ?? [])['panel_menu_from'] ?? null,
                    'menu_to' => ($property?->settings ?? [])['panel_menu_to'] ?? null,
                ],
                // Modo de operación (/ajustes/general): 'motel' enciende el
                // registro exprés del plano y los atajos de caseta.
                'property_mode' => ($property?->settings ?? [])['property_mode'] ?? 'hotel',
                // Módulos activos del hotel (plan + overrides): el menú
                // lateral oculta los items de módulos apagados.
                'modules' => tenant()->enabledModules(),
                // Llave pública VAPID para suscribirse a las notificaciones
                // push. Null = función apagada y el panel no la ofrece.
                'vapid_key' => config('webpush.public_key') ?: null,
                // Permisos efectivos del usuario (directos + por rol): el
                // menú lateral esconde lo que respondería 403. Vacío en
                // páginas públicas sin sesión.
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ] : null,
            // Branding de plataforma (login universal, layout). Cacheado.
            'branding' => [
                'app_name' => \App\Models\Central\PlatformSetting::get('app_name', 'KuiraReserve'),
                'logo_url' => ($p = \App\Models\Central\PlatformSetting::get('logo_path')) ? '/storage/'.$p : null,
                'login_title' => \App\Models\Central\PlatformSetting::get('login_title'),
                'login_subtitle' => \App\Models\Central\PlatformSetting::get('login_subtitle'),
                'login_background_url' => ($b = \App\Models\Central\PlatformSetting::get('login_background_path')) ? '/storage/'.$b : null,
            ],
            'auth' => [
                'user' => $request->user(),
            ],
            // Mensajes flash de sesión (back()->with('success'|'error', ...)):
            // las páginas los convierten en toasts tras cada acción.
            'flash' => fn () => [
                'success' => $request->hasSession() ? $request->session()->get('success') : null,
                'error' => $request->hasSession() ? $request->session()->get('error') : null,
            ],
            'ziggy' => fn () => [
                ...(new \Tighten\Ziggy\Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
