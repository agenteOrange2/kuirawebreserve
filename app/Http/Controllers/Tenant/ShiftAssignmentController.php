<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\ShiftAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Rol semanal: quién trabaja qué turno y qué día. Programa por igual al
 * personal del panel, a las camaristas y a los técnicos — que no tienen
 * cuenta pero sí horario.
 */
class ShiftAssignmentController extends Controller
{
    /**
     * Sincroniza los turnos de UNA persona en un día: recibe la lista de
     * tipos que le tocan esa fecha y agrega/quita lo necesario.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:user,housekeeper,technician'],
            'assignable_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'shift_type_ids' => ['present', 'array'],
            'shift_type_ids.*' => ['exists:shift_types,id'],
        ]);

        $class = ShiftAssignment::classFor($data['kind']);

        // El id tiene que existir EN SU tabla: sin esto se podría
        // programar a una camarista inexistente pasando cualquier número.
        if ($class === null || ! $class::query()->whereKey($data['assignable_id'])->exists()) {
            return response()->json(['message' => 'Esa persona ya no existe.'], 422);
        }

        $propertyId = Property::firstOrFail()->id;
        $date = Carbon::parse($data['date'])->toDateString();

        $owner = fn ($query) => $query
            ->where('assignable_type', $class)
            ->where('assignable_id', $data['assignable_id']);

        // Quita lo que ya no está…
        $owner(ShiftAssignment::query())
            ->whereDate('date', $date)
            ->whereNotIn('shift_type_id', $data['shift_type_ids'])
            ->delete();

        // …y agrega lo nuevo. La existencia se pregunta con whereDate a
        // propósito: la columna guarda fecha, pero el cast serializa con
        // hora, y un firstOrCreate por igualdad no encuentra lo que ya
        // está (y revienta contra el único).
        foreach ($data['shift_type_ids'] as $typeId) {
            $exists = $owner(ShiftAssignment::query())
                ->where('shift_type_id', $typeId)
                ->whereDate('date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            ShiftAssignment::create([
                'property_id' => $propertyId,
                'assignable_type' => $class,
                'assignable_id' => $data['assignable_id'],
                'shift_type_id' => $typeId,
                'date' => $date,
                'created_by' => $request->user()?->id,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Copia el rol de la semana anterior a la semana indicada (no duplica
     * lo que ya exista).
     */
    public function copyWeek(Request $request): JsonResponse
    {
        $data = $request->validate([
            'week_start' => ['required', 'date'],
        ]);

        $propertyId = Property::firstOrFail()->id;
        $target = Carbon::parse($data['week_start'])->startOfWeek();
        $source = $target->copy()->subWeek();

        $assignments = ShiftAssignment::query()
            ->whereBetween('date', [$source, $source->copy()->endOfWeek()])
            ->get();

        $copied = 0;
        foreach ($assignments as $assignment) {
            $date = $assignment->date->copy()->addWeek()->toDateString();

            $exists = ShiftAssignment::query()
                ->where('assignable_type', $assignment->assignable_type)
                ->where('assignable_id', $assignment->assignable_id)
                ->where('shift_type_id', $assignment->shift_type_id)
                ->whereDate('date', $date)
                ->exists();

            if ($exists) {
                continue;
            }

            ShiftAssignment::create([
                'property_id' => $propertyId,
                'assignable_type' => $assignment->assignable_type,
                'assignable_id' => $assignment->assignable_id,
                'shift_type_id' => $assignment->shift_type_id,
                'date' => $date,
                'created_by' => $request->user()?->id,
            ]);
            $copied++;
        }

        return response()->json(['copied' => $copied]);
    }
}
