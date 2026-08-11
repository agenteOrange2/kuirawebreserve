<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Http\Controllers\Tenant\ActivityLogPageController;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    // Capacidad fija: la factory sortea 1-4 y la reserva del test lleva 2.
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 1000,
    ]);

    Permission::findOrCreate('reports.view', 'web');
    $this->user = User::factory()->create();
    $this->user->givePermissionTo('reports.view');
});

function activityProps(array $query = []): array
{
    $request = Request::create('/actividad', 'GET', $query);
    $request->headers->set('X-Inertia', 'true');
    $request->setUserResolver(fn () => test()->user);

    return app(ActivityLogPageController::class)($request)
        ->toResponse($request)->getData(true)['props'];
}

it('los pagos dejan línea de bitácora con su autor', function () {
    $this->actingAs($this->user);

    Payment::create([
        'amount' => 750,
        'method' => 'cash',
        'received_by' => $this->user->id,
        'paid_at' => now(),
    ]);

    $entry = Activity::query()->where('log_name', 'payment')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->user->id)
        ->and((float) ($entry->properties['attributes']['amount'] ?? 0))->toBe(750.0);

    $rows = activityProps()['activities']['data'];

    expect(collect($rows)->pluck('message')->first())->toContain('Pago registrado: $750.00');
});

it('aplicar y canjear un cupón dejan rastro de quién y cuándo', function () {
    $this->actingAs($this->user);

    Coupon::create([
        'code' => 'PROMO10',
        'kind' => Coupon::KIND_PERCENT,
        'value' => 10,
        'active' => true,
    ]);

    $reservation = app(CreateReservation::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->plan->id,
        'guest_name' => 'Huésped Cupón',
        'adults' => 2,
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(2)->setTime(12, 0),
        'coupon_code' => 'PROMO10',
        'source_channel' => 'front_desk',
    ], $this->user);

    // El alta con cupón deja su línea propia.
    $applied = Activity::query()
        ->where('log_name', 'coupon')
        ->where('description', 'like', 'Cupón PROMO10 aplicado%')
        ->first();

    expect($applied)->not->toBeNull()
        ->and($applied->causer_id)->toBe($this->user->id)
        ->and($applied->subject_id)->toBe($reservation->id);

    // Confirmar canjea el cupón y también queda escrito.
    app(TransitionReservation::class)->confirm($reservation, $this->user);

    expect(Activity::query()
        ->where('log_name', 'coupon')
        ->where('description', 'like', 'Cupón PROMO10 canjeado%')
        ->exists())->toBeTrue()
        ->and(Coupon::firstWhere('code', 'PROMO10')->used_count)->toBe(1);
});

it('la bitácora global filtra por usuario, tipo y fecha', function () {
    $this->actingAs($this->user);

    $other = User::factory()->create();

    Payment::create([
        'amount' => 100,
        'method' => 'cash',
        'received_by' => $this->user->id,
        'paid_at' => now(),
    ]);

    // Actividad de otro tipo (habitación): el filtro debe separarlas.
    $this->room->update(['notes' => 'Cambio de prueba para la bitácora']);

    $all = activityProps();
    $onlyPayments = activityProps(['type' => 'payment']);
    $onlyOther = activityProps(['user' => (string) $other->id]);

    expect($all['activities']['total'])->toBeGreaterThanOrEqual(2)
        ->and(collect($onlyPayments['activities']['data'])->pluck('type')->unique()->all())->toBe(['payment'])
        ->and($onlyOther['activities']['total'])->toBe(0)
        // Fuera de rango de fechas: nada.
        ->and(activityProps(['from' => now()->addDay()->format('Y-m-d')])['activities']['total'])->toBe(0);
});
