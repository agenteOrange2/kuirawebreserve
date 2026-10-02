<?php

use App\Http\Controllers\Tenant\MailPreviewController;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;

// /ajustes/mails → "Así se ven tus correos": cada correo se arma con datos de
// ejemplo, sin tocar reservas reales.

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Property::factory()->create([
        'name' => 'Cabañas Real de la Sierra',
        'address' => 'Los Ojitos, Juárez',
        'settings' => ['phone' => '+526561112233', 'wizard_accent' => '#978667'],
    ]);
});

test('cada correo se puede ver con la marca del hotel', function (string $type, string $text) {
    $user = User::factory()->create(['name' => 'Karla']);
    $request = Request::create("/ajustes/mails/vista-previa/{$type}");
    $request->setUserResolver(fn () => $user);

    $response = app(MailPreviewController::class)->show($request, $type);
    $html = $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('text/html')
        ->and($html)->toContain('Cabañas Real de la Sierra')
        ->and($html)->toContain($text);
})->with([
    ['reservation', 'RES-EJEMPLO'],
    ['notice', 'EXP-0001'],
    ['staff', 'Abrir en el panel'],
    ['reset', 'Crea tu contraseña nueva'],
    ['changed', 'Tu contraseña cambió'],
]);

test('el correo al huésped lleva el contacto del hotel y no a la plataforma', function () {
    $user = User::factory()->create();
    $request = Request::create('/ajustes/mails/vista-previa/reservation');
    $request->setUserResolver(fn () => $user);

    $html = app(MailPreviewController::class)->show($request, 'reservation')->getContent();

    expect($html)->toContain('Los Ojitos, Juárez')
        ->and($html)->toContain('tel:+526561112233')
        ->and($html)->toContain('#978667')
        ->and($html)->not->toContain('Con la tecnología de');
});

test('un tipo desconocido es 404', function () {
    $request = Request::create('/ajustes/mails/vista-previa/otro');
    app(MailPreviewController::class)->show($request, 'otro');
})->throws(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
