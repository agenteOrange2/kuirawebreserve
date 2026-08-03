<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RoomStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Room;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área AISLADA de la operación del día (/ajustes/limpieza): check-in
 * automático a la hora de llegada, cómo avanza el semáforo sucia → en
 * limpieza → disponible (a mano, por tiempo o ambos) y qué pasa con una
 * habitación "reservada" cuya salida venció sin check-in registrado.
 * Config con superficie propia = página propia, misma regla que métodos
 * de pago y wizard.
 */
class HousekeepingSettingsPageController extends Controller
{
    public function __invoke(): Response
    {
        $property = Property::firstOrFail();
        $settings = $property->settings ?? [];

        return Inertia::render('tenant/settings/Housekeeping', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                'checkin_mode' => $settings['checkin_mode'] ?? 'manual',
                'hk_mode' => $settings['hk_mode'] ?? 'manual',
                'hk_dirty_value' => (int) ($settings['hk_dirty_value'] ?? 30),
                'hk_dirty_unit' => $settings['hk_dirty_unit'] ?? 'minute',
                'hk_cleaning_value' => (int) ($settings['hk_cleaning_value'] ?? 45),
                'hk_cleaning_unit' => $settings['hk_cleaning_unit'] ?? 'minute',
                'day_close_no_checkin' => $settings['day_close_no_checkin'] ?? 'dirty',
            ],
            // Contexto vivo para que el ajuste no sea abstracto: cuántas
            // habitaciones están ahora mismo en cada tramo del flujo.
            'roomCounts' => [
                'reserved' => Room::query()->where('status', RoomStatus::Reserved->value)->count(),
                'dirty' => Room::query()->where('status', RoomStatus::Dirty->value)->count(),
                'cleaning' => Room::query()->where('status', RoomStatus::Cleaning->value)->count(),
            ],
            // Llegadas confirmadas de hoy: las que tocaría el check-in
            // automático cuando les llegue su hora.
            'arrivalsToday' => \App\Models\Reservation::query()
                ->where('status', \App\Enums\ReservationStatus::Confirmed)
                ->whereNotNull('room_id')
                ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
                ->count(),
        ]);
    }
}
