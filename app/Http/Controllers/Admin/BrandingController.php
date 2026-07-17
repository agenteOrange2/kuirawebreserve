<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Central\PlatformSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Apariencia de la plataforma: nombre, logo, favicon y el lado derecho del
 * login (texto + fondo). Aplica en central Y en los dominios de los hoteles
 * (el login es universal). Archivos en el disco public (branding/).
 */
class BrandingController extends Controller
{
    /** Llaves de imagen: setting de path => campo del form. */
    protected const IMAGES = [
        'logo_path' => 'logo',
        'favicon_path' => 'favicon',
        'login_background_path' => 'login_background',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/Branding', [
            'settings' => [
                'app_name' => PlatformSetting::get('app_name', ''),
                'login_title' => PlatformSetting::get('login_title', ''),
                'login_subtitle' => PlatformSetting::get('login_subtitle', ''),
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
            'login_title' => ['nullable', 'string', 'max:120'],
            'login_subtitle' => ['nullable', 'string', 'max:300'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:ico,png,svg', 'max:512'],
            'login_background' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'remove_login_background' => ['sometimes', 'boolean'],
        ]);

        foreach (['app_name', 'login_title', 'login_subtitle'] as $key) {
            if ($request->has($key)) {
                PlatformSetting::set($key, trim((string) $request->input($key)) ?: null);
            }
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

    protected function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    protected function deleteFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
