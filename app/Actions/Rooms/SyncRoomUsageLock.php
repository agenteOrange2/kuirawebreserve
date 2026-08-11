<?php

namespace App\Actions\Rooms;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Models\User;

/**
 * Mantiene coherente el candado por usos de una habitación: bloquea cuando
 * el contador alcanza el límite configurado y desbloquea cuando el contador
 * vuelve a quedar por debajo (o el límite se quita). Salvaguarda: el candado
 * nunca se aplica si dejaría al tipo sin ninguna habitación usable — en ese
 * caso solo queda aviso en bitácora y la habitación sigue vendible.
 */
class SyncRoomUsageLock
{
    public const LOCKED = 'locked';

    public const UNLOCKED = 'unlocked';

    public const SKIPPED = 'skipped';

    public const UNCHANGED = 'unchanged';

    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(Room $room, ?User $by = null, array $context = []): string
    {
        $shouldLock = $room->usage_limit !== null && $room->usage_count >= $room->usage_limit;

        if ($shouldLock && ! $room->usageLocked()) {
            if (! $this->hasUsableSiblings($room)) {
                activity('room')
                    ->performedOn($room)
                    ->causedBy($by)
                    ->withProperties([
                        'usage_count' => $room->usage_count,
                        'usage_limit' => $room->usage_limit,
                    ] + $context)
                    ->log(sprintf(
                        'La %s llegó a %d usos (límite %d) pero es la última de su tipo en servicio: el candado no se aplicó',
                        $room->number,
                        $room->usage_count,
                        $room->usage_limit,
                    ));

                return self::SKIPPED;
            }

            // saveQuietly: el rastro útil es la línea descriptiva de abajo,
            // no un "updated" genérico duplicado del LogsActivity del modelo.
            $room->forceFill(['usage_locked_at' => now()])->saveQuietly();

            activity('room')
                ->performedOn($room)
                ->causedBy($by)
                ->withProperties([
                    'usage_count' => $room->usage_count,
                    'usage_limit' => $room->usage_limit,
                ] + $context)
                ->log(sprintf(
                    'Candado por usos: la %s alcanzó %d usos (límite %d) y salió de disponibilidad',
                    $room->number,
                    $room->usage_count,
                    $room->usage_limit,
                ));

            return self::LOCKED;
        }

        if (! $shouldLock && $room->usageLocked()) {
            $room->forceFill(['usage_locked_at' => null])->saveQuietly();

            activity('room')
                ->performedOn($room)
                ->causedBy($by)
                ->withProperties([
                    'usage_count' => $room->usage_count,
                    'usage_limit' => $room->usage_limit,
                ] + $context)
                ->log(sprintf('Candado por usos retirado: la %s vuelve a disponibilidad', $room->number));

            return self::UNLOCKED;
        }

        return self::UNCHANGED;
    }

    /**
     * Reset explícito (botón de recepción): contador a cero y candado fuera.
     */
    public function reset(Room $room, ?User $by = null): Room
    {
        $wasLocked = $room->usageLocked();
        $previous = (int) $room->usage_count;

        $room->forceFill(['usage_count' => 0, 'usage_locked_at' => null])->saveQuietly();

        activity('room')
            ->performedOn($room)
            ->causedBy($by)
            ->withProperties(['previous_count' => $previous, 'usage_limit' => $room->usage_limit])
            ->log(sprintf(
                $wasLocked
                    ? 'Contador de usos de la %s reseteado (llevaba %d) y candado retirado'
                    : 'Contador de usos de la %s reseteado (llevaba %d)',
                $room->number,
                $previous,
            ));

        return $room;
    }

    /**
     * ¿Queda al menos otra habitación del mismo tipo sin candado y fuera de
     * mantenimiento? Si no, bloquear esta mataría la venta del tipo entero.
     */
    protected function hasUsableSiblings(Room $room): bool
    {
        return Room::query()
            ->where('room_type_id', $room->room_type_id)
            ->whereKeyNot($room->id)
            ->whereNull('usage_locked_at')
            ->where('status', '!=', RoomStatus::Maintenance->value)
            ->exists();
    }
}
