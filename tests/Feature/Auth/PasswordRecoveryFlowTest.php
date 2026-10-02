<?php

use App\Models\Central\PlatformSetting;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

// Recuperar la contraseña: en español, sin delatar qué correos tienen
// cuenta, con el enlace revisado al abrirlo y con aviso de seguridad al
// cambiarla.

test('un correo sin cuenta recibe la misma respuesta que uno con cuenta', function () {
    Notification::fake();
    $user = User::factory()->create();

    $conCuenta = $this->post(route('password.email'), ['email' => $user->email]);
    $sinCuenta = $this->post(route('password.email'), ['email' => 'nadie@ejemplo.com']);

    $conCuenta->assertSessionHasNoErrors()->assertSessionHas('status');
    $sinCuenta->assertSessionHasNoErrors()->assertSessionHas('status', session('status'));

    expect(session('status'))->toContain('Si ese correo tiene una cuenta');
    Notification::assertSentTo($user, ResetPasswordNotification::class);
    Notification::assertCount(1);
});

test('pedir otro enlace antes de un minuto avisa en español', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => 'Ya te enviamos un enlace hace un momento. Espera un minuto antes de pedir otro.']);
});

test('la pantalla de restablecer sabe si el enlace sirve antes de escribir nada', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/ResetPassword')
            ->where('tokenValid', true)
            ->has('requirements')
        );

    $this->get(route('password.reset', ['token' => 'vencido', 'email' => $user->email]))
        ->assertInertia(fn (Assert $page) => $page->where('tokenValid', false));
});

test('al restablecer cierra las otras sesiones y avisa por correo', function () {
    Notification::fake();
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    DB::table('sessions')->insert([
        'id' => 'sesion-vieja', 'user_id' => $user->id, 'ip_address' => '1.2.3.4',
        'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'nueva-segura-123',
        'password_confirmation' => 'nueva-segura-123',
    ])->assertRedirect(route('login'))->assertSessionHas('status', 'Listo, tu contraseña cambió. Entra con la nueva.');

    expect(DB::table('sessions')->where('id', 'sesion-vieja')->exists())->toBeFalse();
    Notification::assertSentTo($user, PasswordChangedNotification::class, fn ($n) => $n->via === 'reset');
});

test('los errores de la contraseña llegan en español', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'corta',
        'password_confirmation' => 'otra',
    ])->assertSessionHasErrors(['password']);

    expect(session('errors')->first('password'))->toContain('contraseña');
});

test('el correo de recuperación va en español, con el enlace y por el SMTP de la plataforma', function () {
    $user = User::factory()->create(['name' => 'Karla']);

    PlatformSetting::set('mail_host', 'smtp.example.com');
    PlatformSetting::set('mail_from_address', 'hola@example.com');
    PlatformSetting::set('mail_password', Crypt::encryptString('x'));

    $message = (new ResetPasswordNotification('tok-123'))->toMail($user);
    $html = (string) $message->render();

    expect($message->subject)->toStartWith('Crea tu contraseña nueva')
        ->and($message->mailer)->toBe('platform_smtp')
        ->and($html)->toContain('Hola Karla')
        ->and($html)->toContain('/reset-password/tok-123')
        ->and($html)->toContain('60 minutos');
});

test('cambiar la contraseña desde el perfil también avisa por correo', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('admin.settings.password.edit'))
        ->put(route('admin.settings.password.update'), [
            'current_password' => 'password',
            'password' => 'nueva-segura-123',
            'password_confirmation' => 'nueva-segura-123',
        ])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, PasswordChangedNotification::class, fn ($n) => $n->via === 'profile');
});

test('el aviso de cambio reconoce el navegador', function () {
    expect(PasswordChangedNotification::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1'))->toBe('Safari en iPhone/iPad')
        ->and(PasswordChangedNotification::device('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36'))->toBe('Chrome en Windows')
        ->and(PasswordChangedNotification::device(null))->toBeNull();
});
