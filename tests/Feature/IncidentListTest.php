<?php

use App\Http\Controllers\Tenant\IncidentController;
use App\Http\Controllers\Tenant\IncidentsPageController;
use App\Models\Incident;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Higiene del listado: antes cortaba en 100 sin avisar, no se podía
 * buscar y el orden usaba field(), que es de MySQL.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
    $this->otra = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '205',
    ]);
    $this->user = User::factory()->create();
});

function listPage(array $query = []): array
{
    $request = tap(
        Request::create('/incidencias', 'GET', $query),
        fn (Request $r) => $r->setUserResolver(fn () => test()->user),
    );

    $response = app(IncidentsPageController::class)($request);

    $props = (new ReflectionObject($response))->getProperty('props');
    $props->setAccessible(true);

    return $props->getValue($response);
}

function anIncident(array $attributes = []): Incident
{
    return Incident::create([
        'room_id' => test()->room->id,
        'title' => 'Falla',
        'priority' => 'medium',
        'status' => Incident::STATUS_OPEN,
        ...$attributes,
    ]);
}

it('pagina en vez de cortar en silencio', function () {
    foreach (range(1, 25) as $i) {
        anIncident(['title' => "Falla {$i}"]);
    }

    $page = listPage();

    expect($page['incidents']->count())->toBe(20)
        ->and($page['incidents']->total())->toBe(25);
});

it('ordena pendientes y urgentes primero, con CASE portable', function () {
    anIncident(['title' => 'Resuelta vieja', 'status' => Incident::STATUS_RESOLVED, 'priority' => 'high']);
    anIncident(['title' => 'Baja abierta', 'priority' => 'low']);
    anIncident(['title' => 'Fuga', 'priority' => 'high']);

    $titles = collect(listPage(['status' => 'all'])['incidents']->items())
        ->pluck('title')
        ->all();

    expect($titles)->toBe(['Fuga', 'Baja abierta', 'Resuelta vieja']);
});

it('busca por texto de la falla y por número de habitación', function () {
    anIncident(['title' => 'Fuga en la regadera']);
    anIncident(['title' => 'Foco fundido', 'room_id' => $this->otra->id]);

    expect(collect(listPage(['q' => 'regadera'])['incidents']->items())->pluck('title')->all())
        ->toBe(['Fuga en la regadera'])
        ->and(collect(listPage(['q' => '205'])['incidents']->items())->pluck('title')->all())
        ->toBe(['Foco fundido']);
});

it('filtra las que nadie tomó y las que reportó un huésped', function () {
    anIncident(['title' => 'Sin dueño']);
    anIncident(['title' => 'Asignada', 'assigned_to' => $this->user->id]);
    anIncident(['title' => 'Del huésped', 'source' => Incident::SOURCE_GUEST]);

    expect(collect(listPage(['assignee' => 'none'])['incidents']->items())->pluck('title')->all())
        ->toContain('Sin dueño')
        ->not->toContain('Asignada')
        ->and(collect(listPage(['source' => 'guest'])['incidents']->items())->pluck('title')->all())
        ->toBe(['Del huésped']);
});

it('filtra solo las que se pasaron de su tiempo', function () {
    $tarde = anIncident(['title' => 'Fuga de hace dos semanas', 'priority' => 'high']);
    $tarde->forceFill(['due_at' => now()->subDays(14)])->saveQuietly();
    anIncident(['title' => 'Recién reportada']);

    $titles = collect(listPage(['overdue' => 1])['incidents']->items())->pluck('title')->all();

    expect($titles)->toBe(['Fuga de hace dos semanas']);
});

it('el filtro de tipo de falla sobrevive a la recarga', function () {
    anIncident(['category' => 'plomeria']);

    expect(listPage(['category' => 'plomeria'])['filters']['category'])->toBe('plomeria');
});

it('borra en bloque lo seleccionado', function () {
    $ids = [anIncident()->id, anIncident()->id];
    $queda = anIncident();

    $response = app(IncidentController::class)->destroyBulk(
        Request::create('/x', 'DELETE', ['ids' => $ids]),
    );

    expect($response->getData()->deleted)->toBe(2)
        ->and(Incident::count())->toBe(1)
        ->and(Incident::first()->id)->toBe($queda->id);
});
