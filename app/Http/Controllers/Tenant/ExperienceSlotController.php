<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Experiences\GenerateExperienceSessions;
use App\Http\Controllers\Controller;
use App\Models\Experience;
use App\Models\ExperienceBooking;
use App\Models\ExperienceSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Horarios recurrentes de una experiencia (la programación semanal junto
 * con operating_days). Cada mutación regenera las sesiones futuras al
 * momento — el hotel ve el efecto de su cambio sin esperar al rodillo
 * diario.
 */
class ExperienceSlotController extends Controller
{
    public function store(Request $request, Experience $experience, GenerateExperienceSessions $generator): JsonResponse
    {
        $data = $request->validate($this->rules());

        $slot = $experience->slots()->create($data);

        if ($error = $this->capacityError($slot)) {
            $slot->delete();

            return response()->json(['message' => $error], 422);
        }

        $generator->handle($experience);

        return response()->json(self::serialize($slot->fresh()), 201);
    }

    public function update(Request $request, Experience $experience, ExperienceSlot $slot, GenerateExperienceSessions $generator): JsonResponse
    {
        abort_unless($slot->experience_id === $experience->id, 404);

        $data = $request->validate($this->rules(partial: true));

        $original = $slot->getOriginal();
        $slot->update($data);

        if ($error = $this->capacityError($slot)) {
            $slot->update(array_intersect_key($original, array_flip(['start_time', 'vehicle_ids', 'capacity', 'active'])));

            return response()->json(['message' => $error], 422);
        }

        $generator->handle($experience);

        return response()->json(self::serialize($slot->fresh()));
    }

    public function destroy(Experience $experience, ExperienceSlot $slot, GenerateExperienceSessions $generator): JsonResponse
    {
        abort_unless($slot->experience_id === $experience->id, 404);

        // Sesiones futuras del horario sin gente: se van con él. Las que ya
        // tienen reservas sobreviven sueltas (FK nullOnDelete) — avisar o
        // cancelar es decisión humana, igual que en las sesiones manuales.
        $slot->sessions()
            ->where('starts_at', '>', now())
            ->whereDoesntHave('bookings', fn ($q) => $q->whereIn('status', [
                ExperienceBooking::STATUS_PENDING, ExperienceBooking::STATUS_CONFIRMED,
            ]))
            ->delete();

        $slot->delete();

        $generator->handle($experience);

        return response()->json(status: 204);
    }

    /**
     * Un horario debe poder vender algo: vehículos vivos o cupo manual.
     */
    protected function capacityError(ExperienceSlot $slot): ?string
    {
        if ($slot->effectiveCapacity() < 1) {
            return 'El horario necesita al menos un vehículo activo o un cupo manual.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'start_time' => [$presence, 'date_format:H:i'],
            'vehicle_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'vehicle_ids.*' => ['integer', Rule::exists('experience_vehicles', 'id')],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public static function serialize(ExperienceSlot $slot): array
    {
        return [
            'id' => $slot->id,
            'experience_id' => $slot->experience_id,
            'start_time' => $slot->start_time,
            'vehicle_ids' => $slot->vehicle_ids ?? [],
            'capacity' => $slot->capacity,
            'effective_capacity' => $slot->effectiveCapacity(),
            'active' => $slot->active,
        ];
    }
}
