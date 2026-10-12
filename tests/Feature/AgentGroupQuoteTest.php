<?php

use App\Actions\Reservations\CreateGroupReservation;
use App\Actions\Reservations\CreateReservation;
use App\Http\Controllers\Agent\AgentToolsController;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use Illuminate\Http\Request;

/**
 * Caso real cabañas 2026-10-11 (conv. 1861, Irving, 15 personas en
 * diciembre). La combinación "con persona extra" llenaba cada cabaña a tope
 * y cobraba extras que el grupo no necesitaba:
 *
 * - 15 personas: Real (+2) + Escondida (+1) + Sencilla = $11,250. En esas
 *   tres caben 14 incluidas y sobraba UNA extra ($10,750), y tres cabañas de
 *   $3,000 a 5 personas cada una salen en $9,750.
 * - 13 personas el 31-dic (Real y Escondida ocupadas): tres Sencillas con 2
 *   extras = $9,500, cuando sobra una: $9,250.
 *
 * Y el apartado, que sí reparte bien, habría cobrado otra cifra.
 */
beforeEach(function () {
    $this->artisan('migrate', ['--path' => 'database/migrations/tenant']);

    $this->property = Property::factory()->create(['name' => 'Cabañas Real de la Sierra']);

    $this->tipos = collect([
        'Cabaña Real' => [4500, 6, 8],
        'Cabaña Escondida' => [3000, 4, 5],
        'Cabaña Luxury' => [3500, 4, 5],
        'Cabaña Prisma' => [3500, 4, 5],
        'Cabaña Sencilla 1' => [3000, 4, 5],
        'Cabaña Sencilla 2' => [3000, 4, 5],
        'Cabaña Sencilla 3' => [3000, 4, 5],
        'Cabaña Sencilla 4' => [3000, 4, 5],
    ])->map(function (array $spec, string $nombre) {
        [$precio, $incluidas, $maximo] = $spec;
        static $orden = 0;

        $type = RoomType::factory()->create([
            'property_id' => $this->property->id,
            'name' => $nombre,
            'capacity' => $incluidas,
            'sort_order' => $orden++,
        ]);

        Room::factory()->create([
            'property_id' => $this->property->id,
            'room_type_id' => $type->id,
            'included_occupancy' => $incluidas,
            'max_occupancy' => $maximo,
            'extra_guest_fee' => 250,
        ]);

        $plan = RatePlan::factory()->create([
            'property_id' => $this->property->id,
            'room_type_id' => $type->id,
            'price' => $precio,
            'deposit_percent' => 50,
        ]);

        return ['type' => $type, 'plan' => $plan];
    });

    $this->panorama = function (int $personas, \Carbon\CarbonInterface $date): array {
        return app(AgentToolsController::class)->availabilityOverview(
            Request::create('/agent/availability-overview', 'GET', [
                'starts_at' => $date->format('Y-m-d').' 14:00',
                'ends_at' => $date->copy()->addDay()->format('Y-m-d').' 11:00',
                'guests' => $personas,
            ]),
            app(AvailabilityService::class),
        )->getData(true);
    };
});

function ocuparCabana(string $tipo, \Carbon\CarbonInterface $date): void
{
    app(CreateReservation::class)->handle([
        'rate_plan_id' => test()->tipos[$tipo]['plan']->id,
        'starts_at' => $date->copy()->setTime(14, 0),
        'ends_at' => $date->copy()->addDay()->setTime(11, 0),
        'confirmed' => true,
        'source_channel' => 'front_desk',
        'guest_name' => 'Ya reservada',
    ]);
}

it('a 15 personas da la combinación más barata de tres cabañas, no la de llenar a tope', function () {
    $data = ($this->panorama)(15, now()->addDays(20));

    // Tres cabañas de $3,000 con 5 personas cada una: 12 incluidas + 3
    // extras = $9,750 (el bot dijo $11,250).
    expect($data['combination_with_extras_rooms'])->toBe(3)
        ->and($data['combination_with_extras_extra_guests'])->toBe(3)
        ->and($data['combination_with_extras_total'])->toEqual(9750)
        ->and($data['combination_with_extras_max_guests'])->toBe(15)
        ->and(collect($data['combination_with_extras'])->pluck('room_type')->all())->not->toContain('Cabaña Real')
        // Sin extras: cuatro de $3,000 = 16 lugares por $12,000 (el bot dijo Real + tres = $13,500).
        ->and($data['combination_total'])->toEqual(12000)
        ->and($data['quote_options'][0])->toContain('$12,000.00 para 15 personas')
        ->and($data['quote_options'][1])->toContain('$9,750.00 para 15 personas')
        ->and($data['quote_options'][1])->toContain('12 incluidas + 3 personas extra');
});

it('a 13 personas caben en dos cabañas con persona extra', function () {
    $data = ($this->panorama)(13, now()->addDays(20));

    // Real a 8 (+2) y una de 5 (+1): $8,250. Sin extras, tres por $10,500.
    expect($data['combination_with_extras_rooms'])->toBe(2)
        ->and($data['combination_with_extras_total'])->toEqual(8250)
        ->and($data['combination_total'])->toEqual(10500);
});

it('el 31 de diciembre sin Real ni Escondida cotiza la extra justa', function () {
    $date = now()->addDays(20);
    ocuparCabana('Cabaña Real', $date);
    ocuparCabana('Cabaña Escondida', $date);

    $trece = ($this->panorama)(13, $date);
    $quince = ($this->panorama)(15, $date);

    expect($trece['combination_with_extras_total'])->toEqual(9250)
        ->and($trece['combination_with_extras_extra_guests'])->toBe(1)
        ->and($quince['combination_with_extras_total'])->toEqual(9750)
        ->and($quince['combination_with_extras_extra_guests'])->toBe(3)
        ->and(collect($quince['combination_with_extras'])->pluck('room_type')->all())
        ->not->toContain('Cabaña Real', 'Cabaña Escondida');
});

it('lo cotizado es lo que cobra el apartado de grupo', function () {
    $date = now()->addDays(20);
    $data = ($this->panorama)(15, $date);

    $payload = json_decode(app(AgentToolsController::class)->storeGroupHold(
        Request::create('/agent/group-holds', 'POST', [
            'starts_at' => $date->copy()->setTime(14, 0)->toIso8601String(),
            'ends_at' => $date->copy()->addDay()->setTime(11, 0)->toIso8601String(),
            'guest_name' => 'Irving Jesús Mata de la Cruz',
            'guests' => 15,
            'lines' => collect($data['combination_with_extras'])
                ->map(fn (array $line) => ['room_type_id' => $line['room_type_id'], 'rooms' => $line['units']])
                ->all(),
        ]),
        app(CreateGroupReservation::class),
    )->getContent(), true);

    expect($payload['total'])->toEqual($data['combination_with_extras_total'])
        ->and(Reservation::sum('num_people'))->toEqual(15);
});

it('pide volver a consultar si cambia el grupo', function () {
    $data = ($this->panorama)(15, now()->addDays(20));

    expect($data['note'])->toContain('vuelve a llamar esta herramienta');
});
