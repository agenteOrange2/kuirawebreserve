<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marca de la plataforma (/admin/settings/brand): nombre, logo, favicon,
 * todo el login (encabezado del formulario, lado derecho con texto, fondo
 * y velo) y el contacto de soporte que se ofrece a los hoteles suspendidos.
 * Aplica en central Y en los dominios de los hoteles (el login es
 * universal). Archivos en el disco public (branding/).
 */
class BrandingController extends Controller
{
    /** Llaves de imagen: setting de path => campo del form. */
    protected const IMAGES = [
        'logo_path' => 'logo',
        'favicon_path' => 'favicon',
        'login_background_path' => 'login_background',
    ];

    /** Llaves de texto que se guardan tal cual (recortadas; vacío = default). */
    protected const TEXTS = [
        'app_name', 'login_heading', 'login_hint', 'login_title', 'login_subtitle',
        'support_email', 'support_whatsapp',
    ];

    /** Intensidad del velo sobre la imagen de fondo del login. */
    public const OVERLAYS = ['strong', 'medium', 'light'];

    public function index(): Response
    {
        $settings = [];
        foreach (self::TEXTS as $key) {
            $settings[$key] = PlatformSetting::get($key, '');
        }

        return Inertia::render('admin/Branding', [
            'settings' => $settings + [
                'login_overlay' => PlatformSetting::get('login_overlay', 'strong'),
                'logo_url' => $this->url(PlatformSetting::get('logo_path')),
                'favicon_url' => $this->url(PlatformSetting::get('favicon_path')),
                'login_background_url' => $this->url(PlatformSetting::get('login_background_path')),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'app_name' => ['nullable', 'string', 'max:60'],
            'login_heading' => ['nullable', 'string', 'max:80'],
            'login_hint' => ['nullable', 'string', 'max:160'],
            'login_title' => ['nullable', 'string', 'max:120'],
            'login_subtitle' => ['nullable', 'string', 'max:300'],
            'login_overlay' => ['nullable', Rule::in(self::OVERLAYS)],
            'support_email' => ['nullable', 'email', 'max:120'],
            'support_whatsapp' => ['nullable', 'string', 'max:30'],
            // Laravel 12 saca el SVG de la regla `image` salvo allow_svg; sin
            // esto el formulario ofrecía SVG y luego lo rechazaba.
            'logo' => ['nullable', 'image:allow_svg', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:ico,png,svg', 'max:512'],
            'login_background' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'remove_login_background' => ['sometimes', 'boolean'],
        ], [], [
            'app_name' => 'nombre de la plataforma',
            'login_heading' => 'encabezado del formulario',
            'login_hint' => 'instrucción del formulario',
            'login_title' => 'título del login',
            'login_subtitle' => 'texto de apoyo',
            'support_email' => 'correo de soporte',
            'support_whatsapp' => 'WhatsApp de soporte',
            'logo' => 'logo',
            'favicon' => 'favicon',
            'login_background' => 'imagen de fondo',
        ]);

        foreach (self::TEXTS as $key) {
            if ($request->has($key)) {
                PlatformSetting::set($key, trim((string) $request->input($key)) ?: null);
            }
        }

        if ($request->filled('login_overlay')) {
            PlatformSetting::set('login_overlay', $request->input('login_overlay'));
        }

        foreach (self::IMAGES as $setting => $field) {
            if ($request->boolean("remove_{$field}")) {
                $this->deleteFile(PlatformSetting::get($setting));
                PlatformSetting::set($setting, null);
            }

            if ($request->hasFile($field)) {
                $this->deleteFile(PlatformSetting::get($setting));
                PlatformSetting::set($setting, $request->file($field)->store('branding', 'public'));
            }
        }

        return redirect()->route('admin.branding');
    }

    /** Relativa, igual que el share de HandleInertiaRequests: sirve en central y en los hoteles. */
    protected function url(?string $path): ?string
    {
        return $path ? '/storage/'.$path : null;
    }

    protected function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
