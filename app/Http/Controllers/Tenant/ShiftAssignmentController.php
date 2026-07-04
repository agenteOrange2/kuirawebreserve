<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\ShiftAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ShiftAssignmentController extends Controller
{
    /**
     * Sincroniza los turnos de un usuario en un día: recibe la lista de
     * tipos que le tocan esa fecha y agrega/quita lo necesario.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'date' => ['required', 'date'],
            'shift_type_ids' => ['present', 'array'],
            'shift_type_ids.*' => ['exists:shift_types,id'],
        ]);

        $propertyId = Property::firstOrFail()->id;
        $date = Carbon::parse($data['date'])->toDateString();

        // Quita lo que ya no está…
        ShiftAssignment::query()
            ->where('user_id', $data['user_id'])
            ->whereDate('date', $date)
            ->whereNotIn('shift_type_id', $data['shift_type_ids'])
            ->delete();

        // …y agrega lo nuevo.
        foreach ($data['shift_type_ids'] as $typeId) {
            ShiftAssignment::firstOrCreate(
                ['user_id' => $data['user_id'], 'shift_type_id' => $typeId, 'date' => $date],
                ['property_id' => $propertyId, 'created_by' => $request->user()?->id],
            );
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
            $created = ShiftAssignment::firstOrCreate(
                [
                    'user_id' => $assignment->user_id,
                    'shift_type_id' => $assignment->shift_type_id,
                    'date' => $assignment->date->copy()->addWeek()->toDateString(),
                ],
                ['property_id' => $propertyId, 'created_by' => $request->user()?->id],
            );
            if ($created->wasRecentlyCreated) {
                $copied++;
            }
        }

        return response()->json(['copied' => $copied]);
    }
}
