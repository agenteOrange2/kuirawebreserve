<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\TransitionReservation;
use App\Events\RoomStatusChanged;
use App\Http\Controllers\Tenant\WaitlistPublicController;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Channels\DirectGuestMessenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);
    Event::fake([RoomStatusChanged::class]);

    $this->property = Property::factory()->create();
    $this->roomType = RoomType::factory()->create(['property_id' => $this->property->id, 'capacity' => 2]);
    $this->room = Room::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'number' => '601',
    ]);
    $this->plan = RatePlan::factory()->create([
        'property_id' => $this->property->id,
        'room_type_id' => $this->roomType->id,
        'price' => 900,
    ]);
});

/** Tenant en memoria como contexto de tenancy (sin DB propia). */
function actAsTenant(string $plan): Tenant
{
    $tenant = new Tenant;
    $tenant->id = "hotel-{$plan}";
    $tenant->plan = $plan;

    app()->instance(\Stancl\Tenancy\Contracts\Tenant::class, $tenant);

    return $tenant;
}

function waitlistStore(array $payload): \Illuminate\Http\JsonResponse
{
    $request = Request::create('/api/booking/waitlist', 'POST', $payload);

    return app(WaitlistPublicController::class)->store($request);
}

it('la captura pública crea la entrada en espera', function () {
    $response = waitlistStore([
        'guest_name' => 'Espera Turno',
        'guest_phone' => '5511223344',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
    ]);

    expect($response->getStatusCode())->toBe(201);

    $entry = WaitlistEntry::firstOrFail();

    expect($entry->status)->toBe(WaitlistEntry::STATUS_WAITING)
        ->and($entry->guest_name)->toBe('Espera Turno')
        ->and($entry->room_type_id)->toBeNull();
});

it('sin teléfono ni correo la captura se rechaza', function () {
    expect(fn () => waitlistStore([
        'guest_name' => 'Sin Contacto',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
    ]))->toThrow(ValidationException::class);
});

it('el mismo contacto con el mismo rango no duplica su entrada', function () {
    $payload = [
        'guest_name' => 'Doble Clic',
        'guest_phone' => '5511223344',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
    ];

    waitlistStore($payload);
    $again = waitlistStore($payload);

    expect($again->getStatusCode())->toBe(200)
        ->and(WaitlistEntry::query()->count())->toBe(1);
});

it('sin el módulo, el endpoint público responde 403 (middleware)', function () {
    actAsTenant('basic'); // basic no incluye lista-espera

    $request = Request::create('http://hotel.test/api/booking/waitlist', 'POST');

    expect(fn () => app(EnsureModuleEnabled::class)->handle($request, fn () => response('ok'), 'lista-espera'))
        ->toThrow(HttpException::class);
});

it('cancelar una reserva avisa a las entradas solapadas y las sella', function () {
    actAsTenant('pro'); // pro incluye lista-espera (semilla config/plans.php)

    // Solapada del mismo tipo, solapada sin tipo (cualquiera) → se avisan.
    $sameType = WaitlistEntry::create([
        'room_type_id' => $this->roomType->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Mismo Tipo',
        'guest_phone' => '5511111111',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    $anyType = WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(4)->toDateString(),
        'ends_at' => now()->addDays(6)->toDateString(),
        'guest_name' => 'Cualquier Tipo',
        'guest_email' => 'cualquiera@correo.test',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    // Fuera de rango y ya avisada → NO se tocan.
    $outOfRange = WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(20)->toDateString(),
        'ends_at' => now()->addDays(22)->toDateString(),
        'guest_name' => 'Otro Mes',
        'guest_phone' => '5522222222',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);
    $alreadyNotified = WaitlistEntry::create([
        'room_type_id' => $this->roomType->id,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Ya Avisado',
        'guest_phone' => '5533333333',
        'status' => WaitlistEntry::STATUS_NOTIFIED,
        'notified_at' => now()->subDay(),
    ]);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldReceive('sendToContact')
            ->twice()
            ->withArgs(fn ($phone, $email, $subject, $body) => $subject === 'Se liberó espacio para tus fechas'
                && str_contains($body, 'se liberó espacio para tus fechas'));
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'El Que Cancela',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    expect($sameType->refresh()->status)->toBe(WaitlistEntry::STATUS_NOTIFIED)
        ->and($sameType->notified_at)->not->toBeNull()
        ->and($anyType->refresh()->status)->toBe(WaitlistEntry::STATUS_NOTIFIED)
        ->and($outOfRange->refresh()->status)->toBe(WaitlistEntry::STATUS_WAITING)
        ->and($alreadyNotified->refresh()->notified_at->isYesterday())->toBeTrue();
});

it('sin el módulo la cancelación no avisa a nadie', function () {
    actAsTenant('basic');

    WaitlistEntry::create([
        'room_type_id' => null,
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'guest_name' => 'Nadie Le Avisa',
        'guest_phone' => '5544444444',
        'status' => WaitlistEntry::STATUS_WAITING,
    ]);

    $this->mock(DirectGuestMessenger::class, function ($mock) {
        $mock->shouldIgnoreMissing();
        $mock->shouldNotReceive('sendToContact');
    });

    $reservation = app(CreateReservation::class)->handle([
        'rate_plan_id' => $this->plan->id,
        'room_id' => $this->room->id,
        'starts_at' => now()->addDays(3)->setTime(15, 0),
        'ends_at' => now()->addDays(6)->setTime(12, 0),
        'confirmed' => true,
        'guest_name' => 'Cancela Basic',
    ]);

    app(TransitionReservation::class)->cancel($reservation);

    expect(WaitlistEntry::firstOrFail()->status)->toBe(WaitlistEntry::STATUS_WAITING);
});
