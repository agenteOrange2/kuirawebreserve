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
    $tenant->id = 'hotel-basic';
    $tenant->plan = 'basic'; // basic no incluye cupones
    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);

    $request = Request::create('http://hotel.test/api/booking/coupons/check', 'POST');

    expect(fn () => app(EnsureModuleEnabled::class)->handle($request, fn () => response('ok'), 'cupones'))
        ->toThrow(HttpException::class);

    Coupon::create(['code' => 'PRO10', 'kind' => 'percent', 'value' => 10]);

    $hold = couponHold(['coupon_code' => 'PRO10']);

    expect($hold->getStatusCode())->toBe(422)
        ->and($hold->getData(true)['message'])->toContain('no están disponibles');
});
