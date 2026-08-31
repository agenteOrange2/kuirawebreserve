<?php

use App\Http\Controllers\AvatarController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Foto de perfil de quien usa el panel. Vive igual en los dos paneles y se
 * sube SIEMPRE sobre uno mismo: el id de la URL solo sirve para servirla.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->user = User::factory()->create();
});

function uploadAvatar(User $user, ?UploadedFile $file = null)
{
    $request = Request::create('/avatar', 'POST', [], [], [
        'avatar' => $file ?? UploadedFile::fake()->image('yo.jpg', 200, 200),
    ]);
    $request->setUserResolver(fn () => $user);

    return app(AvatarController::class)->store($request);
}

it('sube la foto y la deja servible por su URL', function () {
    expect($this->user->avatarUrl())->toBeNull();

    $payload = json_decode(uploadAvatar($this->user)->getContent(), true);

    expect($payload['avatar_url'])->toContain('/avatar/'.$this->user->id)
        // ?v= : al resubir cambia el id del media y revienta el caché.
        ->and($payload['avatar_url'])->toContain('?v=');

    $response = app(AvatarController::class)->show(Request::create('/'), $this->user->fresh());
    expect($response->getStatusCode())->toBe(200);
});

it('solo guarda una foto: la nueva reemplaza a la anterior', function () {
    uploadAvatar($this->user);
    $primera = $this->user->fresh()->getFirstMedia('avatar')->id;

    uploadAvatar($this->user, UploadedFile::fake()->image('otra.png', 300, 300));
    $user = $this->user->fresh();

    expect($user->getMedia('avatar'))->toHaveCount(1)
        ->and($user->getFirstMedia('avatar')->id)->not->toBe($primera);
});

it('quitar la foto devuelve las iniciales y deja de servirla', function () {
    uploadAvatar($this->user);

    $request = Request::create('/avatar', 'DELETE');
    $request->setUserResolver(fn () => $this->user);
    app(AvatarController::class)->destroy($request);

    expect($this->user->fresh()->avatarUrl())->toBeNull();

    expect(fn () => app(AvatarController::class)->show(Request::create('/'), $this->user->fresh()))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('rechaza lo que no es imagen', function () {
    expect(fn () => uploadAvatar(
        $this->user,
        UploadedFile::fake()->create('nomina.pdf', 100, 'application/pdf'),
    ))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('subir siempre es sobre uno mismo, nunca sobre otra persona', function () {
    $otra = User::factory()->create();

    uploadAvatar($this->user);

    // La subida usa al usuario en sesión: la otra cuenta queda intacta.
    expect($otra->fresh()->avatarUrl())->toBeNull()
        ->and($this->user->fresh()->avatarUrl())->not->toBeNull();
});
