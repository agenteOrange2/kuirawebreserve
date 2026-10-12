<?php

use App\Actions\Reservations\ApplyReservationCoupon;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\CreateWalkInStay;
use App\Actions\Reservations\ExtendStay;
use App\Actions\Reservations\TransitionReservation;
use App\Actions\Reservations\UpdateReservation;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Events\RoomStatusChanged;
use App\Events\StaffNotified;
use App\Exceptions\CourtesyNeedsConfirmation;
use App\Models\Coupon;
use App\Models\Courtesy;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StaffNotification;
use App\Models\User;
use App\Services\Courtesies\CourtesyService;
use Database\Seeders\TenantRolesSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Cortesías (módulo cortesias, pedido de Hotel México 2026-10-09): el
 * propietario la da directo, el resto la solicita y solo un propietario la
 * aprueba. Baja el total como el cupón, nunca es un pago, y sobrevive a las
 * ediciones que rearman la cuenta desde cero.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    $this->seed(TenantRolesSeeder::class);
    Event::fake([StaffNotified::class, RoomStatusChanged::class]);
    Queue::fake();

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    $this->ratePlan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 500,
    ]);

    $this->owner = courtesyUser('owner');
    $this->owner2 = courtesyUser('owner');
    $this->manager = courtesyUser('manager');
    $this->service = app(CourtesyService::class);
});

function courtesyUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

function courtesyReservation(bool $confirmed = true, int $nights = 2): Reservation
{
    return app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->ratePlan->id,
        'room_id' => test()->room->id,
        'guest_name' => 'Cliente Importante',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(1 + $nights)->setTime(12, 0),
        'confirmed' => $confirmed,
    ]);
}

function courtesyData(array $overrides = []): array
{
    return array_replace([
        'kind' => 'full',
        'value' => null,
        'category' => 'cliente_importante',
        'reason' => 'Socio del dueño',
    ], $overrides);
}

it('el propietario da una cortesía gratis: total en cero, pagada y confirmada', function () {
    $reservation = courtesyReservation(confirmed: false);
    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and((float) $reservation->total_amount)->toEqual(1000.0);

    $courtesy = $this->service->request($reservation, courtesyData(), $this->owner);
    $reservation->refresh();

    expect($courtesy->status)->toBe(Courtesy::STATUS_APPROVED)
        ->and((float) $courtesy->amount)->toEqual(1000.0)
        ->and($courtesy->decided_by)->toBe($this->owner->id)
        ->and((float) $reservation->total_amount)->toEqual(0.0)
        ->and((float) $reservation->courtesy_amount)->toEqual(1000.0)
        ->and($reservation->payment_status)->toBe(PaymentStatus::Paid)
        ->and($reservation->payment_due_at)->toBeNull()
        // Apartada en $0: se confirma sola, el barrido no la cancela.
        ->and($reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->isPaymentOverdue())->toBeFalse();

    // Nunca es un pago: CashLedger no la ve.
    expect($reservation->payments()->count())->toBe(0);

    // El otro propietario se entera; quien la dio no.
    $notices = StaffNotification::query()->where('type', StaffNotification::TYPE_COURTESY)->get();
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->user_id)->toBe($this->owner2->id);
});

it('quien no es propietario solo la solicita: el total no cambia y avisa a cada propietario', function () {
    $reservation = courtesyReservation();

    $courtesy = $this->service->request($reservation, courtesyData(['kind' => 'percent', 'value' => 50]), $this->manager);

    expect($courtesy->status)->toBe(Courtesy::STATUS_PENDING)
        ->and((float) $courtesy->amount)->toEqual(500.0)
        ->and((float) $reservation->refresh()->total_amount)->toEqual(1000.0);

    // Un aviso por propietario, no uno solo deduplicado por sujeto.
    $notices = StaffNotification::query()->where('type', StaffNotification::TYPE_COURTESY)->pluck('user_id')->sort()->values()->all();
    expect($notices)->toBe(collect([$this->owner->id, $this->owner2->id])->sort()->values()->all());

    Queue::assertPushed(\App\Jobs\SendCourtesyNoticeMail::class);
});

it('el propietario aprueba con otro monto y avisa a quien la pidió', function () {
    $reservation = courtesyReservation();
    $courtesy = $this->service->request($reservation, courtesyData(), $this->manager);

    $this->service->approve($courtesy, $this->owner, ['kind' => 'amount', 'value' => 300]);

    expect($courtesy->refresh()->status)->toBe(Courtesy::STATUS_APPROVED)
        ->and($courtesy->kind)->toBe('amount')
        ->and((float) $courtesy->amount)->toEqual(300.0)
        ->and((float) $reservation->refresh()->total_amount)->toEqual(700.0);

    expect(StaffNotification::query()->where('user_id', $this->manager->id)->exists())->toBeTrue();
});

it('solo el propietario aprueba, rechaza o retira', function () {
    $courtesy = $this->service->request(courtesyReservation(), courtesyData(), $this->manager);

    expect(fn () => $this->service->approve($courtesy, $this->manager))
        ->toThrow(InvalidArgumentException::class, 'Solo el propietario');

    $this->service->reject($courtesy, $this->owner, 'No aplica');

    expect($courtesy->refresh()->status)->toBe(Courtesy::STATUS_REJECTED)
        ->and((float) Reservation::first()->total_amount)->toEqual(1000.0);
});

it('retirar una cortesía devuelve la cuenta a su precio', function () {
    $reservation = courtesyReservation();
    $courtesy = $this->service->request($reservation, courtesyData(['kind' => 'percent', 'value' => 20]), $this->owner);

    expect((float) $reservation->refresh()->total_amount)->toEqual(800.0);

    $this->service->revoke($courtesy, $this->owner);

    expect($courtesy->refresh()->status)->toBe(Courtesy::STATUS_REVOKED)
        ->and((float) $reservation->refresh()->total_amount)->toEqual(1000.0)
        ->and((float) $reservation->courtesy_amount)->toEqual(0.0);
});

it('pasar el tope del mes pide confirmar dos veces', function () {
    $this->property->update(['settings' => [...($this->property->settings ?? []), 'courtesy_monthly_cap' => 700]]);

    $reservation = courtesyReservation();

    expect(fn () => $this->service->request($reservation, courtesyData(), $this->owner))
        ->toThrow(CourtesyNeedsConfirmation::class, 'tope');

    // Nada quedó a medias.
    expect(Courtesy::count())->toBe(0)
        ->and((float) $reservation->refresh()->total_amount)->toEqual(1000.0);

    $courtesy = $this->service->request($reservation, courtesyData(), $this->owner, confirmOverCap: true);

    expect($courtesy->over_cap)->toBeTrue()
        ->and($this->service->monthUsage())->toEqual(1000.0);
});

it('editar la reserva conserva la cortesía y la vuelve a medir', function () {
    $reservation = courtesyReservation(nights: 2);
    $this->service->request($reservation, courtesyData(['kind' => 'percent', 'value' => 50]), $this->owner);

    expect((float) $reservation->refresh()->total_amount)->toEqual(500.0);

    app(UpdateReservation::class)->handle($reservation, [
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'starts_at' => $reservation->starts_at->toDateTimeString(),
        'ends_at' => $reservation->starts_at->copy()->addDays(3)->setTime(12, 0)->toDateTimeString(),
    ]);

    // Tres noches de 500 con 50%: la cortesía siguió al hospedaje nuevo.
    expect((float) $reservation->refresh()->total_amount)->toEqual(750.0)
        ->and((float) $reservation->courtesy_amount)->toEqual(750.0)
        ->and((float) Courtesy::first()->amount)->toEqual(750.0);
});

it('cupón y cortesía no se acumulan', function () {
    Coupon::create(['code' => 'DIEZ', 'kind' => 'percent', 'value' => 10]);
    $reservation = courtesyReservation();

    app(ApplyReservationCoupon::class)->handle($reservation, 'DIEZ');
    expect((float) $reservation->refresh()->total_amount)->toEqual(900.0);

    // La cortesía retira el cupón y se mide sobre el bruto.
    $this->service->request($reservation, courtesyData(['kind' => 'percent', 'value' => 50]), $this->owner);
    $reservation->refresh();

    expect($reservation->coupon_code)->toBeNull()
        ->and((float) $reservation->discount_amount)->toEqual(0.0)
        ->and((float) $reservation->total_amount)->toEqual(500.0);

    expect(fn () => app(ApplyReservationCoupon::class)->handle($reservation, 'DIEZ'))
        ->toThrow(InvalidArgumentException::class, 'cortesía');
});

it('solo hay una cortesía viva por cuenta', function () {
    $reservation = courtesyReservation();
    $this->service->request($reservation, courtesyData(), $this->manager);

    expect(fn () => $this->service->request($reservation, courtesyData(), $this->owner))
        ->toThrow(InvalidArgumentException::class, 'esperando al propietario');
});

it('cancelar la reserva cancela la solicitud pendiente', function () {
    $reservation = courtesyReservation();
    $courtesy = $this->service->request($reservation, courtesyData(), $this->manager);

    app(TransitionReservation::class)->cancel($reservation);

    expect($courtesy->refresh()->status)->toBe(Courtesy::STATUS_CANCELED);

    expect(fn () => $this->service->approve($courtesy, $this->owner))
        ->toThrow(InvalidArgumentException::class, 'ya se decidió');
});

it('el walk-in del propietario nace con la cortesía y no deja un pago de cero', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->ratePlan->id,
        'planned_end_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
        'payment_method' => 'cash',
        'courtesy' => courtesyData(),
    ], $this->owner);

    expect((float) $stay->amount)->toEqual(0.0)
        ->and((float) $stay->courtesy_amount)->toEqual(500.0)
        ->and($stay->payments()->count())->toBe(0)
        ->and(Courtesy::first()->stay_id)->toBe($stay->id);
});

it('extender un walk-in con cortesía de porcentaje la vuelve a medir', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->ratePlan->id,
        'planned_end_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
    ], $this->owner);

    $this->service->request($stay, courtesyData(['kind' => 'percent', 'value' => 50]), $this->owner);
    expect((float) $stay->refresh()->amount)->toEqual(250.0);

    app(ExtendStay::class)->handle($stay, $stay->planned_end_at->copy()->addDay(), $this->owner);

    expect((float) $stay->refresh()->amount)->toEqual(500.0)
        ->and((float) $stay->courtesy_amount)->toEqual(500.0);
});

it('la cortesía de una reserva con el huésped adentro baja también la estancia', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Huésped en casa',
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay()->setTime(12, 0),
        'confirmed' => true,
    ]);
    $stay = app(TransitionReservation::class)->checkIn($reservation->refresh(), $this->owner);

    $total = (float) $reservation->refresh()->total_amount;
    $this->service->request($reservation, courtesyData(['kind' => 'amount', 'value' => 100]), $this->owner);

    expect((float) $reservation->refresh()->total_amount)->toEqual($total - 100)
        ->and((float) $stay->refresh()->amount)->toEqual($total - 100);
});

it('la reserva nace con la cortesía del propietario, antes de cualquier aviso', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Amigo del dueño',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'confirmed' => true,
        'courtesy' => courtesyData(['kind' => 'percent', 'value' => 50]),
    ], $this->owner);

    expect((float) $reservation->total_amount)->toEqual(500.0)
        ->and((float) $reservation->courtesy_amount)->toEqual(500.0)
        ->and(Courtesy::first()->status)->toBe(Courtesy::STATUS_APPROVED);
});

it('la reserva del gerente nace con precio normal y la cortesía esperando', function () {
    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Cliente frecuente',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'confirmed' => true,
        'courtesy' => courtesyData(),
    ], $this->manager);

    expect((float) $reservation->total_amount)->toEqual(1000.0)
        ->and(Courtesy::first()->status)->toBe(Courtesy::STATUS_PENDING)
        ->and(Courtesy::first()->reservation_id)->toBe($reservation->id);
});

it('si la cortesía pasa el tope, la reserva no se crea hasta confirmarlo', function () {
    $this->property->update(['settings' => [...($this->property->settings ?? []), 'courtesy_monthly_cap' => 100]]);

    expect(fn () => app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->ratePlan->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Pasa el tope',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDays(3)->setTime(12, 0),
        'confirmed' => true,
        'courtesy' => courtesyData(),
    ], $this->owner))->toThrow(CourtesyNeedsConfirmation::class);

    expect(Reservation::count())->toBe(0)->and(Courtesy::count())->toBe(0);
});

function courtesyHotelWithModule(): void
{
    $tenant = Mockery::mock(\App\Models\Tenant::class)->makePartial();
    $tenant->shouldReceive('hasModule')->andReturnUsing(fn (string $key) => $key === 'cortesias');
    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);
}

function courtesyCheckedOutStay(): \App\Models\Stay
{
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => test()->room->id,
        'rate_plan_id' => test()->ratePlan->id,
        'planned_end_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
    ], test()->owner);

    return app(TransitionReservation::class)->checkOut($stay, test()->owner)->refresh();
}

it('la solicitud pendiente sobrevive a la salida y el propietario la decide en Cuentas por cerrar', function () {
    $stay = app(CreateWalkInStay::class)->handle([
        'room_id' => $this->room->id,
        'rate_plan_id' => $this->ratePlan->id,
        'planned_end_at' => now()->addDay()->setTime(12, 0)->toDateTimeString(),
    ], $this->manager);
    $courtesy = $this->service->request($stay, courtesyData(), $this->manager);

    // "Salir sin cobrar": la estancia se cierra con su saldo.
    app(TransitionReservation::class)->checkOut($stay, $this->manager);

    expect($courtesy->refresh()->status)->toBe(Courtesy::STATUS_PENDING)
        ->and($stay->refresh()->pendingSettlementAmount())->toEqual(500.0);

    $this->service->approve($courtesy, $this->owner);

    // Aprobada sobre la cuenta ya cerrada: el saldo desaparece sin fingir
    // un pago, y la cuenta sale sola de la bandeja.
    expect((float) $stay->refresh()->amount)->toEqual(0.0)
        ->and($stay->payments()->count())->toBe(0)
        ->and(\App\Models\Stay::query()->pendingSettlement()->whereKey($stay->id)->exists())->toBeFalse();
});

it('con cortesías, solo el propietario cierra una cuenta sin cobrar', function () {
    courtesyHotelWithModule();
    $stay = courtesyCheckedOutStay();
    $controller = app(\App\Http\Controllers\Tenant\StaySettlementController::class);

    $asManager = Illuminate\Http\Request::create('/x', 'PATCH', ['note' => 'Cortesía de la casa']);
    $asManager->setUserResolver(fn () => $this->manager);

    $denied = $controller->close($asManager, $stay);
    expect($denied->getStatusCode())->toBe(403)
        ->and($stay->refresh()->settlement_closed_at)->toBeNull();

    // Una solicitud que esperaba se cancela cuando el dueño cierra sin cobrar.
    $pending = $this->service->request($stay, courtesyData(), $this->manager);

    $asOwner = Illuminate\Http\Request::create('/x', 'PATCH', ['note' => 'Lo decidí yo']);
    $asOwner->setUserResolver(fn () => $this->owner);

    expect($controller->close($asOwner, $stay)->getStatusCode())->toBe(200)
        ->and($stay->refresh()->settlement_closed_at)->not->toBeNull()
        ->and($pending->refresh()->status)->toBe(Courtesy::STATUS_CANCELED);
});

it('sin el módulo, cerrar sin cobrar sigue como siempre', function () {
    $stay = courtesyCheckedOutStay();
    $request = Illuminate\Http\Request::create('/x', 'PATCH', ['note' => 'Incobrable']);
    $request->setUserResolver(fn () => $this->manager);

    expect(app(\App\Http\Controllers\Tenant\StaySettlementController::class)->close($request, $stay)->getStatusCode())->toBe(200);
});
