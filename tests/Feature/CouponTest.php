<?php

use App\Actions\Reservations\TransitionReservation;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\BookingCouponController;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\Coupon;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create(['property_id' => $this->property->id, 'room_type_id' => $this->roomType->id]);
    RatePlan::factory()->block(720, 1000)->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
    ]);
});

function couponHold(array $overrides = []): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/booking/holds', 'POST', array_replace([
        'mode' => 'block',
        'arrive_at' => now()->addHour()->toIso8601String(),
        'room_type_id' => test()->roomType->id,
        'room_id' => test()->room->id,
        'adults' => 2,
        'guest_name' => 'Con Cupón',
        'guest_phone' => '5599887766',
        'rendered_at' => now()->subSeconds(5)->toIso8601String(),
        'website' => '',
    ], $overrides));

    return app(BookingController::class)->holds($request, app(\App\Actions\Reservations\CreateReservation::class));
}

it('un cupón de porcentaje ajusta el total del hold y congela los campos', function () {
    Coupon::create(['code' => 'VERANO10', 'kind' => 'percent', 'value' => 10]);

    $response = couponHold(['coupon_code' => 'verano10']); // case-insensitive
    $payload = $response->getData(true);

    expect($response->getStatusCode())->toBe(201)
        ->and($payload['discount'])->toEqual(100.0)
        ->and($payload['coupon_code'])->toBe('VERANO10')
        ->and($payload['total'])->toEqual(900.0);

    $reservation = Reservation::firstOrFail();

    expect((float) $reservation->total_amount)->toEqual(900.0)
        ->and($reservation->coupon_code)->toBe('VERANO10')
        ->and((float) $reservation->discount_amount)->toEqual(100.0)
        // El hold NO cuenta el uso: eso pasa al confirmarse.
        ->and(Coupon::firstOrFail()->used_count)->toBe(0);
});

it('un cupón de monto fijo descuenta y nunca deja el total bajo 0', function () {
    Coupon::create(['code' => 'MEGA', 'kind' => 'amount', 'value' => 5000]);

    $payload = couponHold(['coupon_code' => 'MEGA'])->getData(true);

    // El descuento se recorta al subtotal: total 0, jamás negativo.
    expect($payload['discount'])->toEqual(1000.0)
        ->and($payload['total'])->toEqual(0.0);
});

it('código inválido, vencido o agotado se rechaza con mensaje claro', function () {
    $invalid = couponHold(['coupon_code' => 'NOEXISTE']);
    expect($invalid->getStatusCode())->toBe(422)
        ->and($invalid->getData(true)['message'])->toContain('no es válido');

    Coupon::create(['code' => 'VENCIDO', 'kind' => 'percent', 'value' => 10, 'ends_at' => now()->subDay()]);
    expect(couponHold(['coupon_code' => 'VENCIDO'])->getStatusCode())->toBe(422);

    Coupon::create(['code' => 'AGOTADO', 'kind' => 'percent', 'value' => 10, 'max_uses' => 1, 'used_count' => 1]);
    expect(couponHold(['coupon_code' => 'AGOTADO'])->getStatusCode())->toBe(422);

    // Nada de eso creó reservas: mejor avisar que cobrar el total callado.
    expect(Reservation::query()->count())->toBe(0);
});

it('used_count incrementa al confirmar una sola vez (confirm + check-in no duplica)', function () {
    Coupon::create(['code' => 'UNAVEZ', 'kind' => 'percent', 'value' => 10]);

    couponHold(['coupon_code' => 'UNAVEZ']);
    $reservation = Reservation::firstOrFail();

    expect($reservation->status)->toBe(ReservationStatus::Pending)
        ->and(Coupon::firstOrFail()->used_count)->toBe(0);

    app(TransitionReservation::class)->confirm($reservation);
    expect(Coupon::firstOrFail()->used_count)->toBe(1);

    // El check-in posterior (Confirmada → En casa) NO vuelve a contar.
    app(TransitionReservation::class)->checkIn($reservation->refresh());
    expect(Coupon::firstOrFail()->used_count)->toBe(1);
});

it('el check-in directo desde pendiente también cuenta el uso una sola vez', function () {
    Coupon::create(['code' => 'DIRECTO', 'kind' => 'amount', 'value' => 50]);

    couponHold(['coupon_code' => 'DIRECTO']);
    $reservation = Reservation::firstOrFail();

    app(TransitionReservation::class)->checkIn($reservation);

    expect(Coupon::firstOrFail()->used_count)->toBe(1);
});

it('la validación pública devuelve el descuento para el subtotal', function () {
    Coupon::create(['code' => 'CHECA', 'kind' => 'percent', 'value' => 25]);

    $ok = app(BookingCouponController::class)->check(
        Request::create('/api/booking/coupons/check', 'POST', ['code' => 'checa', 'subtotal' => 800]),
    );

    expect($ok->getStatusCode())->toBe(200)
        ->and($ok->getData(true)['discount'])->toEqual(200.0);

    $bad = app(BookingCouponController::class)->check(
        Request::create('/api/booking/coupons/check', 'POST', ['code' => 'NADA', 'subtotal' => 800]),
    );

    expect($bad->getStatusCode())->toBe(422);
});

it('sin el módulo, el endpoint público responde 403 y el hold rechaza cupones', function () {
    $tenant = new Tenant;
    $tenant->id = 'hotel-esencial';
    $tenant->plan = 'esencial'; // el plan Esencial no incluye cupones
    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);

    $request = Request::create('http://hotel.test/api/booking/coupons/check', 'POST');

    expect(fn () => app(EnsureModuleEnabled::class)->handle($request, fn () => response('ok'), 'cupones'))
        ->toThrow(HttpException::class);

    Coupon::create(['code' => 'PRO10', 'kind' => 'percent', 'value' => 10]);

    $hold = couponHold(['coupon_code' => 'PRO10']);

    expect($hold->getStatusCode())->toBe(422)
        ->and($hold->getData(true)['message'])->toContain('no están disponibles');
});

it('las condiciones del cupón se validan contra la reserva y el huésped', function () {
    // Noches/periodos mínimos: el hold de un periodo no alcanza.
    Coupon::create(['code' => 'LARGA', 'kind' => 'percent', 'value' => 15, 'min_nights' => 2]);

    $short = couponHold(['coupon_code' => 'LARGA']);
    expect($short->getStatusCode())->toBe(422)
        ->and($short->getData(true)['message'])->toContain('al menos 2 noches');

    // Tipo de habitación: cupón amarrado a OTRO tipo.
    $otherType = RoomType::factory()->create(['property_id' => $this->property->id]);
    Coupon::create(['code' => 'SUITE', 'kind' => 'percent', 'value' => 10, 'room_type_id' => $otherType->id]);

    $wrongType = couponHold(['coupon_code' => 'SUITE']);
    expect($wrongType->getStatusCode())->toBe(422)
        ->and($wrongType->getData(true)['message'])->toContain('aplica solo para');

    // Cliente frecuente: huésped nuevo no alcanza las visitas.
    Coupon::create(['code' => 'FRECUENTE', 'kind' => 'amount', 'value' => 100, 'min_visits' => 3]);

    $newGuest = couponHold(['coupon_code' => 'FRECUENTE']);
    expect($newGuest->getStatusCode())->toBe(422)
        ->and($newGuest->getData(true)['message'])->toContain('clientes frecuentes');
});

it('el cupón de cumpleaños exige fecha registrada y cercana', function () {
    Coupon::create(['code' => 'CUMPLE', 'kind' => 'percent', 'value' => 20, 'birthday' => true]);

    // Huésped sin fecha de nacimiento: rechazado con motivo claro.
    $without = couponHold(['coupon_code' => 'CUMPLE']);
    expect($without->getStatusCode())->toBe(422)
        ->and($without->getData(true)['message'])->toContain('fecha de nacimiento');

    // Huésped registrado cuyo cumpleaños cae cerca de la llegada: aplica.
    \App\Models\Guest::create([
        'first_name' => 'Festejada',
        'phone' => '5299887766554',
        'birth_date' => now()->addHour()->subYears(30)->addDays(3),
    ]);

    $near = couponHold([
        'coupon_code' => 'CUMPLE',
        'guest_name' => 'Festejada',
        'guest_phone' => '5299887766554',
    ]);

    expect($near->getStatusCode())->toBe(201)
        ->and((float) $near->getData(true)['discount'])->toEqual(200.0);
});

it('recepción aplica cupón en una reserva manual con la misma validación', function () {
    Coupon::create(['code' => 'MOSTRADOR', 'kind' => 'percent', 'value' => 10]);

    Spatie\Permission\Models\Permission::findOrCreate('reservations.manage', 'web');
    $user = \App\Models\User::factory()->create();

    $request = Request::create('/api/reservations', 'POST', [
        'rate_plan_id' => RatePlan::firstOrFail()->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDay()->setTime(15, 0)->toDateTimeString(),
        'guest_name' => 'Cliente Mostrador',
        'confirmed' => true,
        'coupon_code' => 'MOSTRADOR',
    ]);
    $request->setUserResolver(fn () => $user);

    $response = app(\App\Http\Controllers\Tenant\ReservationController::class)->store(
        $request,
        app(\App\Actions\Reservations\CreateReservation::class),
    );

    $reservation = Reservation::firstOrFail();

    expect($response->getStatusCode())->toBe(201)
        ->and($reservation->coupon_code)->toBe('MOSTRADOR')
        ->and((float) $reservation->discount_amount)->toEqual(100.0)
        ->and((float) $reservation->total_amount)->toEqual(900.0);

    // Un cupón que no cumple condiciones responde 422 con el motivo.
    Coupon::create(['code' => 'LARGA2', 'kind' => 'percent', 'value' => 15, 'min_nights' => 5]);

    $bad = Request::create('/api/reservations', 'POST', [
        'rate_plan_id' => RatePlan::firstOrFail()->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0)->toDateTimeString(),
        'guest_name' => 'Otro Cliente',
        'confirmed' => true,
        'coupon_code' => 'LARGA2',
    ]);
    $bad->setUserResolver(fn () => $user);

    $rejected = app(\App\Http\Controllers\Tenant\ReservationController::class)->store(
        $bad,
        app(\App\Actions\Reservations\CreateReservation::class),
    );

    expect($rejected->getStatusCode())->toBe(422)
        ->and($rejected->getData(true)['message'])->toContain('al menos 5 noches');
});
