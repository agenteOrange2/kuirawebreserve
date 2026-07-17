<?php

namespace App\Actions\Reservations;

use App\Exceptions\NoAvailabilityException;
use App\Models\Property;
use App\Models\ReservationGroup;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reserva grupal (módulo `grupos`): varias habitaciones de un jalón, TODO
 * O NADA — si alguna línea no tiene disponibilidad, no se crea ninguna
 * (un grupo a medias es peor que ninguno: deja gente sin cuarto y cuartos
 * bloqueados). Cada habitación pasa por CreateReservation, o sea por los
 * mismos locks anti-doble-venta, precios de servidor y política de
 * anticipos de siempre; el grupo solo las envuelve bajo un folio GRP-.
 */
class CreateGroupReservation
{
    public function __construct(
        protected CreateReservation $createReservation,
        protected \App\Actions\Experiences\CreateExperienceBooking $createExperienceBooking,
    ) {}

    /**
     * @param  array{
     *     mode: string,
     *     starts_at: mixed,
     *     ends_at?: mixed,
     *     guest_name: string,
     *     guest_phone?: ?string,
     *     guest_email?: ?string,
     *     notes?: ?string,
     *     confirmed?: bool,
     *     source_channel?: string,
     *     lines: array<int, array{room_type_id: int, rooms: int, adults?: int, children?: int}>,
     *     experiences?: array<int, array{session_id: int, people: int}>
     * }  $data
     *
     * @throws NoAvailabilityException
     */
    public function handle(array $data, ?User $user = null): ReservationGroup
    {
        $lines = array_values($data['lines'] ?? []);

        if (count($lines) === 0) {
            throw new InvalidArgumentException('Un grupo necesita al menos una habitación.');
        }

        $totalRooms = array_sum(array_column($lines, 'rooms'));

        if ($totalRooms < 2) {
            throw new InvalidArgumentException('Un grupo son dos habitaciones o más; para una sola usa la reserva normal.');
        }

        if ($totalRooms > 30) {
            throw new InvalidArgumentException('Máximo 30 habitaciones por grupo.');
        }

        return DB::transaction(function () use ($data, $lines, $user) {
            $group = ReservationGroup::create([
                'property_id' => Property::firstOrFail()->id,
                'guest_name' => $data['guest_name'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            $guestId = null;

            foreach ($lines as $line) {
                $type = RoomType::query()->where('active', true)->findOrFail($line['room_type_id']);

                // Misma resolución que el wizard: la tarifa activa más
                // barata de la modalidad pedida para ese tipo.
                $ratePlan = $type->ratePlans()
                    ->where('active', true)
                    ->where('type', $data['mode'])
                    ->orderBy('price')
                    ->first();

                if (! $ratePlan) {
                    throw new InvalidArgumentException("El tipo {$type->name} no tiene tarifa activa en esa modalidad.");
                }

                // Por noche, cada tipo puede tener sus propios horarios de
                // entrada/salida (Catálogo → Horarios; fallback: el hotel).
                $lineStart = \Carbon\Carbon::parse($data['starts_at']);
                $lineEnd = ! empty($data['ends_at']) ? \Carbon\Carbon::parse($data['ends_at']) : null;

                if (($data['mode'] ?? null) === 'night') {
                    [[$inHour, $inMinute], [$outHour, $outMinute]] = $type->effectiveScheduleTimes();
                    $lineStart = $lineStart->copy()->setTime($inHour, $inMinute);
                    $lineEnd = $lineEnd?->copy()->setTime($outHour, $outMinute);
                }

                for ($i = 0; $i < (int) $line['rooms']; $i++) {
                    // NoAvailabilityException aquí revienta la transacción
                    // completa: ninguna reserva del grupo sobrevive.
                    $reservation = $this->createReservation->handle([
                        'rate_plan_id' => $ratePlan->id,
                        'starts_at' => $lineStart,
                        'ends_at' => $lineEnd,
                        'confirmed' => (bool) ($data['confirmed'] ?? false),
                        'source_channel' => $data['source_channel'] ?? 'front_desk',
                        'guest_name' => $data['guest_name'],
                        'guest_phone' => $data['guest_phone'] ?? null,
                        'guest_email' => $data['guest_email'] ?? null,
                        'adults' => $line['adults'] ?? 1,
                        'children' => $line['children'] ?? 0,
                        'notes' => $data['notes'] ?? null,
                    ], $user);

                    $reservation->forceFill(['reservation_group_id' => $group->id])->saveQuietly();
                    $guestId ??= $reservation->guest_id;
                }
            }

            // Experiencias como plus del grupo: cuelgan del GRP- (no de un
            // cuarto) y suman al total consolidado. Mismo cupo duro bajo
            // lock; sin cupo, revienta el grupo completo — todo o nada.
            foreach (array_values($data['experiences'] ?? []) as $line) {
                $this->createExperienceBooking->handle([
                    'experience_session_id' => $line['session_id'] ?? 0,
                    'people' => $line['people'] ?? 1,
                    'reservation_group_id' => $group->id,
                    'guest_id' => $guestId,
                    'guest_name' => $data['guest_name'],
                    'confirmed' => (bool) ($data['confirmed'] ?? false),
                ], $user);
            }

            $group->forceFill([
                'code' => ReservationGroup::formatCode($group->id, $group->created_at),
                'guest_id' => $guestId,
            ])->saveQuietly();

            return $group;
        });
    }
}
