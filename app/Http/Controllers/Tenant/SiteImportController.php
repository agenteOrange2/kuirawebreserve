<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\RateDurationUnit;
use App\Enums\RatePlanType;
use App\Http\Controllers\Controller;
use App\Models\SiteImportSuggestion;
use App\Services\Integration\SiteImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agente importador (spec-integracion-sitios §4): analiza la página de
 * habitaciones del hotel y llena la cola de sugerencias; aplicar y
 * descartar son SIEMPRE decisión humana. Al aplicar, el precio (si el
 * humano confirma uno en este paso) puede crear la tarifa base — pero
 * SOLO si el tipo aún no tiene ninguna tarifa activa.
 */
class SiteImportController extends Controller
{
    public function store(Request $request, SiteImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
        ]);

        try {
            $created = $importer->import($data['url'], $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'created' => $created,
            'suggestions' => $this->pending(),
        ], 201);
    }

    public function apply(Request $request, SiteImportSuggestion $suggestion): JsonResponse
    {
        abort_unless($suggestion->status === SiteImportSuggestion::STATUS_PENDING, 409, 'La sugerencia ya fue atendida.');

        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0.01'],
            'rate_type' => ['required_with:price', Rule::enum(RatePlanType::class)],
            'duration_unit' => ['required_if:rate_type,block', 'nullable', Rule::enum(RateDurationUnit::class)],
            'duration_value' => ['required_if:rate_type,block', 'nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $rate = ! empty($data['price']) ? $data : null;

        $type = $suggestion->apply($request->user()?->id, $rate);

        return response()->json([
            'room_type' => $type->only(['id', 'name', 'active']),
            // Habitación física: número si se creó, motivo si se omitió
            // (límite del plan) o ambos null si ya existía (no se duplicó).
            'room_number' => $suggestion->createdRoomNumber,
            'room_skipped_reason' => $suggestion->roomSkippedReason,
            'suggestions' => $this->pending(),
        ]);
    }

    public function discard(SiteImportSuggestion $suggestion): JsonResponse
    {
        abort_unless($suggestion->status === SiteImportSuggestion::STATUS_PENDING, 409, 'La sugerencia ya fue atendida.');

        $suggestion->update(['status' => SiteImportSuggestion::STATUS_DISCARDED]);

        return response()->json(['suggestions' => $this->pending()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function pending(): array
    {
        return SiteImportSuggestion::query()
            ->with('roomType:id,name')
            ->where('status', SiteImportSuggestion::STATUS_PENDING)
            ->latest()
            ->get()
            ->map(fn (SiteImportSuggestion $s) => [
                'id' => $s->id,
                'source_url' => $s->source_url,
                'action' => $s->action,
                'room_type' => $s->roomType?->name,
                'payload' => $s->payload,
                // El formulario de aprobación solo ofrece capturar precio
                // cuando el destino AÚN no vende con una tarifa (si ya
                // tiene, ese precio nunca se toca).
                'target_has_rate' => $s->roomType?->hasActiveRate() ?? false,
                'created_at' => $s->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }
}
