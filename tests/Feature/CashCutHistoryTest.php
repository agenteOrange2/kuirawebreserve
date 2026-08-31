<?php

use App\Http\Controllers\Tenant\CashCutsPageController;
use App\Models\CashCut;
use App\Models\Property;
use App\Models\User;
use App\Services\CashCutService;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

/**
 * El historial de /cortes: paginado y filtrable. Si las cifras de la cabecera
 * no siguen al filtro, quien revisa la caja compara contra un total que no es
 * el de la lista que está viendo.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();

    Permission::findOrCreate('reservations.view', 'web');
    $this->ana = User::factory()->create(['name' => 'Ana']);
    $this->ana->givePermissionTo('reservations.view');
    $this->beto = User::factory()->create(['name' => 'Beto']);
});

function savedCut(User $who, string $scope, float $total, ?float $counted, float $difference = 0): CashCut
{
    return CashCut::create([
        'property_id' => test()->property->id,
        'user_id' => $who->id,
        'scope' => $scope,
        'opened_at' => now()->subHours(8),
        'closed_at' => now()->subHours(1),
        'grand_total' => $total,
        'cash_total' => $total,
        'card_total' => 0,
        'transfer_total' => 0,
        'expected_cash' => $total,
        'counted_cash' => $counted,
        'difference' => $difference,
        'created_by' => $who->id,
    ]);
}

function cutsProps(array $query = []): array
{
    $viewer = test()->ana;
    $request = Request::create('/cortes', 'GET', $query);
    // El paginador lee la página del request GLOBAL, así que hay que atarlo;
    // y el resolver de usuario va DESPUÉS, porque instance('request') dispara
    // el rebinding de auth y lo sobrescribe.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $viewer);

    $response = app(CashCutsPageController::class)($request, app(CashCutService::class));
    $prop = (new ReflectionObject($response))->getProperty('props');
    $prop->setAccessible(true);

    return $prop->getValue($response);
}

it('el historial pagina y trae from/to para el pie', function () {
    foreach (range(1, 18) as $i) {
        savedCut($this->ana, CashCut::SCOPE_ROOMS, 100 * $i, 100 * $i);
    }

    $page1 = cutsProps()['cuts'];
    $page2 = cutsProps(['h_page' => 2])['cuts'];

    expect($page1->total())->toBe(18)
        ->and($page1->count())->toBe(15)
        ->and($page1->firstItem())->toBe(1)
        ->and($page2->count())->toBe(3)
        ->and($page2->currentPage())->toBe(2);
});

it('los filtros del historial acotan la lista y las cifras', function () {
    savedCut($this->ana, CashCut::SCOPE_ROOMS, 500, 500);
    savedCut($this->ana, CashCut::SCOPE_POS, 300, 250, -50);
    savedCut($this->beto, CashCut::SCOPE_ROOMS, 200, null);

    $todos = cutsProps();
    $soloPos = cutsProps(['h_scope' => 'pos']);
    $soloAna = cutsProps(['h_user' => $this->ana->id]);
    $sinCuadrar = cutsProps(['h_state' => 'diff']);
    $sinArqueo = cutsProps(['h_state' => 'sin-arqueo']);

    expect($todos['cuts']->total())->toBe(3)
        ->and($todos['historyStats']['count'])->toBe(3)
        ->and($todos['historyStats']['total'])->toBe(1000.0)
        ->and($todos['historyStats']['off'])->toBe(1)
        ->and($todos['historyStats']['without_count'])->toBe(1)
        ->and($soloPos['cuts']->total())->toBe(1)
        // Las cifras siguen al filtro, no se quedan en el total global.
        ->and($soloPos['historyStats']['total'])->toBe(300.0)
        ->and($soloAna['cuts']->total())->toBe(2)
        ->and($sinCuadrar['cuts']->total())->toBe(1)
        ->and($sinArqueo['cuts']->total())->toBe(1)
        ->and($todos['historyFilters'])->toBe([
            'scope' => '',
            'user' => null,
            'state' => '',
        ]);
});

it('el renglon del historial trae lo que pinta la tabla', function () {
    savedCut($this->ana, CashCut::SCOPE_ROOMS, 500, 480, -20);

    $row = cutsProps()['cuts']->getCollection()->first();

    expect($row)->toHaveKeys([
        'id', 'user', 'scope', 'scope_label', 'closed_at',
        'grand_total', 'expected_cash', 'counted_cash', 'difference', 'by',
    ])->and($row['user'])->toBe('Ana')
        ->and($row['difference'])->toBe(-20.0);
});
