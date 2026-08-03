<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GuestController extends Controller
{
    /** Colecciones de fotos permitidas (documento INE y vehículo). */
    private const COLLECTIONS = ['documents', 'vehicle'];

    /**
     * Autocompletado para reservas/walk-in: pocos resultados, con señales
     * (visitas, blacklist) para que el staff decida.
     */
    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $guests = Guest::query()
            ->search($term)
            ->withCount(['stays as visits' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('updated_at')
            ->take(8)
            ->get()
            ->map(fn (Guest $guest) => [
                'id' => $guest->id,
                'full_name' => $guest->full_name,
                'phone' => $guest->phone,
                'email' => $guest->email,
                'visits' => $guest->visits,
                'is_blacklisted' => $guest->is_blacklisted,
                'blacklist_reason' => $guest->blacklist_reason,
            ]);

        return response()->json($guests);
    }

    public function store(Request $request): JsonResponse
    {
        $guest = Guest::create($this->validated($request));

        return response()->json($guest, 201);
    }

    public function update(Request $request, Guest $guest): JsonResponse
    {
        $guest->update($this->validated($request, $guest));

        return response()->json($guest->refresh());
    }

    /**
     * Con historial (reservas/estancias) se ARCHIVA: desaparece del
     * directorio y del autocompletado, pero el historial lo sigue mostrando
     * y puede restaurarse. Sin historial se elimina definitivamente. Sobre
     * un huésped YA archivado la petición es la purga definitiva: su
     * historial se conserva con guest_id en null (FKs nullOnDelete).
     */
    public function destroy(Guest $guest): JsonResponse
    {
        if (! $guest->trashed() && ($guest->reservations()->exists() || $guest->stays()->exists())) {
            $guest->delete();

            return response()->json([
                'archived' => true,
                'message' => 'Huésped archivado: tiene historial, así que se ocultó del directorio. Puedes restaurarlo desde el filtro Archivados.',
            ]);
        }

        $this->purge($guest);

        return response()->json(status: 204);
    }

    /** Borra ficha y fotos sin vuelta atrás. */
    private function purge(Guest $guest): void
    {
        $guest->clearMediaCollection('documents');
        $guest->clearMediaCollection('vehicle');
        $guest->forceDelete();
    }

    /** Regresa al directorio un huésped archivado. */
    public function restore(Guest $guest): JsonResponse
    {
        $guest->restore();

        return response()->json($guest->refresh());
    }

    /**
     * Borrado en masa: elimina definitivamente los huéspedes SIN historial;
     * los que tienen reservas/estancias se archivan (restaurables). Los que
     * ya estaban archivados se purgan definitivamente.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $deleted = 0;
        $archived = 0;

        foreach (Guest::query()->withTrashed()->whereIn('id', $data['ids'])->get() as $guest) {
            if (! $guest->trashed() && ($guest->reservations()->exists() || $guest->stays()->exists())) {
                $guest->delete();
                $archived++;

                continue;
            }

            $this->purge($guest);
            $deleted++;
        }

        return response()->json(['deleted' => $deleted, 'archived' => $archived]);
    }

    /**
     * Sube una foto (INE frente/reverso o vehículo) al disco privado.
     */
    public function storeDocument(Request $request, Guest $guest): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'image', 'max:8192'],
            'collection' => ['nullable', Rule::in(self::COLLECTIONS)],
        ]);

        $collection = $validated['collection'] ?? 'documents';
        $guest->addMediaFromRequest('file')->toMediaCollection($collection);

        return response()->json(self::media($guest->refresh(), $collection), 201);
    }

    public function showDocument(Guest $guest, Media $media): BinaryFileResponse
    {
        abort_unless(
            $media->model_type === $guest->getMorphClass()
            && (int) $media->model_id === $guest->id
            && in_array($media->collection_name, self::COLLECTIONS, true),
            404,
        );

        return response()->file($media->getPath());
    }

    public function destroyDocument(Guest $guest, Media $media): JsonResponse
    {
        abort_unless(
            (int) $media->model_id === $guest->id && in_array($media->collection_name, self::COLLECTIONS, true),
            404,
        );

        $collection = $media->collection_name;
        $media->delete();

        return response()->json(self::media($guest->refresh(), $collection));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function media(Guest $guest, string $collection): array
    {
        return $guest->getMedia($collection)->map(fn (Media $media) => [
            'id' => $media->id,
            'name' => $media->file_name,
            'url' => route('tenant.guests.documents.show', [$guest, $media]),
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function documents(Guest $guest): array
    {
        return self::media($guest->refresh(), 'documents');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, ?Guest $guest = null): array
    {
        $data = $request->validate([
            'first_name' => [$guest ? 'sometimes' : 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:10'],
            'id_document_type' => ['nullable', Rule::in(Guest::DOCUMENT_TYPES)],
            'id_document_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_blacklisted' => ['sometimes', 'boolean'],
            'blacklist_reason' => ['nullable', 'string', 'max:255', 'required_if:is_blacklisted,true'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'vehicle' => ['sometimes', 'nullable', 'array'],
            'vehicle.plate' => ['nullable', 'string', 'max:20'],
            'vehicle.brand' => ['nullable', 'string', 'max:50'],
            'vehicle.model' => ['nullable', 'string', 'max:50'],
            'vehicle.color' => ['nullable', 'string', 'max:40'],
            'vehicle.year' => ['nullable', 'integer', 'min:1950', 'max:'.((int) date('Y') + 1)],
            'vehicle.notes' => ['nullable', 'string', 'max:255'],
        ]);

        // El vehículo vive en meta.vehicle (sin campos vacíos).
        if ($request->has('vehicle')) {
            $vehicle = array_filter(
                $data['vehicle'] ?? [],
                fn ($value) => $value !== null && $value !== '',
            );
            $meta = $guest?->meta ?? [];

            if ($vehicle !== []) {
                $meta['vehicle'] = $vehicle;
            } else {
                unset($meta['vehicle']);
            }

            $data['meta'] = $meta;
        }

        unset($data['vehicle']);

        return $data;
    }
}
