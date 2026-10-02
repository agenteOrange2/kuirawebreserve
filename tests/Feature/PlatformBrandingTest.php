<?php

use App\Models\Central\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Stancl\Tenancy\CacheManager as TenantCacheManager;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

// Marca de la plataforma en /admin/settings/brand: nombre, logo, favicon y el
// login completo. Aplica también en los dominios de los hoteles.

function platformAdminForBranding(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

test('la marca vive en /admin/settings/brand y la liga vieja redirige', function () {
    $this->actingAs(platformAdminForBranding());

    expect(route('admin.branding', [], false))->toBe('/admin/settings/brand');

    $this->get('/admin/apariencia')->assertRedirect('/admin/settings/brand');

    $this->get('/admin/settings/brand')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Branding')
            ->where('settings.login_overlay', 'strong')
            ->where('settings.logo_url', null)
        );
});

test('guarda textos del login, velo y un logo SVG', function () {
    Storage::fake('public');
    $this->actingAs(platformAdminForBranding());

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
    );

    $this->post(route('admin.branding.update'), [
        'app_name' => 'Kuira Hoteles',
        'login_heading' => 'Bienvenido de nuevo',
        'login_hint' => 'Entra con tu correo',
        'login_title' => 'Tu hotel, en orden',
        'login_overlay' => 'light',
        'logo' => $svg,
    ])->assertRedirect(route('admin.branding'))->assertSessionHasNoErrors();

    expect(PlatformSetting::get('app_name'))->toBe('Kuira Hoteles')
        ->and(PlatformSetting::get('login_heading'))->toBe('Bienvenido de nuevo')
        ->and(PlatformSetting::get('login_hint'))->toBe('Entra con tu correo')
        ->and(PlatformSetting::get('login_overlay'))->toBe('light')
        ->and(PlatformSetting::get('logo_path'))->toStartWith('branding/');

    Storage::disk('public')->assertExists(PlatformSetting::get('logo_path'));

    // El share del login lleva lo nuevo.
    $this->get('/admin/settings/brand')->assertInertia(fn (Assert $page) => $page
        ->where('branding.login_heading', 'Bienvenido de nuevo')
        ->where('branding.login_overlay', 'light')
        ->where('settings.logo_url', '/storage/'.PlatformSetting::get('logo_path'))
    );
});

test('quitar el logo borra el archivo y no toca los textos', function () {
    Storage::fake('public');
    $this->actingAs(platformAdminForBranding());

    $this->post(route('admin.branding.update'), [
        'app_name' => 'Kuira Hoteles',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ]);
    $path = PlatformSetting::get('logo_path');

    $this->post(route('admin.branding.update'), ['remove_logo' => true])->assertRedirect();

    expect(PlatformSetting::get('logo_path'))->toBeNull()
        ->and(PlatformSetting::get('app_name'))->toBe('Kuira Hoteles');
    Storage::disk('public')->assertMissing($path);
});

test('rechaza un velo desconocido', function () {
    $this->actingAs(platformAdminForBranding())
        ->post(route('admin.branding.update'), ['login_overlay' => 'neon'])
        ->assertSessionHasErrors('login_overlay');
});

test('lo que guarda el admin lo ve el hotel aunque el hotel ya lo tuviera en caché', function () {
    // El CacheTenancyBootstrapper etiqueta la caché con el tenant: antes el
    // hotel guardaba su copia etiquetada y el forget del admin (sin etiqueta)
    // nunca la alcanzaba, así que el login del hotel se quedaba con la marca
    // vieja para siempre.
    $manager = new TenantCacheManager(app());
    app()->instance('cache', $manager);
    Cache::clearResolvedInstance('cache');
    app()->instance(TenantContract::class, new Tenant(['id' => 'hotel-a']));

    PlatformSetting::query()->create(['key' => 'app_name', 'value' => 'Marca vieja']);
    expect(PlatformSetting::get('app_name'))->toBe('Marca vieja');

    // El admin cambia la marca desde el dominio central: su forget va al
    // store sin etiquetas.
    PlatformSetting::query()->where('key', 'app_name')->update(['value' => 'Marca nueva']);
    $manager->store()->forget('platform_setting.app_name');

    expect(PlatformSetting::get('app_name'))->toBe('Marca nueva');
});
