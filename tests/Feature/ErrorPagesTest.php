<?php

use App\Support\ErrorPage;
use App\Support\ErrorReference;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/**
 * Las pantallas de error con el theme.
 *
 * Antes, cualquier dirección mal escrita o cualquier falla del servidor
 * terminaba en la página gris de Laravel, en inglés. Estas pruebas cuidan
 * las tres cosas que la hacen útil: que el usuario vea una pantalla del
 * producto, que la API NO la vea (necesita JSON), y que el folio que se le
 * enseña sea el mismo que queda escrito en el log.
 */
beforeEach(function () {
    config()->set('app.debug', false);
    ErrorReference::forget();
});

it('una dirección inexistente abre la pantalla del producto, no la de Laravel', function () {
    $this->get('/direccion-que-no-existe')
        ->assertStatus(404)
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 404)
            ->where('title', 'Aquí no hay nada')
            ->where('tone', 'primary')
        );
});

it('la dirección inexistente es una ruta de verdad, para que el 404 caiga dentro del panel', function () {
    // Si el fallback no existiera, Laravel rechazaría la URL durante el
    // ruteo: sin sesión ni datos compartidos, y quien está trabajando en el
    // panel perdería el menú justo cuando más lo necesita.
    $fallbacks = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => $r->isFallback);

    expect($fallbacks)->not->toBeEmpty()
        ->and($fallbacks->every(fn ($r) => str_contains($r->getActionName(), 'NotFoundController')))
        ->toBeTrue();
});

it('lo que pide JSON sigue recibiendo JSON', function () {
    $this->getJson('/direccion-que-no-existe')
        ->assertStatus(404)
        ->assertHeader('content-type', 'application/json');
});

it('el folio que se le enseña al usuario es el que queda en el log', function () {
    $folio = ErrorReference::current();

    // El mismo valor durante toda la petición: dos excepciones seguidas son
    // el mismo incidente, no dos folios que soporte tendría que cruzar.
    expect(ErrorReference::current())->toBe($folio)
        ->and($folio)->toMatch('/^[A-Z0-9]{6}$/');

    $payload = ErrorPage::payload(request(), 500);
    expect($payload['folio'])->toBe($folio);

    // Y en un 404 no hay folio: no hay incidente que buscar en el log. En
    // mantenimiento tampoco: el 503 no registra ninguna excepción.
    expect(ErrorPage::payload(request(), 404)['folio'])->toBeNull()
        ->and(ErrorPage::payload(request(), 503)['folio'])->toBeNull();
});

it('el folio viaja al log como contexto de la excepción', function () {
    Route::middleware('web')->get('/prueba-500', function () {
        throw new RuntimeException('falla de prueba');
    });

    $registrado = null;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$registrado) {
        if ($e->message === 'falla de prueba') {
            $registrado = $e->context['folio'] ?? null;
        }
    });

    $this->get('/prueba-500')->assertStatus(500);

    // Sin esto, alguien reporta "me salió un error" y soporte tiene que
    // adivinar cuál de los cien del día era.
    expect($registrado)->toBe(ErrorReference::current());
});

it('la sesión caducada a mitad de un formulario devuelve al usuario a donde estaba', function () {
    Route::middleware('web')->post('/prueba-419', fn () => abort(419));

    $this->from('/algun-formulario')
        ->post('/prueba-419', ['nombre' => 'Ana'])
        ->assertRedirect('/algun-formulario')
        ->assertSessionHas('error');
});

it('cada error tiene su texto y su icono, sin huecos', function () {
    $statuses = array_keys(config('error-pages.statuses'));

    expect($statuses)->toContain(403, 404, 419, 429, 500, 503);

    foreach ($statuses as $status) {
        $p = ErrorPage::payload(request(), $status);

        expect($p['title'])->not->toBeEmpty("el {$status} no tiene título")
            ->and($p['body'])->not->toBeEmpty("el {$status} no tiene explicación")
            ->and($p['badge'])->not->toBeEmpty("el {$status} no tiene rótulo")
            ->and($p['tone'])->toBeIn(['primary', 'info', 'success', 'warning', 'pending', 'danger', 'dark']);
    }
});

it('los iconos existen de verdad en Lucide', function () {
    $declaraciones = file_get_contents(base_path('node_modules/lucide-vue-next/dist/lucide-vue-next.d.ts'));

    $iconos = collect(config('error-pages.statuses'))
        ->pluck('icon')
        ->push(config('error-pages.default.icon'))
        ->unique();

    foreach ($iconos as $icono) {
        // toContain toma cada argumento como otra aguja: el motivo va fuera.
        expect(str_contains($declaraciones, "@name {$icono}\n"))
            ->toBeTrue("el icono {$icono} no existe en Lucide");
    }
})->skip(fn () => ! file_exists(base_path('node_modules/lucide-vue-next/dist/lucide-vue-next.d.ts')), 'sin node_modules');

it('queda un respaldo sin JavaScript para cuando ni Inertia se puede armar', function () {
    // Dominio de hotel desconocido, base caída o mantenimiento: ahí no hay
    // manifiesto de Vite ni sesión, y esta vista es lo único que queda.
    foreach ([403, 404, 419, 429, 500, 503] as $status) {
        expect(file_exists(resource_path("views/errors/{$status}.blade.php")))
            ->toBeTrue("falta el respaldo Blade del {$status}");
    }

    $html = view('errors.layout', ['status' => 503])->render();

    expect($html)->toContain('Volvemos en unos minutos')
        ->and($html)->not->toContain('@vite')
        ->and($html)->toContain('lang="es"');
});

it('el respaldo sin JavaScript no se cae aunque todo lo demás esté roto', function () {
    $payload = ErrorPage::safePayload(500);

    expect($payload['status'])->toBe(500)
        ->and($payload['title'])->not->toBeEmpty()
        ->and($payload['home']['url'])->not->toBeEmpty();
});

it('al huésped no le da consejos que solo sirven dentro del panel', function () {
    // "Usa el buscador del panel" es buen consejo para recepción y un
    // despropósito para quien estaba reservando desde el sitio del hotel.
    $visitante = ErrorPage::payload(request(), 404);

    expect(collect($visitante['hints'])->contains(fn ($h) => str_contains($h, 'buscador del panel')))->toBeFalse()
        ->and($visitante['home']['label'])->not->toContain('panel');

    $empleado = \Illuminate\Http\Request::create('/x');
    $empleado->setUserResolver(fn () => new \App\Models\User(['name' => 'Recepción']));
    $conSesion = ErrorPage::payload($empleado, 404);

    expect(collect($conSesion['hints'])->contains(fn ($h) => str_contains($h, 'buscador del panel')))->toBeTrue()
        ->and($conSesion['home']['label'])->toContain('panel');
});
