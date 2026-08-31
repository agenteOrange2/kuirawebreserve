<?php

use App\Http\Controllers\Tenant\ShiftAssignmentController;
use App\Models\Housekeeper;
use App\Models\Property;
use App\Models\ShiftAssignment;
use App\Models\ShiftType;
use App\Models\Technician;
use App\Models\User;
use App\Services\ShiftRoster;
use Illuminate\Http\Request;

/**
 * El rol semanal cubre a quien trabaja por turno, tenga cuenta o no:
 * recepción entra al panel, las camaristas y los técnicos no, pero los
 * tres tienen horario. (Shift, la asistencia CON CAJA, sigue siendo solo
 * de usuarios: ahí hay fondo y corte.)
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->user = User::factory()->create(['name' => 'Luis Recepción']);

    $this->morning = ShiftType::create([
        'property_id' => $this->property->id,
        'name' => 'Matutino',
        'starts_at' => '07:00',
        'ends_at' => '15:00',
        'color' => '#0ea5e9',
        'active' => true,
    ]);
    $this->evening = ShiftType::create([
        'property_id' => $this->property->id,
        'name' => 'Vespertino',
        'starts_at' => '15:00',
        'ends_at' => '23:00',
        'color' => '#8b5cf6',
        'active' => true,
    ]);
});

function syncRoster(array $payload, User $actor): void
{
    $request = Request::create('/api/shift-assignments/sync', 'POST', $payload);
    $request->setUserResolver(fn () => $actor);

    app(ShiftAssignmentController::class)->sync($request);
}

it('programa a una camarista aunque no tenga cuenta en el sistema', function () {
    $rosa = Housekeeper::create(['name' => 'Rosa Elena Prieto', 'active' => true]);

    syncRoster([
        'kind' => 'housekeeper',
        'assignable_id' => $rosa->id,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->morning->id],
    ], $this->user);

    $assignment = ShiftAssignment::firstOrFail();

    expect($assignment->assignable_type)->toBe(Housekeeper::class)
        ->and($assignment->assignable_id)->toBe($rosa->id)
        ->and($assignment->assigneeName())->toBe('Rosa Elena Prieto')
        ->and($assignment->kind())->toBe('housekeeper')
        ->and($assignment->slot())->toBe("housekeeper:{$rosa->id}");
});

it('el rol del personal del panel sigue funcionando igual', function () {
    syncRoster([
        'kind' => 'user',
        'assignable_id' => $this->user->id,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->morning->id, $this->evening->id],
    ], $this->user);

    expect(ShiftAssignment::query()->count())->toBe(2)
        ->and(ShiftAssignment::first()->kind())->toBe('user');

    // Volver a sincronizar con un solo turno quita el otro.
    syncRoster([
        'kind' => 'user',
        'assignable_id' => $this->user->id,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->morning->id],
    ], $this->user);

    expect(ShiftAssignment::query()->count())->toBe(1)
        ->and(ShiftAssignment::first()->shift_type_id)->toBe($this->morning->id);
});

it('no se programa a alguien que no existe en su tabla', function () {
    $request = Request::create('/api/shift-assignments/sync', 'POST', [
        'kind' => 'technician',
        'assignable_id' => 9999,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->morning->id],
    ]);
    $request->setUserResolver(fn () => $this->user);

    $response = app(ShiftAssignmentController::class)->sync($request);

    expect($response->getStatusCode())->toBe(422)
        ->and(ShiftAssignment::query()->count())->toBe(0);
});

it('el rol del día dice quién está en turno en este momento', function () {
    $rosa = Housekeeper::create(['name' => 'Rosa Elena Prieto', 'active' => true]);
    $alma = Housekeeper::create(['name' => 'Alma Delia Ríos', 'active' => true]);

    // Rosa por la mañana, Alma por la tarde.
    syncRoster([
        'kind' => 'housekeeper',
        'assignable_id' => $rosa->id,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->morning->id],
    ], $this->user);
    syncRoster([
        'kind' => 'housekeeper',
        'assignable_id' => $alma->id,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->evening->id],
    ], $this->user);

    $roster = app(ShiftRoster::class);

    // A las 10 de la mañana manda Rosa; a las 6 de la tarde, Alma.
    $this->travelTo(today()->setTime(10, 0));
    expect($roster->onDutyNow('housekeeper'))->toBe([$rosa->id]);

    $this->travelTo(today()->setTime(18, 0));
    expect($roster->onDutyNow('housekeeper'))->toBe([$alma->id]);

    // Programadas hoy son las dos, esté o no corriendo su turno.
    expect($roster->today('housekeeper'))->toHaveCount(2);

    $this->travelBack();
});

it('el turno nocturno cruza la medianoche sin romperse', function () {
    $night = ShiftType::create([
        'property_id' => $this->property->id,
        'name' => 'Nocturno',
        'starts_at' => '23:00',
        'ends_at' => '07:00',
        'color' => '#64748b',
        'active' => true,
    ]);

    $this->travelTo(today()->setTime(2, 30));
    expect($night->covers())->toBeTrue()
        ->and($this->morning->covers())->toBeFalse();

    $this->travelTo(today()->setTime(23, 30));
    expect($night->covers())->toBeTrue();

    $this->travelTo(today()->setTime(12, 0));
    expect($night->covers())->toBeFalse();

    $this->travelBack();
});

it('los técnicos también entran al rol, que es lo que faltaba para incidencias', function () {
    $chuy = Technician::create(['name' => 'Chuy Barraza', 'external' => false, 'active' => true]);

    syncRoster([
        'kind' => 'technician',
        'assignable_id' => $chuy->id,
        'date' => today()->toDateString(),
        'shift_type_ids' => [$this->morning->id],
    ], $this->user);

    $this->travelTo(today()->setTime(9, 0));

    expect(app(ShiftRoster::class)->onDutyNow('technician'))->toBe([$chuy->id]);

    $this->travelBack();
});
