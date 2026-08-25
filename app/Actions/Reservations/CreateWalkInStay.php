<?php

namespace App\Actions\Reservations;

use App\Actions\Rooms\ChangeRoomStatus;
use App\Enums\RoomStatus;
use App\Exceptions\NoAvailabilityException;
use App\Models\Guest;
use App\Models\RatePlan;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Walk-in: ocupación inmediata sin reserva previa (flujo motel / mostrador).
 */
class CreateWalkInStay
{
    public function __construct(
        protected AvailabilityService $availability,
        protected ChangeRoomStatus $changeRoomStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws NoAvailabilityException
     */
    public function handle(array $data, ?User $user = null): Stay
    {
        $ratePlan = RatePlan::findOrFail($data['rate_plan_id']);
        $start = now();
        $end = isset($data['planned_end_at']) && $data['planned_end_at']
            ? Carbon::parse($data['planned_end_at'])
            : $ratePlan->suggestedEnd($start);

        return DB::transaction(function () use ($data, $ratePlan, $start, $end, $user) {
            $room = Room::whereKey($data['room_id'])
                ->where('room_type_id', $ratePlan->room_type_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($room->status->getMorphClass() !== RoomStatus::Available->value
                || ! $this->availability->isRoomAvailable($room, $start, $end)) {
                throw NoAvailabilityException::forRoom($room->number);
            }

            // Techo real de la habitación, igual que CreateReservation: el
            // walk-in era el único canal que no lo hacía cumplir.
            $capacity = $room->effectiveMaxOccupancy();
            $people = max(1, (int) ($data['num_people'] ?? 1));
            if ($capacity !== null && $people > $capacity) {
                throw NoAvailabilityException::exceedsCapacity($room->number, $capacity);
            }

            $guest = null;
            if (! empty($data['guest_id'])) {
                $guest = Guest::findOrFail($data['guest_id']);
            } elseif (! empty($data['guest_phone'])) {
                $guest = Guest::firstOrCreate(
                    ['phone' => $data['guest_phone']],
                    [
                        'first_name' => $data['guest_name'] ?? null,
                        'email' => $data['guest_email'] ?? null,
                    ],
                );
            }

            // La ficha del CRM se enriquece de paso, sin pisar lo que ya
            // tenga: correo capturado en mostrador y documento del exprés.
            if ($guest) {
                $guest->fill(array_filter([
                    'email' => $guest->email ? null : ($data['guest_email'] ?? null),
                    'id_document_type' => $guest->id_document_number ? null : ($data['id_document_type'] ?? null),
                    'id_document_number' => $guest->id_document_number ? null : ($data['id_document_number'] ?? null),
                ]));
                if ($guest->isDirty()) {
                    $guest->save();
                }
            }

            // Registro de vehículos (caseta): la placa gana ficha propia con
            // marca y modelo, y la estancia queda ligada. Si lo tecleado no
            // puede ser una placa, devuelve null y no se crea nada.
            $vehicle = app(\App\Services\VehicleRegistry::class)->resolve($data, $guest);

            // Cargos extra de la ficha: personas sobre las incluidas +
            // cargos opcionales elegidos (mascota, decoración…).
            $extraCharges = $room->extraChargeLines(
                max(1, (int) ($data['num_people'] ?? 1)),
                $ratePlan->unitsFor($start, $end),
                $data['extra_charges'] ?? [],
            );

            $stay = Stay::create([
                'room_id' => $room->id,
                'rate_plan_id' => $ratePlan->id,
                'guest_id' => $guest?->id,
                'guest_name' => $data['guest_name'] ?? $guest?->full_name,
                'num_people' => $data['num_people'] ?? 1,
                // En mayúsculas: las placas se escriben así y el histórico se
                // lee mejor parejo. El panel ya lo hacía en el cliente; aquí
                // queda garantizado venga de donde venga la llamada.
                'vehicle_plate' => isset($data['vehicle_plate']) && $data['vehicle_plate'] !== ''
                    ? mb_strtoupper(trim($data['vehicle_plate']))
                    : null,
                'vehicle_desc' => $data['vehicle_desc'] ?? null,
                'vehicle_id' => $vehicle?->id,
                // Cómo llegaron, tal como lo eligió quien atendió: al
                // completar el registro no se vuelve a preguntar.
                'arrival_mode' => in_array($data['arrival_mode'] ?? null, ['vehicle', 'foot'], true)
                    ? $data['arrival_mode']
                    : null,
                'id_document_type' => $data['id_document_type'] ?? null,
                'id_document_number' => $data['id_document_number'] ?? null,
                // Caseta de motel en dos momentos: con `arrival_pending` la
                // llegada nace sin sellar — el acceso se abre ya, y los datos
                // del carro y el cobro se completan cuando el encargado
                // regresa con el papel. Sin la bandera todo sigue igual: la
                // llegada se captura completa de una vez.
                'arrival_completed_at' => ! empty($data['arrival_pending']) ? null : now(),
                'check_in_at' => $start,
                'planned_end_at' => $end,
                'status' => Stay::STATUS_ACTIVE,
                'amount' => round(
                    $ratePlan->priceFor($start, $end, $room) + array_sum(array_column($extraCharges, 'amount')),
                    2,
                ),
                'extra_charges' => $extraCharges ?: null,
                'channel' => 'walk_in',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user?->id,
            ]);

            // Cobro al registrar la llegada (walkin_charge=checkin en
            // /ajustes/metodos-pago): el hospedaje queda pagado desde el
            // inicio y entra al corte del cobrador; al salir solo consumos.
            if (! empty($data['payment_method'])) {
                $stay->payments()->create([
                    'amount' => $stay->amount,
                    'method' => $data['payment_method'],
                    'kind' => \App\Models\Payment::KIND_LODGING,
                    'reference' => $data['payment_reference'] ?? null,
                    'notes' => 'Hospedaje cobrado al registrar la llegada',
                    'received_by' => $user?->id,
                    'paid_at' => now(),
                    'created_at' => now(),
                ]);
            }

            // Fianza (depósito en garantía, /ajustes/metodos-pago): monto
            // fijo del ajuste — NUNCA del cliente — cobrado con método
            // presencial. No es ingreso: kind 'guarantee' la deja fuera del
            // folio y de los totales de venta; se devuelve al check-out.
            $policy = app(\App\Services\ReservationPolicy::class);
            if (! empty($data['guarantee_method']) && $policy->guaranteeEnabled()) {
                $stay->payments()->create([
                    'amount' => $policy->guaranteeAmount(),
                    'method' => $data['guarantee_method'],
                    'kind' => \App\Models\Payment::KIND_GUARANTEE,
                    'notes' => 'Fianza (depósito en garantía) cobrada al registrar la llegada',
                    'received_by' => $user?->id,
                    'paid_at' => now(),
                    'created_at' => now(),
                ]);
            }

            $this->changeRoomStatus->handle($room, RoomStatus::Occupied->value, $user, [
                'stay_id' => $stay->id,
            ]);

            return $stay;
        });
    }
}
