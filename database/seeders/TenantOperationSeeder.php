<?php

namespace Database\Seeders;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\RoomStatus;
use App\Models\CashCut;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Coupon;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSession;
use App\Models\Extra;
use App\Models\Guest;
use App\Models\Housekeeper;
use App\Models\Incident;
use App\Models\MenuRequest;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RatePlanSeason;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCleaning;
use App\Models\RoomStatusLog;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftType;
use App\Models\StaffNotification;
use App\Models\Stay;
use App\Models\StaySurvey;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\CashCutService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Operación ficticia completa para demos: staff, huéspedes, ~6 semanas de
 * reservas con pagos y encuestas, ventas POS, turnos con cortes, incidencias,
 * camaristas con su bitácora de limpiezas, cupones, experiencias, grupo,
 * lista de espera, menú digital y bandeja.
 *
 * Usa el catálogo de habitaciones y productos que ya exista. Cada sección
 * tiene su guard, así que re-correrlo no duplica datos (y reanuda lo que
 * falte si una corrida quedó a medias):
 *
 *   php artisan tenants:seed --class="Database\Seeders\TenantOperationSeeder" --tenants=hoteltest
 */
class TenantOperationSeeder extends Seeder
{
    private Property $property;

    private CarbonImmutable $today;

    /** @var array<string, User> */
    private array $staff = [];

    /** @var array<int, Guest> */
    private array $guests = [];

    /** @var array<int, Room[]> habitaciones agrupadas por room_type_id */
    private array $roomsByType = [];

    /** @var array<int, RatePlan> tarifa nocturna por room_type_id */
    private array $planByType = [];

    /** @var array<int, array<int, array{0: string, 1: string}>> intervalos ocupados por room_id (Y-m-d) */
    private array $booked = [];

    /** @var array<int, array{id: int, user_id: int, start: CarbonImmutable, end: ?CarbonImmutable}> */
    private array $shiftIndex = [];

    /** @var Product[] */
    private array $products = [];

    public function run(): void
    {
        // Cada sección trae su propio guard, así que el seeder puede
        // reanudarse si una corrida anterior quedó a medias.
        mt_srand(20260810);

        $this->today = now()->startOfDay();
        $this->property = Property::firstOrFail();
        $this->products = Product::query()->where('active', true)->get()->all();

        foreach (Room::query()->orderBy('number')->get() as $room) {
            $this->roomsByType[$room->room_type_id][] = $room;
        }
        foreach (RatePlan::query()->where('type', 'night')->get() as $plan) {
            $this->planByType[$plan->room_type_id] = $plan;
        }

        $this->seedStaff();
        $this->seedShiftsAndAssignments();
        $this->seedGuests();
        $this->seedCouponsAndSeasons();
        $this->seedExtras();
        $this->seedExperiences();
        $this->seedHistory();
        $this->seedCurrentDay();
        $this->seedFuture();
        $this->seedWaitlist();
        $this->seedIncidents();
        $this->seedMaintenanceCrew();
        $this->seedHousekeeping();
        $this->seedCrewRoster();
        $this->seedMenuRequests();
        $this->seedMessaging();
        $this->seedStock();
        $this->seedCashCuts();
        $this->seedNotifications();
        $this->backdateActivity();

        $this->command?->info('Operación ficticia sembrada.');
    }

    // ------------------------------------------------------------------
    // Staff y turnos
    // ------------------------------------------------------------------

    private function seedStaff(): void
    {
        $this->call(TenantRolesSeeder::class);

        $people = [
            'carolina' => ['Carolina Méndez', 'gerencia@hoteltest.mx', '+52 614 210 4477', 'manager'],
            'luis' => ['Luis Ortega', 'recepcion.am@hoteltest.mx', '+52 614 388 1290', 'front-desk'],
            'sofia' => ['Sofía Ramírez', 'recepcion.pm@hoteltest.mx', '+52 614 402 7713', 'front-desk'],
            'jorge' => ['Jorge Anaya', 'barra@hoteltest.mx', '+52 614 199 0524', 'kitchen'],
            'marta' => ['Marta Chávez', 'limpieza@hoteltest.mx', '+52 614 577 8841', 'housekeeping'],
        ];

        foreach ($people as $key => [$name, $email, $phone, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'phone' => $phone, 'password' => Hash::make('password')],
            );
            $user->assignRole($role);
            $this->staff[$key] = $user;
        }

        Auth::setUser($this->staff['carolina']);
    }

    private function seedShiftsAndAssignments(): void
    {
        $types = [
            'matutino' => ShiftType::firstOrCreate(
                ['property_id' => $this->property->id, 'name' => 'Matutino'],
                ['starts_at' => '07:00', 'ends_at' => '15:00', 'color' => '#0ea5e9', 'active' => true],
            ),
            'vespertino' => ShiftType::firstOrCreate(
                ['property_id' => $this->property->id, 'name' => 'Vespertino'],
                ['starts_at' => '15:00', 'ends_at' => '23:00', 'color' => '#8b5cf6', 'active' => true],
            ),
            'nocturno' => ShiftType::firstOrCreate(
                ['property_id' => $this->property->id, 'name' => 'Nocturno'],
                ['starts_at' => '23:00', 'ends_at' => '07:00', 'color' => '#64748b', 'active' => true],
            ),
        ];

        // Rol de la semana en curso (lunes a domingo).
        $monday = $this->today->startOfWeek();
        foreach (range(0, 6) as $offset) {
            $date = $monday->addDays($offset)->toDateString();
            foreach ([['luis', 'matutino'], ['sofia', 'vespertino'], ['marta', 'matutino']] as [$who, $type]) {
                ShiftAssignment::firstOrCreate([
                    'property_id' => $this->property->id,
                    'assignable_type' => User::class,
                    'assignable_id' => $this->staff[$who]->id,
                    'shift_type_id' => $types[$type]->id,
                    'date' => $date,
                ], ['created_by' => $this->staff['carolina']->id]);
            }
        }

        // Turnos reales de recepción de los últimos 12 días (para cortes por
        // turno); el de hoy queda abierto según la hora.
        if (Shift::query()->exists()) {
            foreach (Shift::query()->orderBy('started_at')->get() as $shift) {
                $this->shiftIndex[] = [
                    'id' => $shift->id,
                    'user_id' => $shift->user_id,
                    'start' => $shift->started_at,
                    'end' => $shift->ended_at,
                ];
            }

            return;
        }

        foreach (range(12, 0) as $daysAgo) {
            $day = $this->today->subDays($daysAgo);

            foreach ([
                ['luis', 7, 15, 1500.0],
                ['sofia', 15, 23, 1500.0],
            ] as [$who, $fromHour, $toHour, $opening]) {
                $start = $day->setTime($fromHour, 0);
                $end = $day->setTime($toHour, 0);

                if ($start->isFuture()) {
                    continue;
                }

                $shift = Shift::create([
                    'property_id' => $this->property->id,
                    'user_id' => $this->staff[$who]->id,
                    'started_at' => $start,
                    'ended_at' => $end->isFuture() ? null : $end,
                    'opening_cash' => $opening,
                    'created_by' => $this->staff[$who]->id,
                    'closed_by' => $end->isFuture() ? null : $this->staff[$who]->id,
                ]);
                $this->backdate($shift, $start);

                $this->shiftIndex[] = [
                    'id' => $shift->id,
                    'user_id' => $this->staff[$who]->id,
                    'start' => $start,
                    'end' => $end->isFuture() ? null : $end,
                ];
            }
        }
    }

    /** Recepcionista en funciones a esa hora y su turno abierto, si existe. */
    private function receptionAt(CarbonImmutable $at): array
    {
        $user = (int) $at->format('G') < 15 ? $this->staff['luis'] : $this->staff['sofia'];

        foreach ($this->shiftIndex as $row) {
            if ($row['user_id'] === $user->id && $at->greaterThan($row['start']) && ($row['end'] === null || $at->lessThanOrEqualTo($row['end']))) {
                return [$user, $row['id']];
            }
        }

        return [$user, null];
    }

    // ------------------------------------------------------------------
    // Huéspedes y catálogos
    // ------------------------------------------------------------------

    private function seedGuests(): void
    {
        if (Guest::query()->exists()) {
            $this->guests = Guest::query()->where('is_blacklisted', false)->orderBy('id')->get()->all();

            return;
        }

        $pool = [
            ['María Fernanda', 'García López', 'mfgarcia92@gmail.com', '1992-04-18', 'Chihuahua'],
            ['Roberto', 'Carrillo Núñez', 'rcarrillo@outlook.com', '1985-11-02', 'Chihuahua'],
            ['Ana Sofía', 'Beltrán Ríos', 'anasofia.br@gmail.com', '1996-08-14', 'Cd. Juárez'],
            ['Jorge Luis', 'Domínguez Vega', 'jldominguez@hotmail.com', '1979-01-25', 'Delicias'],
            ['Paola', 'Estrada Cano', 'paoestrada@gmail.com', '1990-06-30', 'Cuauhtémoc'],
            ['Héctor', 'Muñoz Salas', 'hmunozs@gmail.com', '1988-03-12', 'Parral'],
            ['Daniela', 'Reyes Ochoa', 'dreyeso@outlook.com', '1994-09-21', 'Chihuahua'],
            ['Fernando', 'Aguilar Pineda', 'feragupi@gmail.com', '1982-12-05', 'Torreón'],
            ['Lucía', 'Herrera Sandoval', 'lucy.herrera@gmail.com', '1998-02-17', 'Monterrey'],
            ['Miguel Ángel', 'Torres Baca', 'matorresb@hotmail.com', '1975-07-09', 'Chihuahua'],
            ['Karla', 'Villalobos Mena', 'karlavm@gmail.com', '1993-10-28', 'Cd. Juárez'],
            ['Ricardo', 'Fuentes Lara', 'rfuenteslara@gmail.com', '1987-05-16', 'Delicias'],
            ['Gabriela', 'Cisneros Peña', 'gaby.cisneros@outlook.com', '1991-08-03', 'Chihuahua'],
            ['Arturo', 'Quintana Ruiz', 'aquintanar@gmail.com', '1980-04-22', 'Camargo'],
            ['Valeria', 'Montes Ibarra', 'vmontesi@gmail.com', '1997-12-11', 'Chihuahua'],
            ['Samuel', 'Nájera Ponce', 'samnajera@hotmail.com', '1984-06-07', 'Cuauhtémoc'],
            ['Alejandra', 'Bustillos Gil', 'alebustillos@gmail.com', '1995-03-29', 'Chihuahua'],
            ['Oscar', 'Perea Holguín', 'operea@gmail.com', '1978-09-15', 'Parral'],
            ['Renata', 'Salcido Vargas', 'renatasalcido@gmail.com', '1999-01-08', 'Monterrey'],
            ['Emily', 'Johnson', 'emily.johnson@gmail.com', '1990-10-19', 'El Paso'],
        ];

        foreach ($pool as $i => [$first, $last, $email, $birth, $city]) {
            $this->guests[] = Guest::create([
                'first_name' => $first,
                'last_name' => $last,
                'phone' => sprintf('+52 614 %03d %04d', mt_rand(100, 799), mt_rand(1000, 9999)),
                'email' => $email,
                'birth_date' => $birth,
                'nationality' => $last === 'Johnson' ? 'US' : 'MX',
                'city' => $city,
                'state' => in_array($city, ['Monterrey'], true) ? 'Nuevo León' : (in_array($city, ['Torreón'], true) ? 'Coahuila' : ($city === 'El Paso' ? 'Texas' : 'Chihuahua')),
                'id_document_type' => $last === 'Johnson' ? 'pasaporte' : 'ine',
                'id_document_number' => $last === 'Johnson' ? 'US'.mt_rand(10000000, 99999999) : 'IDMEX'.mt_rand(100000000, 999999999),
                'marketing_consent' => $i % 3 !== 0,
            ]);
        }

        // Huésped vetado: aparece en el CRM pero no en la operación.
        Guest::create([
            'first_name' => 'Iván',
            'last_name' => 'Salas Peña',
            'phone' => '+52 614 641 0093',
            'id_document_type' => 'ine',
            'id_document_number' => 'IDMEX'.mt_rand(100000000, 999999999),
            'is_blacklisted' => true,
            'blacklist_reason' => 'Daños al mobiliario en una visita previa y negativa de pago.',
        ]);
    }

    private function seedCouponsAndSeasons(): void
    {
        $coupons = [
            ['BIENVENIDA10', Coupon::KIND_PERCENT, 10, ['max_uses' => 200]],
            ['VERANO26', Coupon::KIND_AMOUNT, 150, ['ends_at' => $this->today->endOfMonth(), 'max_uses' => 100]],
            ['CUMPLE15', Coupon::KIND_PERCENT, 15, ['birthday' => true]],
            ['FRECUENTE10', Coupon::KIND_PERCENT, 10, ['min_visits' => 3]],
            ['ESCAPADA12', Coupon::KIND_PERCENT, 12, ['min_nights' => 3]],
            ['NAVIDAD25', Coupon::KIND_PERCENT, 20, ['ends_at' => CarbonImmutable::parse('2025-12-31'), 'active' => false]],
        ];

        foreach ($coupons as [$code, $kind, $value, $extra]) {
            Coupon::firstOrCreate(['code' => $code], $extra + [
                'kind' => $kind,
                'value' => $value,
                'active' => true,
            ]);
        }

        // Tarifas flexibles: una temporada y una promo vigentes.
        $suitePlan = $this->planByType[array_key_last($this->planByType)] ?? null;
        $firstPlan = $this->planByType[array_key_first($this->planByType)] ?? null;

        if ($suitePlan) {
            RatePlanSeason::firstOrCreate([
                'rate_plan_id' => $suitePlan->id,
                'name' => 'Temporada alta septiembre',
            ], [
                'kind' => RatePlanSeason::KIND_SEASON,
                'starts_on' => $this->today->setDate(2026, 9, 1)->toDateString(),
                'ends_on' => $this->today->setDate(2026, 9, 30)->toDateString(),
                'price' => round((float) $suitePlan->price * 1.15, 2),
                'priority' => 10,
                'active' => true,
            ]);
        }

        if ($firstPlan) {
            RatePlanSeason::firstOrCreate([
                'rate_plan_id' => $firstPlan->id,
                'name' => 'Promo estancia larga agosto',
            ], [
                'kind' => RatePlanSeason::KIND_PROMO,
                'starts_on' => $this->today->startOfMonth()->toDateString(),
                'ends_on' => $this->today->endOfMonth()->toDateString(),
                'min_nights' => 3,
                'price' => round((float) $firstPlan->price * 0.85, 2),
                'priority' => 5,
                'active' => true,
            ]);
        }
    }

    private function seedExtras(): void
    {
        $extras = [
            ['Decoración romántica', 'Pétalos, velas LED y botella de vino espumoso en la habitación.', 350],
            ['Desayuno para dos', 'Desayuno completo servido en la habitación entre 8:00 y 11:00.', 180],
            ['Late check-out', 'Salida extendida hasta las 15:00, sujeta a disponibilidad.', 150],
            ['Cava de quesos y vino', 'Tabla de quesos regionales con botella de vino tinto.', 420],
        ];

        foreach ($extras as $i => [$name, $description, $price]) {
            Extra::firstOrCreate(['property_id' => $this->property->id, 'name' => $name], [
                'description' => $description,
                'price' => $price,
                'active' => true,
                'sort_order' => $i,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Experiencias
    // ------------------------------------------------------------------

    private function seedExperiences(): void
    {
        if (Experience::query()->exists()) {
            $this->tonightSession = ExperienceSession::query()
                ->where('starts_at', $this->today->setTime(20, 0))
                ->first();

            return;
        }

        $cena = Experience::create([
            'property_id' => $this->property->id,
            'name' => 'Cena romántica en la terraza',
            'description' => 'Cena de tres tiempos con vista, música ambiental y copa de bienvenida.',
            'includes' => 'Menú de tres tiempos, copa de espumoso y decoración de mesa.',
            'duration_minutes' => 120,
            'pricing_mode' => 'per_person',
            'price' => 480,
            'min_people' => 2,
            'max_people' => 10,
            'active' => true,
            'sort_order' => 0,
        ]);

        $tour = Experience::create([
            'property_id' => $this->property->id,
            'name' => 'Tour por el centro histórico',
            'description' => 'Recorrido guiado de dos horas por los principales puntos del centro.',
            'includes' => 'Guía certificado, agua embotellada y traslado desde el hotel.',
            'duration_minutes' => 150,
            'pricing_mode' => 'per_person',
            'price' => 350,
            'min_people' => 2,
            'max_people' => 12,
            'active' => true,
            'sort_order' => 1,
        ]);

        // Sesiones pasadas con reservas cobradas.
        foreach ([28, 21, 14, 7] as $daysAgo) {
            $startsAt = $this->today->subDays($daysAgo)->setTime(20, 0);
            $session = ExperienceSession::create([
                'experience_id' => $cena->id,
                'starts_at' => $startsAt,
                'capacity' => 10,
                'status' => ExperienceSession::STATUS_COMPLETED,
            ]);
            $this->backdate($session, $startsAt->subDays(5));

            $people = mt_rand(2, 4);
            $guest = $this->guests[array_rand($this->guests)];
            $booking = ExperienceBooking::create([
                'experience_session_id' => $session->id,
                'guest_id' => $guest->id,
                'guest_name' => $guest->first_name.' '.$guest->last_name,
                'people' => $people,
                'total' => $people * 480,
                'status' => ExperienceBooking::STATUS_COMPLETED,
                'created_by' => $this->staff['sofia']->id,
            ]);
            $booking->forceFill(['code' => ExperienceBooking::formatCode($booking->id, $startsAt)])->saveQuietly();
            $this->backdate($booking, $startsAt->subDays(4));

            [$receptionist, $shiftId] = $this->receptionAt($startsAt->subHours(2));
            $payment = Payment::create([
                'experience_booking_id' => $booking->id,
                'amount' => $people * 480,
                'method' => 'cash',
                'received_by' => $receptionist->id,
                'shift_id' => $shiftId,
                'paid_at' => $startsAt->subHours(2),
            ]);
            $this->backdate($payment, $startsAt->subHours(2));
        }

        // Sesiones futuras: la de hoy en la noche y la del sábado.
        $tonight = ExperienceSession::create([
            'experience_id' => $cena->id,
            'starts_at' => $this->today->setTime(20, 0),
            'capacity' => 10,
            'status' => ExperienceSession::STATUS_SCHEDULED,
        ]);

        $saturday = ExperienceSession::create([
            'experience_id' => $tour->id,
            'starts_at' => $this->today->next('Saturday')->setTime(10, 0),
            'capacity' => 12,
            'status' => ExperienceSession::STATUS_SCHEDULED,
        ]);

        $guest = $this->guests[8];
        $booking = ExperienceBooking::create([
            'experience_session_id' => $saturday->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'people' => 3,
            'total' => 3 * 350,
            'status' => ExperienceBooking::STATUS_CONFIRMED,
            'created_by' => $this->staff['luis']->id,
        ]);
        $booking->forceFill(['code' => ExperienceBooking::formatCode($booking->id, now())])->saveQuietly();

        $online = Payment::create([
            'experience_booking_id' => $booking->id,
            'amount' => 3 * 350,
            'fee_amount' => round(3 * 350 * 0.036 + 3, 2),
            'method' => Payment::METHOD_ONLINE,
            'gateway' => 'stripe',
            'gateway_ref' => 'ch_'.Str::random(14),
            'paid_at' => now()->subHours(20),
        ]);
        $this->backdate($online, now()->subHours(20));

        ExperienceBooking::create([
            'experience_session_id' => $saturday->id,
            'guest_name' => 'Patricia Lozano',
            'people' => 2,
            'total' => 2 * 350,
            'status' => ExperienceBooking::STATUS_PENDING,
            'notes' => 'Pidió confirmar si el tour sale con lluvia.',
            'created_by' => $this->staff['sofia']->id,
        ])->forceFill(['code' => null])->saveQuietly();

        $this->tonightSession = $tonight;
    }

    private ?ExperienceSession $tonightSession = null;

    // ------------------------------------------------------------------
    // Historia (últimas ~6 semanas)
    // ------------------------------------------------------------------

    private function seedHistory(): void
    {
        if (Reservation::query()->exists()) {
            return;
        }

        foreach (range(45, 1) as $daysAgo) {
            $arrivalDay = $this->today->subDays($daysAgo);
            $arrivals = $this->pick([1 => 30, 2 => 45, 3 => 25]);

            foreach (range(1, $arrivals) as $i) {
                $this->seedPastStay($arrivalDay, $daysAgo);
            }

            // Venta de barra del día (POS), la registra Jorge.
            foreach (range(1, $this->pick([2 => 40, 3 => 40, 4 => 20])) as $i) {
                $this->seedBarOrder($arrivalDay);
            }

            if ($daysAgo % 9 === 0) {
                $this->seedCancelled($arrivalDay, $daysAgo);
            }

            if ($daysAgo % 13 === 0) {
                $this->seedNoShow($arrivalDay);
            }
        }
    }

    private function seedPastStay(CarbonImmutable $arrivalDay, int $daysAgo): void
    {
        $typeId = $this->pick([1 => 45, 2 => 35, 3 => 20]);
        if (! isset($this->roomsByType[$typeId], $this->planByType[$typeId])) {
            return;
        }

        $nights = min($this->pick([1 => 45, 2 => 35, 3 => 20]), $daysAgo - 1);
        if ($nights < 1) {
            return;
        }

        $start = $arrivalDay->setTime(15, 0);
        $end = $arrivalDay->addDays($nights)->setTime(12, 0);

        $room = $this->claimRoom($typeId, $arrivalDay, $arrivalDay->addDays($nights));
        if ($room === null) {
            return;
        }

        $plan = $this->planByType[$typeId];
        $guest = $this->guests[array_rand($this->guests)];
        $source = $this->pick(['web' => 40, 'front_desk' => 25, 'phone' => 15, 'whatsapp' => 10, 'walk_in' => 10]);

        $checkIn = $start->addMinutes(mt_rand(-30, 150));
        $checkOut = $end->addMinutes(mt_rand(-75, 30));
        [$inReceptionist, $inShiftId] = $this->receptionAt($checkIn);
        [$outReceptionist, $outShiftId] = $this->receptionAt($checkOut);

        $lodging = $nights * (float) $plan->price;

        // Walk-in: sin reserva, folio directo con fianza ocasional.
        if ($source === 'walk_in') {
            Auth::setUser($inReceptionist);
            $stay = Stay::create([
                'room_id' => $room->id,
                'rate_plan_id' => $plan->id,
                'guest_id' => $guest->id,
                'guest_name' => $guest->first_name.' '.$guest->last_name,
                'num_people' => min(mt_rand(1, 2), 2),
                'check_in_at' => $checkIn,
                'planned_end_at' => $end,
                'check_out_at' => $checkOut,
                'status' => Stay::STATUS_COMPLETED,
                'amount' => $lodging,
                'channel' => 'walk_in',
                'created_by' => $inReceptionist->id,
            ]);
            $this->backdate($stay, $checkIn);

            $this->makePayment([
                'stay_id' => $stay->id,
                'amount' => $lodging,
                'method' => $this->pick(['cash' => 65, 'card' => 35]),
                'kind' => Payment::KIND_LODGING,
                'received_by' => $inReceptionist->id,
                'shift_id' => $inShiftId,
            ], $checkIn);

            if (mt_rand(1, 100) <= 55) {
                $guarantee = $this->makePayment([
                    'stay_id' => $stay->id,
                    'amount' => 200,
                    'method' => 'cash',
                    'kind' => Payment::KIND_GUARANTEE,
                    'received_by' => $inReceptionist->id,
                    'shift_id' => $inShiftId,
                ], $checkIn->addMinutes(2));

                Auth::setUser($outReceptionist);
                $refund = Refund::create([
                    'payment_id' => $guarantee->id,
                    'amount' => 200,
                    'status' => Refund::STATUS_COMPLETED,
                    'reason' => 'Devolución de fianza a la salida',
                    'created_by' => $outReceptionist->id,
                    'refunded_at' => $checkOut,
                ]);
                $this->backdate($refund, $checkOut);
            }

            $this->maybeConsumption($stay, $checkIn, $checkOut, $outReceptionist, $outShiftId);
            $this->maybeSurvey($stay, $guest, $checkOut);

            return;
        }

        // Reserva anticipada (web/tel/whatsapp) o de mostrador.
        $bookedAt = in_array($source, ['web', 'whatsapp'], true)
            ? $start->subDays(mt_rand(2, 18))->setTime(mt_rand(9, 21), mt_rand(0, 59))
            : $start->subDays(mt_rand(0, 4))->setTime(mt_rand(9, 20), mt_rand(0, 59));

        $extras = null;
        $extrasTotal = 0.0;
        if (mt_rand(1, 100) <= 22) {
            $extra = Extra::query()->inRandomOrder()->first();
            $extras = [[
                'extra_id' => $extra->id,
                'name' => $extra->name,
                'qty' => 1.0,
                'unit_price' => (float) $extra->price,
                'total' => (float) $extra->price,
            ]];
            $extrasTotal = (float) $extra->price;
        }

        $discount = 0.0;
        $couponCode = null;
        if (mt_rand(1, 100) <= 15) {
            $coupon = Coupon::query()->where('code', 'BIENVENIDA10')->first();
            $discount = round($lodging * 0.10, 2);
            $couponCode = $coupon->code;
            $coupon->increment('used_count');
        }

        $total = round($lodging + $extrasTotal - $discount, 2);
        $isRemote = in_array($source, ['web', 'phone', 'whatsapp'], true);
        $deposit = $isRemote ? round($total * 0.30, 2) : 0.0;

        Auth::setUser($inReceptionist);
        $adults = min(mt_rand(1, 3), 3);
        $children = mt_rand(0, 100) <= 25 ? 1 : 0;

        $reservation = Reservation::create([
            'property_id' => $this->property->id,
            'room_type_id' => $typeId,
            'room_id' => $room->id,
            'rate_plan_id' => $plan->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'num_people' => $adults + $children,
            'adults' => $adults,
            'children' => $children,
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => ReservationStatus::Completed,
            'source_channel' => $source,
            'total_amount' => $total,
            'extras' => $extras,
            'deposit_amount' => $deposit,
            'coupon_code' => $couponCode,
            'discount_amount' => $discount,
            'payment_status' => PaymentStatus::Paid,
            'created_by' => $isRemote ? null : $inReceptionist->id,
        ]);
        $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, $bookedAt)]);
        $this->backdate($reservation, $bookedAt);

        // Anticipo al reservar + saldo al llegar.
        if ($deposit > 0) {
            $method = $this->pick(['online' => 55, 'transfer' => 45]);
            $this->makePayment($method === 'online' ? [
                'reservation_id' => $reservation->id,
                'amount' => $deposit,
                'fee_amount' => round($deposit * 0.036 + 3, 2),
                'method' => Payment::METHOD_ONLINE,
                'gateway' => $this->pick(['stripe' => 60, 'mercadopago' => 40]),
                'gateway_ref' => 'ch_'.Str::random(14),
            ] : [
                'reservation_id' => $reservation->id,
                'amount' => $deposit,
                'method' => 'transfer',
                'reference' => 'SPEI '.mt_rand(1000000, 9999999),
                'received_by' => $this->receptionAt($bookedAt)[0]->id,
                'shift_id' => $this->receptionAt($bookedAt)[1],
            ], $bookedAt->addMinutes(mt_rand(5, 120)));
        }

        Auth::setUser($inReceptionist);
        $this->makePayment([
            'reservation_id' => $reservation->id,
            'amount' => round($total - $deposit, 2),
            'method' => $this->pick(['cash' => 45, 'card' => 45, 'transfer' => 10]),
            'received_by' => $inReceptionist->id,
            'shift_id' => $inShiftId,
        ], $checkIn->addMinutes(5));

        $stay = Stay::create([
            'room_id' => $room->id,
            'reservation_id' => $reservation->id,
            'rate_plan_id' => $plan->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'num_people' => $adults + $children,
            'check_in_at' => $checkIn,
            'planned_end_at' => $end,
            'check_out_at' => $checkOut,
            'status' => Stay::STATUS_COMPLETED,
            'amount' => $total,
            'channel' => $source,
            'created_by' => $inReceptionist->id,
        ]);
        $this->backdate($stay, $checkIn);

        $this->maybeConsumption($stay, $checkIn, $checkOut, $outReceptionist, $outShiftId);
        $this->maybeSurvey($stay, $guest, $checkOut);
    }

    /** Consumo del minibar/barra cargado a la habitación y liquidado a la salida. */
    private function maybeConsumption(Stay $stay, CarbonImmutable $checkIn, CarbonImmutable $checkOut, User $receptionist, ?int $shiftId): void
    {
        if (mt_rand(1, 100) > 35 || $this->products === []) {
            return;
        }

        Auth::setUser($this->staff['jorge']);
        $orderAt = $checkIn->addHours(mt_rand(2, 6));
        [$lines, $subtotal, $cost] = $this->randomLines(mt_rand(1, 3));

        $order = Order::create([
            'property_id' => $this->property->id,
            'stay_id' => $stay->id,
            'status' => Order::STATUS_COMPLETED,
            'payment_method' => 'room',
            'settled_at' => $checkOut,
            'settled_by' => $receptionist->id,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'total_cost' => $cost,
            'created_by' => $this->staff['jorge']->id,
        ]);
        $this->attachLines($order, $lines);
        $this->backdate($order, $orderAt);

        Auth::setUser($receptionist);
        $this->makePayment([
            'stay_id' => $stay->id,
            'amount' => $subtotal,
            'method' => $this->pick(['cash' => 55, 'card' => 45]),
            'kind' => Payment::KIND_CONSUMPTION,
            'received_by' => $receptionist->id,
            'shift_id' => $shiftId,
        ], $checkOut->addMinutes(2));
    }

    private function maybeSurvey(Stay $stay, Guest $guest, CarbonImmutable $checkOut): void
    {
        if (mt_rand(1, 100) > 65) {
            return;
        }

        $stay->forceFill(['thanks_sent_at' => $checkOut->addHours(2)])->saveQuietly();

        $survey = StaySurvey::forStay($stay);
        $this->backdate($survey, $checkOut->addHours(2));

        if (mt_rand(1, 100) > 70) {
            return; // enviada pero sin responder
        }

        $rating = $this->pick([5 => 42, 4 => 34, 3 => 14, 2 => 7, 1 => 3]);
        $answers = [];
        foreach (StaySurvey::aspects() as $aspect) {
            $answers[$aspect['key']] = max(1, min(5, $rating + mt_rand(-1, 1)));
        }

        $comments = [
            5 => ['Excelente atención, la habitación impecable.', 'Todo perfecto, volveremos pronto.', 'El personal muy amable, gracias por las atenciones.'],
            4 => ['Muy buena estancia, solo el aire tardó en enfriar.', 'Cómodo y limpio, el estacionamiento algo justo.'],
            3 => ['Bien en general, la regadera tenía poca presión.', 'Correcto por el precio, el wifi se caía por ratos.'],
            2 => ['La habitación olía a humedad y tardaron en atendernos.'],
            1 => ['Mala experiencia, mucho ruido en la madrugada y la TV no funcionaba.'],
        ];

        $survey->forceFill([
            'rating' => $rating,
            'answers' => $answers,
            'comment' => mt_rand(1, 100) <= 60 ? $comments[$rating][array_rand($comments[$rating])] : null,
            'submitted_at' => $checkOut->addHours(mt_rand(4, 30)),
        ])->saveQuietly();
    }

    private function seedBarOrder(CarbonImmutable $day): void
    {
        if ($this->products === []) {
            return;
        }

        Auth::setUser($this->staff['jorge']);
        $at = $day->setTime(mt_rand(12, 22), mt_rand(0, 59));
        [$lines, $subtotal, $cost] = $this->randomLines(mt_rand(1, 4));
        $tip = mt_rand(1, 100) <= 30 ? (float) (mt_rand(2, 6) * 5) : 0.0;

        $order = Order::create([
            'property_id' => $this->property->id,
            'status' => 'completed',
            'payment_method' => $this->pick(['cash' => 60, 'card' => 40]),
            'settled_at' => $at,
            'settled_by' => $this->staff['jorge']->id,
            'subtotal' => $subtotal,
            'tip' => $tip,
            'total' => $subtotal + $tip,
            'total_cost' => $cost,
            'created_by' => $this->staff['jorge']->id,
        ]);
        $this->attachLines($order, $lines);
        $this->backdate($order, $at);
    }

    private function seedCancelled(CarbonImmutable $arrivalDay, int $daysAgo): void
    {
        $typeId = array_rand($this->planByType);
        $plan = $this->planByType[$typeId];
        $guest = $this->guests[array_rand($this->guests)];
        $start = $arrivalDay->setTime(15, 0);
        $bookedAt = $start->subDays(mt_rand(6, 15))->setTime(mt_rand(10, 20), mt_rand(0, 59));
        $cancelledAt = $start->subDays(mt_rand(1, 3))->setTime(mt_rand(9, 19), mt_rand(0, 59));
        $total = 2 * (float) $plan->price;
        $deposit = round($total * 0.30, 2);

        Auth::setUser($this->staff['sofia']);
        $reservation = Reservation::create([
            'property_id' => $this->property->id,
            'room_type_id' => $typeId,
            'rate_plan_id' => $plan->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'num_people' => 2,
            'adults' => 2,
            'starts_at' => $start,
            'ends_at' => $arrivalDay->addDays(2)->setTime(12, 0),
            'status' => ReservationStatus::Cancelled,
            'source_channel' => 'web',
            'total_amount' => $total,
            'deposit_amount' => $deposit,
            'payment_status' => PaymentStatus::DepositPaid,
            'cancellation_reason' => $this->pick([
                'El huésped canceló el viaje por trabajo' => 50,
                'Cambio de fechas, hará una nueva reserva' => 30,
                'Encontró hospedaje con otra ubicación' => 20,
            ]),
        ]);
        $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, $bookedAt)]);
        $this->backdate($reservation, $bookedAt);

        $payment = $this->makePayment([
            'reservation_id' => $reservation->id,
            'amount' => $deposit,
            'fee_amount' => round($deposit * 0.036 + 3, 2),
            'method' => Payment::METHOD_ONLINE,
            'gateway' => 'stripe',
            'gateway_ref' => 'ch_'.Str::random(14),
        ], $bookedAt->addMinutes(12));

        // Una de cada dos cancelaciones devuelve el anticipo.
        if ($daysAgo % 2 === 0) {
            $refund = Refund::create([
                'payment_id' => $payment->id,
                'reservation_id' => $reservation->id,
                'amount' => $deposit,
                'status' => Refund::STATUS_COMPLETED,
                'gateway' => 'stripe',
                'gateway_ref' => 're_'.Str::random(14),
                'reason' => 'Cancelación dentro del plazo de la política',
                'created_by' => $this->staff['carolina']->id,
                'refunded_at' => $cancelledAt->addHours(3),
            ]);
            $this->backdate($refund, $cancelledAt->addHours(3));
        }
    }

    private function seedNoShow(CarbonImmutable $arrivalDay): void
    {
        $typeId = array_rand($this->planByType);
        $plan = $this->planByType[$typeId];
        $guest = $this->guests[array_rand($this->guests)];
        $start = $arrivalDay->setTime(15, 0);
        $bookedAt = $start->subDays(mt_rand(3, 10))->setTime(mt_rand(10, 21), mt_rand(0, 59));

        Auth::setUser($this->staff['sofia']);
        $reservation = Reservation::create([
            'property_id' => $this->property->id,
            'room_type_id' => $typeId,
            'rate_plan_id' => $plan->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'num_people' => 1,
            'adults' => 1,
            'starts_at' => $start,
            'ends_at' => $arrivalDay->addDay()->setTime(12, 0),
            'status' => ReservationStatus::NoShow,
            'source_channel' => 'phone',
            'total_amount' => (float) $plan->price,
            'payment_status' => PaymentStatus::Unpaid,
            'notes' => 'No llegó ni contestó los recordatorios; se liberó la habitación a las 23:00.',
        ]);
        $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, $bookedAt)]);
        $this->backdate($reservation, $bookedAt);
    }

    // ------------------------------------------------------------------
    // Día en curso: semáforo vivo del plano
    // ------------------------------------------------------------------

    private function seedCurrentDay(): void
    {
        if (Stay::query()->where('status', Stay::STATUS_ACTIVE)->exists()) {
            return;
        }

        $yesterday = $this->today->subDay();
        $tomorrow = $this->today->addDay();
        $rooms = Room::query()->orderBy('number')->get()->keyBy('number');

        // 101 ocupada: reserva web con anticipo, entró ayer, sale mañana.
        if ($room = $rooms->get('101')) {
            $stay = $this->checkInReservation($room, $this->guests[0], 'web', $yesterday, 2, depositOnline: true);
            $this->setRoomStatus($room, RoomStatus::Occupied, 'Check-in de reserva', $stay->check_in_at);

            if ($this->tonightSession && $stay->reservation_id) {
                $booking = ExperienceBooking::create([
                    'experience_session_id' => $this->tonightSession->id,
                    'guest_id' => $this->guests[0]->id,
                    'reservation_id' => $stay->reservation_id,
                    'guest_name' => $this->guests[0]->first_name.' '.$this->guests[0]->last_name,
                    'people' => 2,
                    'total' => 2 * 480,
                    'status' => ExperienceBooking::STATUS_CONFIRMED,
                    'notes' => 'Celebran aniversario, mesa junto al barandal.',
                    'created_by' => $this->staff['luis']->id,
                ]);
                $booking->forceFill(['code' => ExperienceBooking::formatCode($booking->id, now())])->saveQuietly();
            }
        }

        // 102 ocupada: walk-in de hoy con fianza en efectivo sin devolver.
        if ($room = $rooms->get('102')) {
            $checkIn = $this->today->setTime(13, 10);
            [$receptionist, $shiftId] = $this->receptionAt($checkIn);
            Auth::setUser($receptionist);
            $plan = $this->planByType[$room->room_type_id];
            $guest = $this->guests[5];

            $stay = Stay::create([
                'room_id' => $room->id,
                'rate_plan_id' => $plan->id,
                'guest_id' => $guest->id,
                'guest_name' => $guest->first_name.' '.$guest->last_name,
                'num_people' => 2,
                'vehicle_plate' => 'CHH-482-A',
                'check_in_at' => $checkIn,
                'planned_end_at' => $tomorrow->setTime(12, 0),
                'status' => Stay::STATUS_ACTIVE,
                'amount' => (float) $plan->price,
                'channel' => 'walk_in',
                'created_by' => $receptionist->id,
            ]);
            $this->backdate($stay, $checkIn);
            $this->claim($room->id, $this->today, $tomorrow);

            $this->makePayment([
                'stay_id' => $stay->id,
                'amount' => (float) $plan->price,
                'method' => 'cash',
                'kind' => Payment::KIND_LODGING,
                'received_by' => $receptionist->id,
                'shift_id' => $shiftId,
            ], $checkIn);
            $this->makePayment([
                'stay_id' => $stay->id,
                'amount' => 200,
                'method' => 'cash',
                'kind' => Payment::KIND_GUARANTEE,
                'received_by' => $receptionist->id,
                'shift_id' => $shiftId,
            ], $checkIn->addMinutes(2));

            $this->setRoomStatus($room, RoomStatus::Occupied, 'Walk-in', $checkIn);
        }

        // 105 ocupada: reserva de mostrador que paga a la salida (saldo en plano).
        if ($room = $rooms->get('105')) {
            $stay = $this->checkInReservation($room, $this->guests[11], 'front_desk', $this->today, 1, depositOnline: false, paid: false);
            $this->setRoomStatus($room, RoomStatus::Occupied, 'Check-in de reserva', $stay->check_in_at);
        }

        // 103 sucia: salida de esta mañana, encuesta enviada.
        if ($room = $rooms->get('103')) {
            $stay = $this->checkInReservation($room, $this->guests[3], 'phone', $yesterday, 1, depositOnline: false, checkOutAt: $this->today->setTime(11, 35));
            $this->maybeSurveyForced($stay, $this->guests[3]);
            $this->setRoomStatus($room, RoomStatus::Dirty, 'Check-out', $this->today->setTime(11, 35));
        }

        // 202 en limpieza: salida de hoy, Marta ya está dentro.
        if ($room = $rooms->get('202')) {
            $this->checkInReservation($room, $this->guests[7], 'web', $yesterday, 1, depositOnline: true, checkOutAt: $this->today->setTime(10, 55));
            $this->setRoomStatus($room, RoomStatus::Dirty, 'Check-out', $this->today->setTime(10, 55));
            $this->setRoomStatus($room, RoomStatus::Cleaning, 'Limpieza en curso', $this->today->setTime(12, 20), $this->staff['marta']);
        }

        // 204 en mantenimiento: fuga en el jacuzzi, bloqueada tres días.
        if ($room = $rooms->get('204')) {
            Auth::setUser($this->staff['carolina']);
            RoomBlock::create([
                'room_id' => $room->id,
                'starts_at' => $this->today,
                'ends_at' => $this->today->addDays(3),
                'reason' => 'Reparación de fuga en el jacuzzi',
                'created_by' => $this->staff['carolina']->id,
            ]);
            $room->forceFill(['maintenance_notes' => 'Fuga en tubería del jacuzzi; refacción llega el miércoles.'])->saveQuietly();
            $this->setRoomStatus($room, RoomStatus::Maintenance, 'Bloqueo por mantenimiento', $yesterday->setTime(19, 10));
            $this->claim($room->id, $this->today, $this->today->addDays(3));
        }

        // 106 reservada: llegada confirmada de hoy con anticipo pagado.
        if ($room = $rooms->get('106')) {
            $plan = $this->planByType[$room->room_type_id];
            $guest = $this->guests[14];
            $total = (float) $plan->price;
            $deposit = round($total * 0.30, 2);
            $bookedAt = now()->subDays(3)->setTime(17, 24);

            $reservation = Reservation::create([
                'property_id' => $this->property->id,
                'room_type_id' => $room->room_type_id,
                'room_id' => $room->id,
                'rate_plan_id' => $plan->id,
                'guest_id' => $guest->id,
                'guest_name' => $guest->first_name.' '.$guest->last_name,
                'num_people' => 2,
                'adults' => 2,
                'eta' => '19:00',
                'starts_at' => $this->today->setTime(15, 0),
                'ends_at' => $tomorrow->setTime(12, 0),
                'status' => ReservationStatus::Confirmed,
                'source_channel' => 'web',
                'total_amount' => $total,
                'deposit_amount' => $deposit,
                'payment_status' => PaymentStatus::DepositPaid,
            ]);
            $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, $bookedAt)]);
            $this->backdate($reservation, $bookedAt);
            $this->claim($room->id, $this->today, $tomorrow);

            $this->makePayment([
                'reservation_id' => $reservation->id,
                'amount' => $deposit,
                'fee_amount' => round($deposit * 0.036 + 3, 2),
                'method' => Payment::METHOD_ONLINE,
                'gateway' => 'mercadopago',
                'gateway_ref' => 'mp_'.Str::random(12),
            ], $bookedAt->addMinutes(9));

            $this->setRoomStatus($room, RoomStatus::Reserved, 'Llegada de hoy', $this->today->setTime(9, 0));
        }
    }

    /**
     * Reserva + check-in listos: activa si no se da checkOutAt, completada si sí.
     */
    private function checkInReservation(Room $room, Guest $guest, string $source, CarbonImmutable $arrivalDay, int $nights, bool $depositOnline = false, bool $paid = true, ?CarbonImmutable $checkOutAt = null): Stay
    {
        $plan = $this->planByType[$room->room_type_id];
        $start = $arrivalDay->setTime(15, 0);
        $end = $arrivalDay->addDays($nights)->setTime(12, 0);
        $checkIn = $start->addMinutes(mt_rand(10, 120));
        if ($checkIn->isFuture()) {
            $checkIn = now()->subMinutes(mt_rand(15, 45));
        }
        [$receptionist, $shiftId] = $this->receptionAt($checkIn);
        Auth::setUser($receptionist);

        $total = $nights * (float) $plan->price;
        $deposit = $depositOnline ? round($total * 0.30, 2) : 0.0;
        $bookedAt = $start->subDays(mt_rand(1, 8))->setTime(mt_rand(10, 21), mt_rand(0, 59));

        $paymentStatus = PaymentStatus::Unpaid;
        if ($paid) {
            $paymentStatus = PaymentStatus::Paid;
        } elseif ($deposit > 0) {
            $paymentStatus = PaymentStatus::DepositPaid;
        }

        $reservation = Reservation::create([
            'property_id' => $this->property->id,
            'room_type_id' => $room->room_type_id,
            'room_id' => $room->id,
            'rate_plan_id' => $plan->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'num_people' => 2,
            'adults' => 2,
            'starts_at' => $start,
            'ends_at' => $end,
            'status' => $checkOutAt ? ReservationStatus::Completed : ReservationStatus::CheckedIn,
            'source_channel' => $source,
            'total_amount' => $total,
            'deposit_amount' => $deposit,
            'payment_status' => $paymentStatus,
            'created_by' => in_array($source, ['front_desk', 'phone'], true) ? $receptionist->id : null,
        ]);
        $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, $bookedAt)]);
        $this->backdate($reservation, $bookedAt);
        $this->claim($room->id, $arrivalDay, $arrivalDay->addDays($nights));

        if ($deposit > 0) {
            $this->makePayment([
                'reservation_id' => $reservation->id,
                'amount' => $deposit,
                'fee_amount' => round($deposit * 0.036 + 3, 2),
                'method' => Payment::METHOD_ONLINE,
                'gateway' => 'stripe',
                'gateway_ref' => 'ch_'.Str::random(14),
            ], $bookedAt->addMinutes(11));
        }

        if ($paid) {
            $this->makePayment([
                'reservation_id' => $reservation->id,
                'amount' => round($total - $deposit, 2),
                'method' => $this->pick(['cash' => 50, 'card' => 50]),
                'received_by' => $receptionist->id,
                'shift_id' => $shiftId,
            ], $checkIn->addMinutes(4));
        }

        $stay = Stay::create([
            'room_id' => $room->id,
            'reservation_id' => $reservation->id,
            'rate_plan_id' => $plan->id,
            'guest_id' => $guest->id,
            'guest_name' => $guest->first_name.' '.$guest->last_name,
            'num_people' => 2,
            'check_in_at' => $checkIn,
            'planned_end_at' => $end,
            'check_out_at' => $checkOutAt,
            'status' => $checkOutAt ? Stay::STATUS_COMPLETED : Stay::STATUS_ACTIVE,
            'amount' => $total,
            'channel' => $source,
            'created_by' => $receptionist->id,
        ]);
        $this->backdate($stay, $checkIn);

        return $stay;
    }

    /** Encuesta enviada hoy, aún sin responder (para la demo del módulo). */
    private function maybeSurveyForced(Stay $stay, Guest $guest): void
    {
        $sentAt = $stay->check_out_at ? $stay->check_out_at->addHours(2) : now();
        $stay->forceFill(['thanks_sent_at' => $sentAt])->saveQuietly();
        $survey = StaySurvey::forStay($stay);
        $this->backdate($survey, $sentAt);
    }

    // ------------------------------------------------------------------
    // Futuro: llegadas próximas, hold, grupo
    // ------------------------------------------------------------------

    private function seedFuture(): void
    {
        if (Reservation::query()->where('starts_at', '>=', $this->today->addDay())->exists()) {
            return;
        }

        $plans = [
            // [días, noches, tipo idx (1=primero), fuente, anticipo, cupón, notas]
            [1, 2, 2, 'web', true, null, null],
            [2, 1, 1, 'phone', false, null, null],
            [3, 2, 3, 'web', true, null, 'Celebran su aniversario, piden decoración romántica.'],
            [5, 2, 1, 'whatsapp', true, null, null],
            [5, 2, 2, 'web', true, 'VERANO26', null],
            [7, 3, 3, 'front_desk', false, null, null],
            [10, 1, 2, 'web', false, null, null],
            [12, 2, 1, 'agent', true, null, 'Reserva tomada por el asistente en WhatsApp.'],
        ];

        $typeIds = array_keys($this->planByType);

        foreach ($plans as [$inDays, $nights, $typeIdx, $source, $withDeposit, $couponCode, $notes]) {
            $typeId = $typeIds[min($typeIdx, count($typeIds)) - 1];
            $plan = $this->planByType[$typeId];
            $arrivalDay = $this->today->addDays($inDays);
            $room = $this->claimRoom($typeId, $arrivalDay, $arrivalDay->addDays($nights));
            $guest = $this->guests[array_rand($this->guests)];
            $bookedAt = now()->subDays(mt_rand(0, 6))->subHours(mt_rand(1, 9));

            $lodging = $nights * (float) $plan->price;
            $extras = null;
            $extrasTotal = 0.0;
            if ($notes !== null && str_contains($notes, 'decoración')) {
                $extra = Extra::query()->where('name', 'Decoración romántica')->first();
                if ($extra) {
                    $extras = [[
                        'extra_id' => $extra->id,
                        'name' => $extra->name,
                        'qty' => 1.0,
                        'unit_price' => (float) $extra->price,
                        'total' => (float) $extra->price,
                    ]];
                    $extrasTotal = (float) $extra->price;
                }
            }

            $discount = 0.0;
            if ($couponCode !== null) {
                $coupon = Coupon::query()->where('code', $couponCode)->first();
                $discount = $coupon->kind === Coupon::KIND_PERCENT
                    ? round($lodging * (float) $coupon->value / 100, 2)
                    : (float) $coupon->value;
                $coupon->increment('used_count');
            }

            $total = round($lodging + $extrasTotal - $discount, 2);
            $deposit = $withDeposit ? round($total * 0.30, 2) : 0.0;

            Auth::setUser($this->staff['sofia']);
            $reservation = Reservation::create([
                'property_id' => $this->property->id,
                'room_type_id' => $typeId,
                'room_id' => $room?->id,
                'rate_plan_id' => $plan->id,
                'guest_id' => $guest->id,
                'guest_name' => $guest->first_name.' '.$guest->last_name,
                'num_people' => 2,
                'adults' => 2,
                'starts_at' => $arrivalDay->setTime(15, 0),
                'ends_at' => $arrivalDay->addDays($nights)->setTime(12, 0),
                'status' => ReservationStatus::Confirmed,
                'source_channel' => $source,
                'total_amount' => $total,
                'extras' => $extras,
                'deposit_amount' => $deposit,
                'coupon_code' => $couponCode,
                'discount_amount' => $discount,
                'payment_status' => $withDeposit ? PaymentStatus::DepositPaid : PaymentStatus::Unpaid,
                'payment_due_at' => $withDeposit ? null : $arrivalDay->subDays(2)->setTime(18, 0),
                'guest_notes' => $notes,
            ]);
            $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, $bookedAt)]);
            $this->backdate($reservation, $bookedAt);

            if ($deposit > 0) {
                $this->makePayment([
                    'reservation_id' => $reservation->id,
                    'amount' => $deposit,
                    'fee_amount' => round($deposit * 0.036 + 3, 2),
                    'method' => Payment::METHOD_ONLINE,
                    'gateway' => $this->pick(['stripe' => 60, 'mercadopago' => 40]),
                    'gateway_ref' => 'ch_'.Str::random(14),
                ], $bookedAt->addMinutes(8));
            }
        }

        // Hold del motor web a punto de expirar.
        $holdType = $typeIds[0];
        $holdPlan = $this->planByType[$holdType];
        $holdStart = $this->today->addDays(4);
        $hold = Reservation::create([
            'property_id' => $this->property->id,
            'room_type_id' => $holdType,
            'rate_plan_id' => $holdPlan->id,
            'guest_name' => 'Patricia Lozano',
            'num_people' => 2,
            'adults' => 2,
            'starts_at' => $holdStart->setTime(15, 0),
            'ends_at' => $holdStart->addDay()->setTime(12, 0),
            'status' => ReservationStatus::Pending,
            'hold_expires_at' => now()->addMinutes(25),
            'source_channel' => 'web',
            'total_amount' => (float) $holdPlan->price,
            'payment_status' => PaymentStatus::Unpaid,
        ]);
        $hold->forceFill(['code' => Reservation::formatCode($hold->id, now())])->saveQuietly();

        // Grupo: tres habitaciones para una boda el fin de semana entrante.
        $organizer = $this->guests[9];
        Auth::setUser($this->staff['carolina']);
        $group = ReservationGroup::create([
            'property_id' => $this->property->id,
            'guest_id' => $organizer->id,
            'guest_name' => $organizer->first_name.' '.$organizer->last_name,
            'notes' => 'Boda García-Torres: llegan juntos el viernes, salida el domingo.',
            'created_by' => $this->staff['carolina']->id,
        ]);
        $group->forceFill(['code' => ReservationGroup::formatCode($group->id, now())])->saveQuietly();

        $friday = $this->today->addDays(11);
        $groupGuests = [$organizer, $this->guests[10], $this->guests[12]];
        foreach ($groupGuests as $i => $guest) {
            $typeId = $typeIds[min($i === 0 ? 2 : 1, count($typeIds)) - 1];
            $plan = $this->planByType[$typeId];
            $room = $this->claimRoom($typeId, $friday, $friday->addDays(2));
            $total = 2 * (float) $plan->price;
            $deposit = round($total * 0.30, 2);

            $reservation = Reservation::create([
                'property_id' => $this->property->id,
                'room_type_id' => $typeId,
                'room_id' => $room?->id,
                'rate_plan_id' => $plan->id,
                'reservation_group_id' => $group->id,
                'guest_id' => $guest->id,
                'guest_name' => $guest->first_name.' '.$guest->last_name,
                'num_people' => 2,
                'adults' => 2,
                'starts_at' => $friday->setTime(15, 0),
                'ends_at' => $friday->addDays(2)->setTime(12, 0),
                'status' => ReservationStatus::Confirmed,
                'source_channel' => 'front_desk',
                'total_amount' => $total,
                'deposit_amount' => $deposit,
                'payment_status' => PaymentStatus::DepositPaid,
                'created_by' => $this->staff['carolina']->id,
            ]);
            $reservation->forceFill(['code' => Reservation::formatCode($reservation->id, now())])->saveQuietly();

            $this->makePayment([
                'reservation_id' => $reservation->id,
                'amount' => $deposit,
                'method' => 'transfer',
                'reference' => 'SPEI '.mt_rand(1000000, 9999999),
                'received_by' => $this->staff['sofia']->id,
                'shift_id' => $this->receptionAt(now()->subDay()->setTime(18, 0))[1],
            ], now()->subDay()->setTime(18, 0));
        }
    }

    private function seedWaitlist(): void
    {
        if (WaitlistEntry::query()->exists()) {
            return;
        }

        $typeIds = array_keys($this->planByType);
        $suiteType = $typeIds[count($typeIds) - 1];
        $saturday = $this->today->next('Saturday');

        WaitlistEntry::create([
            'room_type_id' => $suiteType,
            'starts_at' => $saturday->setTime(15, 0),
            'ends_at' => $saturday->addDay()->setTime(12, 0),
            'guest_name' => 'Claudia Espino',
            'guest_phone' => '+52 614 733 2481',
            'guest_email' => 'claudia.espino@gmail.com',
            'status' => WaitlistEntry::STATUS_WAITING,
        ]);

        WaitlistEntry::create([
            'room_type_id' => $suiteType,
            'starts_at' => $this->today->addDays(3)->setTime(15, 0),
            'ends_at' => $this->today->addDays(4)->setTime(12, 0),
            'guest_name' => 'Marcos Tello',
            'guest_phone' => '+52 614 208 5566',
            'status' => WaitlistEntry::STATUS_NOTIFIED,
            'notified_at' => now()->subHours(5),
        ]);

        $old = WaitlistEntry::create([
            'room_type_id' => $typeIds[0],
            'starts_at' => $this->today->subDays(6)->setTime(15, 0),
            'ends_at' => $this->today->subDays(5)->setTime(12, 0),
            'guest_name' => 'Lorena Ávila',
            'guest_phone' => '+52 614 911 3007',
            'status' => WaitlistEntry::STATUS_EXPIRED,
            'notified_at' => $this->today->subDays(7)->setTime(12, 0),
        ]);
        $this->backdate($old, $this->today->subDays(9));
    }

    // ------------------------------------------------------------------
    // Incidencias
    // ------------------------------------------------------------------

    private function seedIncidents(): void
    {
        if (Incident::query()->exists()) {
            return;
        }

        $rooms = Room::query()->orderBy('number')->get()->keyBy('number');
        $c = $this->staff['carolina'];
        $l = $this->staff['luis'];
        $s = $this->staff['sofia'];
        $m = $this->staff['marta'];

        $incidents = [
            // [room, título, categoría, fuente, prioridad, estado, reportó, asignado, resolvió, hace_días, notas de cierre]
            ['204', 'Fuga de agua en el jacuzzi', 'jacuzzi', 'staff', 'high', 'open', $s, $m, null, 1, null],
            ['104', 'El aire acondicionado no enfría', 'clima', 'staff', 'high', 'open', $l, null, null, 0, null],
            ['201', 'El control de la TV no responde', 'tv', 'guest', 'low', 'open', null, null, null, 0, null],
            ['105', 'Poca presión de agua en la regadera', 'plomeria', 'staff', 'medium', 'in_progress', $s, $m, null, 2, null],
            ['203', 'Contacto eléctrico flojo junto a la cama', 'electricidad', 'staff', 'medium', 'in_progress', $l, $c, null, 3, null],
            ['101', 'Foco fundido en el baño', 'electricidad', 'staff', 'low', 'resolved', $l, $m, $m, 6, 'Se cambió el foco y se revisó el resto de la habitación.'],
            ['106', 'Cajón del buró desprendido', 'mobiliario', 'staff', 'low', 'resolved', $s, $m, $m, 9, 'Se atornilló el riel y quedó firme.'],
            ['102', 'Mancha de humedad en el techo', 'limpieza', 'guest', 'medium', 'resolved', null, $m, $m, 12, 'Se limpió y pintó el plafón; se revisó que no hubiera fuga activa.'],
            ['202', 'Chapa de la puerta se atora', 'seguridad', 'staff', 'high', 'resolved', $l, $c, $c, 16, 'Se lubricó y ajustó la chapa; se probó con tres tarjetas.'],
            ['103', 'Ruido en el minisplit', 'clima', 'staff', 'medium', 'resolved', $s, $m, $m, 21, 'Se limpiaron filtros y turbina; dejó de vibrar.'],
        ];

        foreach ($incidents as [$number, $title, $category, $source, $priority, $status, $reporter, $assignee, $resolver, $daysAgo, $resolution]) {
            $createdAt = $this->today->subDays($daysAgo)->setTime(mt_rand(9, 21), mt_rand(0, 59));
            Auth::setUser($reporter ?? $c);

            $incident = Incident::create([
                'room_id' => $rooms->get($number)?->id,
                'title' => $title,
                'category' => $category,
                'source' => $source,
                'description' => $source === 'guest'
                    ? 'Reportado por el huésped desde el código QR de la habitación.'
                    : 'Detectado durante el recorrido de supervisión.',
                'priority' => $priority,
                'status' => $status,
                'reported_by' => $reporter?->id,
                'assigned_to' => $assignee?->id,
                'resolved_by' => $resolver?->id,
                'resolved_at' => $status === 'resolved' ? $createdAt->addHours(mt_rand(4, 48)) : null,
                'resolution_notes' => $resolution,
            ]);
            $this->backdate($incident, $createdAt);
        }
    }

    /**
     * Técnicos de mantenimiento: dos de casa y dos externos. Tampoco entran
     * al panel — viven en su propia tabla por lo mismo que las camaristas.
     * De paso se les cuelgan las incidencias ya resueltas, que estaban sin
     * quién las reparó ni cuánto costaron.
     */
    private function seedMaintenanceCrew(): void
    {
        if (Technician::query()->exists()) {
            return;
        }

        $crew = [];

        foreach ([
            ['Jesús "Chuy" Barraza', '+52 614 155 3308', 'Plomería y electricidad', false, true, 'De planta. Entra de lunes a viernes por la mañana.'],
            ['Iván Domínguez', '+52 614 287 9012', 'Mantenimiento general', false, true, 'De planta, turno vespertino.'],
            ['Clima del Norte', '+52 614 410 2266', 'Aire acondicionado y refrigeración', true, true, 'Externo. Se le llama; no tiene rol fijo.'],
            ['Cerrajería Delicias', '+52 614 332 7749', 'Chapas y control de acceso', true, true, 'Externo, atiende urgencias el mismo día.'],
        ] as [$name, $phone, $specialty, $external, $active, $notes]) {
            $crew[] = Technician::create([
                'name' => $name,
                'phone' => $phone,
                'specialty' => $specialty,
                'external' => $external,
                'active' => $active,
                'notes' => $notes,
            ]);
        }

        // Las resueltas ya tuvieron que costarle algo a alguien.
        $resolved = Incident::query()->where('status', Incident::STATUS_RESOLVED)->get();

        foreach ($resolved as $index => $incident) {
            $technician = $crew[$index % count($crew)];

            $incident->forceFill([
                'technician_id' => $technician->id,
                // Lo externo cobra más que el de planta.
                'cost' => $technician->external ? mt_rand(650, 2400) : mt_rand(120, 850),
            ])->saveQuietly();
        }
    }

    /**
     * Rol de la semana para quienes NO tienen cuenta: camaristas y técnicos
     * de planta. Los externos no entran al rol a propósito — se les llama
     * cuando hace falta, no cubren turno.
     */
    private function seedCrewRoster(): void
    {
        $morning = ShiftType::query()->where('name', 'Matutino')->first();
        $evening = ShiftType::query()->where('name', 'Vespertino')->first();

        if (! $morning || ! $evening) {
            return;
        }

        $byName = fn (string $class, string $name) => $class::query()->where('name', $name)->first();

        // [clase, nombre, turno, días de la semana (0 = lunes)]
        $plan = [
            [Housekeeper::class, 'Rosa Elena Prieto', $morning, [0, 1, 2, 3, 4]],
            [Housekeeper::class, 'Guadalupe Terrazas', $morning, [0, 1, 2, 3, 4]],
            [Housekeeper::class, 'Alma Delia Ríos', $evening, [0, 1, 2, 3, 4]],
            [Housekeeper::class, 'Norma Vázquez', $evening, [2, 3, 4, 5, 6]],
            [Housekeeper::class, 'Brenda Carrasco', $morning, [5, 6]],
            [Technician::class, 'Jesús "Chuy" Barraza', $morning, [0, 1, 2, 3, 4]],
            [Technician::class, 'Iván Domínguez', $evening, [0, 1, 2, 3, 4, 5]],
        ];

        $monday = $this->today->startOfWeek();

        foreach ($plan as [$class, $name, $type, $days]) {
            $person = $byName($class, $name);

            if (! $person) {
                continue;
            }

            foreach ($days as $offset) {
                ShiftAssignment::firstOrCreate(
                    [
                        'assignable_type' => $class,
                        'assignable_id' => $person->id,
                        'shift_type_id' => $type->id,
                        'date' => $monday->addDays($offset)->toDateString(),
                    ],
                    ['property_id' => $this->property->id, 'created_by' => $this->staff['carolina']->id],
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Limpieza: camaristas y su bitácora
    // ------------------------------------------------------------------

    /**
     * Camaristas y tres semanas de limpiezas registradas. Las camaristas
     * NO son usuarios del sistema (tabla aparte, no consumen el límite del
     * plan); la supervisora sí, y por eso lleva user_id.
     *
     * Deja el tablero del día con trabajo real: habitaciones sucias
     * esperando y dos limpiezas con el cronómetro corriendo.
     */
    private function seedHousekeeping(): void
    {
        if (Housekeeper::query()->exists()) {
            return;
        }

        // [nombre, teléfono, activa, notas, ¿tiene cuenta?]
        $people = [
            ['Rosa Elena Prieto', '+52 614 133 8890', true, 'Turno matutino. Cinco años en la casa; es la que entrena a las nuevas.', null],
            ['Guadalupe Terrazas', '+52 614 271 5512', true, 'Turno matutino. Pisos 1 y 2.', null],
            ['Alma Delia Ríos', '+52 614 408 9034', true, 'Turno vespertino. Entra a las 14:00.', null],
            ['Norma Vázquez', '+52 614 592 7761', true, 'Turno vespertino y fines de semana.', null],
            ['Brenda Carrasco', '+52 614 316 2247', true, 'Apoyo de fin de semana y días de lleno.', null],
            ['Marta Chávez', '+52 614 577 8841', true, 'Supervisora de piso; también entra al panel.', 'marta'],
            ['Yolanda Sáenz', '+52 614 224 6618', false, 'Baja en julio. Su historial se conserva.', null],
        ];

        /** @var array<string, Housekeeper> $crew */
        $crew = [];

        foreach ($people as [$name, $phone, $active, $notes, $staffKey]) {
            $crew[$name] = Housekeeper::create([
                'name' => $name,
                'phone' => $phone,
                'active' => $active,
                'notes' => $notes,
                'user_id' => $staffKey ? $this->staff[$staffKey]->id : null,
            ]);
        }

        $rooms = Room::query()->orderBy('number')->get()->values();

        if ($rooms->isEmpty()) {
            return;
        }

        $checklist = collect(app(\App\Services\HousekeepingChecklist::class)->tasks(onlyActive: true))
            ->pluck('key')
            ->all();

        // Quiénes trabajan cada turno: el reparto no es al azar, así el
        // reporte por persona se parece a una plantilla real.
        $morning = [$crew['Rosa Elena Prieto'], $crew['Guadalupe Terrazas'], $crew['Marta Chávez']];
        $evening = [$crew['Alma Delia Ríos'], $crew['Norma Vázquez']];
        $weekend = [$crew['Brenda Carrasco'], $crew['Norma Vázquez'], $crew['Rosa Elena Prieto']];

        // Estancias terminadas, para colgar la limpieza de salida de la
        // estancia que de verdad la ensució: se busca la salida del MISMO
        // día, no la última que hubo en ese cuarto — si no, el detalle
        // mostraría un huésped que se fue tres semanas antes.
        $stays = Stay::query()
            ->where('status', Stay::STATUS_COMPLETED)
            ->whereNotNull('check_out_at')
            ->orderBy('check_out_at')
            ->get()
            ->groupBy('room_id');

        $notes = [
            'Se encontró todo en orden.',
            'Faltaba una toalla; se repuso.',
            'El huésped dejó la habitación muy limpia.',
            'Se reportó el foco del baño a mantenimiento.',
            'Cambio completo de blancos, la cama estaba con manchas de maquillaje.',
            'Se ventiló media hora: olía a cigarro.',
            null,
            null,
            null,
        ];

        // Tres semanas hacia atrás, sin contar hoy (hoy va aparte).
        foreach (range(21, 1) as $daysAgo) {
            $date = $this->today->subDays($daysAgo);
            $isWeekend = in_array($date->dayOfWeek, [0, 6], true);

            // Fin de semana pesa más: más salidas, más cuartos que hacer.
            $howMany = $isWeekend ? mt_rand(4, 6) : mt_rand(2, 4);
            $picked = $rooms->shuffle()->take($howMany);

            foreach ($picked as $index => $room) {
                $evening_shift = $index % 3 === 2;
                $pool = $isWeekend ? $weekend : ($evening_shift ? $evening : $morning);
                $housekeeper = $pool[array_rand($pool)];

                // La mayoría son de salida; el retoque es del huésped que
                // sigue adentro y la profunda cae de vez en cuando.
                $kind = match (true) {
                    mt_rand(1, 100) <= 12 => RoomCleaning::KIND_TOUCHUP,
                    mt_rand(1, 100) <= 8 => RoomCleaning::KIND_DEEP,
                    default => RoomCleaning::KIND_CHECKOUT,
                };

                $minutes = match ($kind) {
                    RoomCleaning::KIND_TOUCHUP => mt_rand(10, 20),
                    RoomCleaning::KIND_DEEP => mt_rand(55, 95),
                    default => mt_rand(22, 48),
                };

                // Si ese día hubo una salida en el cuarto, la limpieza
                // arranca poco después de ella y queda ligada.
                $stay = $kind === RoomCleaning::KIND_CHECKOUT
                    ? ($stays->get($room->id)?->first(fn (Stay $s) => $s->check_out_at?->isSameDay($date)))
                    : null;

                $startedAt = $stay
                    ? CarbonImmutable::instance($stay->check_out_at)->addMinutes(mt_rand(20, 150))
                    : $date->setTime(
                        $evening_shift ? mt_rand(15, 19) : mt_rand(9, 13),
                        mt_rand(0, 59),
                    );

                // Checklist casi siempre completo; a veces queda algo sin
                // marcar, que es lo que el reporte debe poder mostrar.
                $done = $checklist;
                if (mt_rand(1, 100) <= 18 && count($done) > 2) {
                    array_pop($done);
                }

                $cleaning = RoomCleaning::create([
                    'room_id' => $room->id,
                    'housekeeper_id' => $housekeeper->id,
                    'stay_id' => $stay?->id,
                    'kind' => $kind,
                    'started_at' => $startedAt,
                    'ended_at' => $startedAt->addMinutes($minutes),
                    'minutes' => $minutes,
                    'checklist' => $done,
                    'linens' => [
                        'sabanas' => $kind === RoomCleaning::KIND_TOUCHUP ? 0 : mt_rand(1, 2),
                        'toallas' => mt_rand(2, 4),
                    ],
                    'notes' => $notes[array_rand($notes)],
                    // La mayoría se registra desde el plano con cronómetro;
                    // lo capturado después va como manual y con quién lo
                    // escribió.
                    'source' => mt_rand(1, 100) <= 25
                        ? RoomCleaning::SOURCE_MANUAL
                        : RoomCleaning::SOURCE_FLOORPLAN,
                    'recorded_by' => $this->staff['marta']->id,
                ]);

                $this->backdate($cleaning, $startedAt);
            }
        }

        $this->seedHousekeepingToday($crew, $rooms, $checklist);
    }

    /**
     * El día de hoy: lo ya hecho, lo que se está limpiando ahorita (con el
     * cronómetro corriendo) y las que siguen sucias esperando turno.
     *
     * @param  array<string, Housekeeper>  $crew
     * @param  \Illuminate\Support\Collection<int, Room>  $rooms
     * @param  array<int, string>  $checklist
     */
    private function seedHousekeepingToday(array $crew, $rooms, array $checklist): void
    {
        // Las de la mañana ya pasaron: la habitación pudo volver a
        // venderse desde entonces, así que aquí cualquier cuarto sirve.
        foreach ([[0, 'Rosa Elena Prieto', 9, 34], [1, 'Guadalupe Terrazas', 10, 27]] as [$i, $who, $hour, $minutes]) {
            $startedAt = $this->today->setTime($hour, mt_rand(0, 40));
            $cleaning = RoomCleaning::create([
                'room_id' => $rooms[$i]->id,
                'housekeeper_id' => $crew[$who]->id,
                'kind' => RoomCleaning::KIND_CHECKOUT,
                'started_at' => $startedAt,
                'ended_at' => $startedAt->addMinutes($minutes),
                'minutes' => $minutes,
                'checklist' => $checklist,
                'linens' => ['sabanas' => 1, 'toallas' => 3],
                'source' => RoomCleaning::SOURCE_FLOORPLAN,
                'recorded_by' => $this->staff['marta']->id,
            ]);
            $this->backdate($cleaning, $startedAt);
        }

        // Con el cronómetro corriendo. Primero las que YA están en
        // limpieza según el semáforo (ahí el registro es lo que falta);
        // si no alcanzan, se toma alguna disponible y se manda a limpiar.
        $inCleaning = $rooms
            ->filter(fn (Room $room) => $room->status->getMorphClass() === RoomStatus::Cleaning->value)
            ->values();

        $free = $rooms
            ->filter(fn (Room $room) => $room->status->getMorphClass() === RoomStatus::Available->value)
            ->values();

        $working = [$crew['Alma Delia Ríos'], $crew['Norma Vázquez']];

        foreach ([18, 41] as $slot => $agoMinutes) {
            $room = $inCleaning[$slot] ?? $free->shift();

            if (! $room) {
                break;
            }

            $startedAt = CarbonImmutable::instance(now())->subMinutes($agoMinutes);

            $cleaning = RoomCleaning::create([
                'room_id' => $room->id,
                'housekeeper_id' => $working[$slot]->id,
                'kind' => RoomCleaning::KIND_CHECKOUT,
                'started_at' => $startedAt,
                'ended_at' => null,
                'checklist' => [],
                'source' => RoomCleaning::SOURCE_FLOORPLAN,
                'recorded_by' => $this->staff['marta']->id,
            ]);
            $this->backdate($cleaning, $startedAt);

            if ($room->status->getMorphClass() !== RoomStatus::Cleaning->value) {
                $this->setRoomStatus($room, RoomStatus::Cleaning, 'Limpieza en curso', $startedAt, $this->staff['marta']);
            }
        }

        // Y las que salieron y siguen esperando turno: hasta completar
        // tres sucias en el tablero, sin tocar ocupadas ni mantenimiento.
        $dirty = $rooms
            ->filter(fn (Room $room) => $room->status->getMorphClass() === RoomStatus::Dirty->value)
            ->count();

        while ($dirty < 3 && $free->isNotEmpty()) {
            $this->setRoomStatus(
                $free->shift(),
                RoomStatus::Dirty,
                'Check-out',
                CarbonImmutable::instance(now())->subMinutes(mt_rand(25, 110)),
                $this->staff['luis'],
            );
            $dirty++;
        }
    }

    // ------------------------------------------------------------------
    // Menú digital, bandeja, inventario
    // ------------------------------------------------------------------

    private function seedMenuRequests(): void
    {
        if ($this->products === [] || MenuRequest::query()->where('guest_name', 'María Fernanda')->exists()) {
            return;
        }

        $rooms = Room::query()->orderBy('number')->get()->keyBy('number');
        $menuProducts = array_values(array_filter($this->products, fn (Product $p) => $p->available_in_menu));
        if ($menuProducts === []) {
            $menuProducts = $this->products;
        }

        $requests = [
            ['101', 'María Fernanda', MenuRequest::STATUS_PENDING, now()->subMinutes(12), null, null],
            ['102', 'Héctor', MenuRequest::STATUS_PREPARING, now()->subMinutes(28), $this->staff['jorge'], null],
            ['105', 'Ricardo', MenuRequest::STATUS_ATTENDED, now()->subDay()->setTime(21, 5), $this->staff['jorge'], $this->staff['jorge']],
            ['202', 'Lucía', MenuRequest::STATUS_CANCELLED, now()->subDays(2)->setTime(19, 40), null, null],
        ];

        foreach ($requests as [$number, $guestName, $status, $at, $preparer, $attendant]) {
            $count = mt_rand(1, 2);
            $items = [];
            $total = 0.0;
            foreach (range(1, $count) as $i) {
                $product = $menuProducts[array_rand($menuProducts)];
                $qty = mt_rand(1, 2);
                $items[] = [
                    'qty' => $qty,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'product_id' => $product->id,
                ];
                $total += $qty * (float) $product->price;
            }

            $request = MenuRequest::create([
                'property_id' => $this->property->id,
                'room_id' => $rooms->get($number)?->id,
                'room_label' => 'Habitación '.$number,
                'guest_name' => $guestName,
                'items' => $items,
                'total' => $total,
                'payment_mode' => $this->pick([MenuRequest::PAYMENT_ROOM_CHARGE => 60, MenuRequest::PAYMENT_ON_DELIVERY => 40]),
                'payment_method' => 'cash',
                'status' => $status,
                'preparing_by' => $preparer?->id,
                'preparing_at' => $preparer ? $at->addMinutes(4) : null,
                'attended_by' => $attendant?->id,
                'attended_at' => $attendant ? $at->addMinutes(18) : null,
            ]);
            $this->backdate($request, $at);
        }
    }

    private function seedMessaging(): void
    {
        if (Conversation::query()->where('contact_phone', '+52 614 155 2010')->exists()) {
            return;
        }

        $channel = Channel::firstOrCreate([
            'property_id' => $this->property->id,
            'type' => 'whatsapp',
        ], [
            'external_id' => '5216145550100',
            'name' => 'WhatsApp Hotel Test',
            'mode' => 'auto',
            'active' => true,
        ]);

        $threads = [
            [
                'contact' => ['María Fernanda García', '+52 614 155 2010', $this->guests[0]],
                'status' => Conversation::STATUS_OPEN,
                'messages' => [
                    ['in', 'visitor', 'Hola, ya estamos en la habitación 101. ¿A qué hora es la cena de esta noche?', -70],
                    ['out', 'bot', 'Hola María Fernanda, su cena en la terraza está confirmada hoy a las 20:00. Su mesa queda junto al barandal, como lo pidieron.', -69],
                    ['in', 'visitor', 'Perfecto, muchas gracias.', -55],
                ],
            ],
            [
                'contact' => ['Andrés Salinas', '+52 614 890 4432', null],
                'status' => Conversation::STATUS_PENDING,
                'messages' => [
                    ['in', 'visitor', 'Buenas tardes, ¿manejan tarifa especial para un grupo de 6 habitaciones en septiembre?', -140],
                    ['out', 'bot', 'Con gusto lo apoyo. Para cotizar un grupo de ese tamaño lo canalizo con recepción, en un momento le responden por aquí.', -139],
                    ['in', 'visitor', '¿Como a qué hora me podrían llamar?', -35],
                ],
            ],
            [
                'contact' => ['Renata Salcido', '+52 614 377 9012', $this->guests[18]],
                'status' => Conversation::STATUS_RESOLVED,
                'archived' => true,
                'messages' => [
                    ['in', 'visitor', 'Hola, ¿tienen disponible una suite para este viernes?', -4320],
                    ['out', 'bot', 'Hola Renata, sí tenemos suite disponible para el viernes. El total por noche es de $1,400. ¿Le comparto la liga para reservar en línea?', -4319],
                    ['in', 'visitor', 'Sí, por favor.', -4310],
                    ['out', 'bot', 'Aquí la tiene. Cualquier duda quedo al pendiente.', -4309],
                ],
            ],
        ];

        foreach ($threads as $thread) {
            [$name, $phone, $guest] = $thread['contact'];
            $lastOffset = $thread['messages'][count($thread['messages']) - 1][3];
            $lastAt = now()->addMinutes($lastOffset);

            $conversation = Conversation::create([
                'uuid' => (string) Str::uuid(),
                'channel_id' => $channel->id,
                'guest_id' => $guest?->id,
                'contact_name' => $name,
                'contact_phone' => $phone,
                'status' => $thread['status'],
                'bot_enabled' => true,
                'last_message_at' => $lastAt,
                'last_message_preview' => Str::limit($thread['messages'][count($thread['messages']) - 1][2], 80),
                'archived_at' => ($thread['archived'] ?? false) ? $lastAt->addDays(1) : null,
            ]);
            $this->backdate($conversation, now()->addMinutes($thread['messages'][0][3]));

            foreach ($thread['messages'] as [$direction, $senderType, $body, $offsetMinutes]) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => $direction,
                    'sender_type' => $senderType,
                    'body' => $body,
                    'read_at' => $thread['status'] === Conversation::STATUS_RESOLVED || $offsetMinutes < -60
                        ? now()->addMinutes($offsetMinutes + 5)
                        : null,
                    'created_at' => now()->addMinutes($offsetMinutes),
                ]);
            }

            // Los mensajes entrantes reabren la conversación al crearse, así
            // que el estado final (y el archivado) se fijan hasta el final.
            $conversation->forceFill([
                'status' => $thread['status'],
                'archived_at' => ($thread['archived'] ?? false) ? $lastAt->addDay() : null,
                'last_message_at' => $lastAt,
            ])->saveQuietly();
        }
    }

    private function seedStock(): void
    {
        if (StockMovement::query()->exists()) {
            return;
        }

        $tracked = array_slice(array_values(array_filter(
            $this->products,
            fn (Product $p) => in_array($p->category, ['Bebidas', 'Snacks'], true),
        )), 0, 4);

        foreach ($tracked as $product) {
            $qty = (float) mt_rand(60, 140);
            $product->forceFill([
                'track_stock' => true,
                'stock_qty' => $qty,
                'reorder_point' => 24,
            ])->saveQuietly();

            foreach ([14, 7] as $daysAgo) {
                $movement = StockMovement::create([
                    'stockable_type' => Product::class,
                    'stockable_id' => $product->id,
                    'type' => 'purchase',
                    'qty' => 72,
                    'unit_cost' => (float) $product->cost,
                    'notes' => 'Compra semanal de proveedor',
                    'created_by' => $this->staff['carolina']->id,
                    'created_at' => $this->today->subDays($daysAgo)->setTime(10, 30),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Cortes de caja (sobre lo ya sembrado, con el servicio real)
    // ------------------------------------------------------------------

    private function seedCashCuts(): void
    {
        if (CashCut::query()->exists()) {
            return;
        }

        $service = app(CashCutService::class);

        // Recepción: un corte por cada turno cerrado.
        foreach ($this->shiftIndex as $row) {
            if ($row['end'] === null) {
                continue;
            }

            $user = User::find($row['user_id']);
            $shift = Shift::find($row['id']);
            $this->storeCut($service, $user, $row['start'], $row['end'], $shift, CashCut::SCOPE_ROOMS);
        }

        // Punto de venta: corte diario de la barra (Jorge, sin turno formal).
        foreach (range(12, 1) as $daysAgo) {
            $day = $this->today->subDays($daysAgo);
            $this->storeCut($service, $this->staff['jorge'], $day->setTime(11, 0), $day->setTime(23, 30), null, CashCut::SCOPE_POS);
        }
    }

    private function storeCut(CashCutService $service, User $user, CarbonImmutable $from, CarbonImmutable $to, ?Shift $shift, string $scope): void
    {
        $agg = $service->compute($user, $from, $to, $shift, $scope);

        if ($agg['grand_total'] <= 0 && ($agg['guarantees_collected'] ?? 0) <= 0) {
            return;
        }

        $pending = $service->pendingSnapshot($user, $from, $to, $shift, $scope);

        // Arqueo: la mayoría cuadra; a veces hay diferencia pequeña.
        $variance = mt_rand(1, 100) <= 25 ? (float) (mt_rand(-4, 4) * 5) : 0.0;
        $counted = max(0, round($agg['expected_cash'] + $variance, 2));

        Auth::setUser($user);
        $cut = CashCut::create([
            'property_id' => $this->property->id,
            'user_id' => $user->id,
            'shift_id' => $shift?->id,
            'scope' => $scope,
            'opened_at' => $from,
            'closed_at' => $to,
            'orders_count' => $agg['orders_count'],
            'orders_total' => $agg['orders_total'],
            'orders_cost' => $agg['orders_cost'],
            'payments_count' => $agg['payments_count'],
            'payments_total' => $agg['payments_total'],
            'cash_total' => $agg['cash_total'],
            'card_total' => $agg['card_total'],
            'transfer_total' => $agg['transfer_total'],
            'grand_total' => $agg['grand_total'],
            'expected_cash' => $agg['expected_cash'],
            'opening_cash' => $agg['opening_cash'],
            'counted_cash' => $counted,
            'difference' => round($counted - $agg['expected_cash'], 2),
            'pending_count' => $pending['count'],
            'pending_total' => $pending['total'],
            'pending_items' => $pending['items'],
            'notes' => $variance < 0 ? 'Faltante menor; se repone del fondo y se revisa con gerencia.' : null,
            'created_by' => $user->id,
        ]);
        $this->backdate($cut, $to->addMinutes(12));
    }

    // ------------------------------------------------------------------
    // Campana del staff
    // ------------------------------------------------------------------

    private function seedNotifications(): void
    {
        if (StaffNotification::query()->exists()) {
            return;
        }

        $items = [
            [StaffNotification::TYPE_RESERVATION, 'Nueva reserva desde el sitio', 'Entró una reserva web para dentro de 5 días con cupón VERANO26.', '/reservas', now()->subHours(2), null],
            [StaffNotification::TYPE_MENU, 'Nueva solicitud del menú', 'Habitación 101 pidió del menú digital; está pendiente de preparar.', '/menu-digital', now()->subMinutes(12), null],
            [StaffNotification::TYPE_MESSAGE, 'Conversación en espera', 'Andrés Salinas pregunta por tarifa de grupo; el asistente lo canalizó a recepción.', '/asistente', now()->subMinutes(35), null],
            [StaffNotification::TYPE_PAYMENT, 'Pago en línea recibido', 'Se acreditó un anticipo por pasarela para la reserva de la habitación 106.', '/reservas', now()->subDays(1)->setTime(17, 33), now()->subDay()->setTime(18, 10)],
            [StaffNotification::TYPE_SURVEY, 'Encuesta con calificación baja', 'Un huésped calificó su estancia con 2 estrellas; conviene dar seguimiento.', '/encuestas', now()->subDays(3)->setTime(11, 20), now()->subDays(3)->setTime(12, 0)],
        ];

        foreach ($items as [$type, $title, $body, $url, $at, $readAt]) {
            $notification = StaffNotification::create([
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'read_at' => $readAt,
            ]);
            $this->backdate($notification, $at);
        }
    }

    // ------------------------------------------------------------------
    // Utilería
    // ------------------------------------------------------------------

    /** Elección ponderada: [valor => peso]. */
    private function pick(array $weighted): mixed
    {
        $roll = mt_rand(1, (int) array_sum($weighted));
        foreach ($weighted as $value => $weight) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return array_key_first($weighted);
    }

    /** Busca habitación libre del tipo en el rango (fechas de día) y la aparta. */
    private function claimRoom(int $typeId, CarbonImmutable $start, CarbonImmutable $end): ?Room
    {
        $rooms = $this->roomsByType[$typeId] ?? [];
        shuffle($rooms);

        foreach ($rooms as $room) {
            if ($this->isFree($room->id, $start, $end)) {
                $this->claim($room->id, $start, $end);

                return $room;
            }
        }

        return null;
    }

    private function isFree(int $roomId, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($this->booked[$roomId] ?? [] as [$s, $e]) {
            if ($start->toDateString() < $e && $end->toDateString() > $s) {
                return false;
            }
        }

        return true;
    }

    private function claim(int $roomId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $this->booked[$roomId][] = [$start->toDateString(), $end->toDateString()];
    }

    /** Crea un pago fechado (y lo cuelga del turno correspondiente si aplica). */
    private function makePayment(array $attrs, CarbonImmutable $at): Payment
    {
        $payment = Payment::create($attrs + ['paid_at' => $at]);

        return $this->backdate($payment, $at);
    }

    /** Cambia el semáforo de la habitación dejando rastro en la bitácora de estados. */
    private function setRoomStatus(Room $room, RoomStatus $to, string $context, CarbonImmutable $at, ?User $by = null): void
    {
        $from = $room->status;
        $room->forceFill(['status' => $to->value])->saveQuietly();

        $log = RoomStatusLog::create([
            'room_id' => $room->id,
            'from_status' => $from instanceof RoomStatus ? $from->value : (string) $from,
            'to_status' => $to->value,
            'changed_by' => ($by ?? Auth::user())?->id,
            'context' => $context,
        ]);
        $this->backdate($log, $at);
    }

    private function randomLines(int $count): array
    {
        $lines = [];
        $subtotal = 0.0;
        $cost = 0.0;

        foreach (range(1, $count) as $i) {
            $product = $this->products[array_rand($this->products)];
            $qty = mt_rand(1, 3);
            $lines[] = [
                'product_id' => $product->id,
                'qty' => $qty,
                'unit_price' => (float) $product->price,
                'unit_cost' => (float) $product->cost,
                'total' => $qty * (float) $product->price,
            ];
            $subtotal += $qty * (float) $product->price;
            $cost += $qty * (float) $product->cost;
        }

        return [$lines, round($subtotal, 2), round($cost, 2)];
    }

    private function attachLines(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $order->lines()->create($line);
        }
    }

    /** Fecha el registro en el pasado sin disparar eventos ni bitácora extra. */
    private function backdate($model, CarbonImmutable $at)
    {
        $model->forceFill(['created_at' => $at]);
        if ($model->getUpdatedAtColumn() !== null) {
            $model->forceFill([$model->getUpdatedAtColumn() => $at]);
        }
        $model->saveQuietly();

        return $model;
    }

    /**
     * La bitácora se generó "hoy" al sembrar: se re-fecha cada entrada a la
     * fecha real de su sujeto para que /actividad cuente la historia.
     */
    private function backdateActivity(): void
    {
        Activity::query()->with('subject')->chunkById(200, function ($activities) {
            foreach ($activities as $activity) {
                $subject = $activity->subject;
                if ($subject === null) {
                    continue;
                }

                $at = $subject instanceof Payment
                    ? $subject->paid_at
                    : $subject->getAttribute('created_at');

                if ($at === null) {
                    continue;
                }

                $activity->created_at = $at;
                $activity->updated_at = $at;

                // Los cobros de pasarela los registra el webhook, no el staff.
                if ($subject instanceof Payment && $subject->method === Payment::METHOD_ONLINE) {
                    $activity->causer_type = null;
                    $activity->causer_id = null;
                }

                $activity->saveQuietly();
            }
        });
    }
}
