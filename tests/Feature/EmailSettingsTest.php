<?php

use App\Mail\PlatformTestMail;
use App\Models\Central\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

function platformAdminForEmail(): User
{
    Role::findOrCreate('platform-admin');
    $user = User::factory()->create();
    $user->assignRole('platform-admin');

    return $user;
}

test('el admin puede ver y guardar la configuración de correo', function () {
    $this->actingAs(platformAdminForEmail())
        ->get(route('admin.settings.email.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Email')
            ->where('configured', false)
            ->where('autoEmailEnabled', true)
            ->where('settings.has_password', false)
        );

    $this->patch(route('admin.settings.email.update'), [
        'mail_host' => 'smtp.zoho.com',
        'mail_port' => 587,
        'mail_username' => 'hola@kuira.test',
        'mail_password' => 'secreta-123',
        'mail_from_address' => 'hola@kuira.test',
        'mail_from_name' => 'KuiraWebReserve',
        'prospects_auto_email' => true,
    ])->assertRedirect();

    expect(PlatformSetting::get('mail_host'))->toBe('smtp.zoho.com')
        ->and(PlatformSetting::get('mail_from_address'))->toBe('hola@kuira.test')
        ->and(Crypt::decryptString(PlatformSetting::get('mail_password')))->toBe('secreta-123')
        ->and(PlatformSetting::get('prospects_auto_email'))->toBe('1');

    // Guardar de nuevo sin contraseña la conserva; apagar el auto lo persiste.
    $this->patch(route('admin.settings.email.update'), [
        'mail_host' => 'smtp.zoho.com',
        'mail_port' => 587,
        'mail_username' => 'hola@kuira.test',
        'mail_password' => '',
        'mail_from_address' => 'hola@kuira.test',
        'mail_from_name' => 'KuiraWebReserve',
        'prospects_auto_email' => false,
    ])->assertRedirect();

    expect(Crypt::decryptString(PlatformSetting::get('mail_password')))->toBe('secreta-123')
        ->and(PlatformSetting::get('prospects_auto_email'))->toBe('0');
});

test('el correo de prueba usa el SMTP configurado', function () {
    Mail::fake();

    PlatformSetting::set('mail_host', 'smtp.zoho.com');
    PlatformSetting::set('mail_from_address', 'hola@kuira.test');

    $admin = platformAdminForEmail();

    $this->actingAs($admin)
        ->post(route('admin.settings.email.test'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertSent(PlatformTestMail::class, fn (PlatformTestMail $mail) => $mail->hasTo($admin->email));
});

test('sin SMTP configurado la prueba avisa en vez de enviar', function () {
    Mail::fake();

    $this->actingAs(platformAdminForEmail())
        ->post(route('admin.settings.email.test'))
        ->assertRedirect()
        ->assertSessionHas('error');

    Mail::assertNothingSent();
});

test('la configuración de correo requiere rol de plataforma', function () {
    $this->get(route('admin.settings.email.edit'))->assertRedirect(route('login'));

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.settings.email.edit'))
        ->assertForbidden();
});
