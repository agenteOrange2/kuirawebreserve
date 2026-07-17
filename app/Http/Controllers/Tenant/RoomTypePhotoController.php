<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fotos de los tipos de habitación (Catálogo → tipo): la galería que ve el
 * huésped en el wizard. Subir/quitar/reordenar exige rooms.manage; servir
 * es público (el wizard no tiene login) pero SOLO entrega la colección
 * `photos` de RoomType — jamás documentos u otros archivos del tenant.
 */
class RoomTypePhotoController extends Controller
{
    protected const MAX_PHOTOS = 12;

    public function store(Request $request, RoomType $roomType): JsonResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpeg,png,webp', 'max:6144'],
        ], [
            'photos.*.max' => 'Cada foto puede pesar máximo 6 MB.',
            'photos.*.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
        ]);

        $current = $roomType->getMedia('photos')->count();
        $incoming = count($request->file('photos', []));

        if ($current + $incoming > self::MAX_PHOTOS) {
            return response()->json([
                'message' => 'Máximo '.self::MAX_PHOTOS." fotos por tipo de habitación (tienes {$current}).",
            ], 422);
        }

        foreach ($request->file('photos', []) as $file) {
            $roomType->addMedia($file)->toMediaCollection('photos');
        }

        return response()->json(['photos' => $roomType->fresh()->photosPayload()], 201);
    }

    public function destroy(RoomType $roomType, int $mediaId): JsonResponse
    {
        $this->ownPhoto($roomType, $mediaId)->delete();

        return response()->json(['photos' => $roomType->fresh()->photosPayload()]);
    }

    /** Reordena la galería; la primera foto es la portada. */
    public function reorder(Request $request, RoomType $roomType): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $owned = $roomType->getMedia('photos')->pluck('id')->all();

        if (array_diff($data['order'], $owned) !== [] || count($data['order']) !== count($owned)) {
            return response()->json(['message' => 'El orden no corresponde con las fotos de este tipo.'], 422);
        }

        Media::setNewOrder($data['order']);

        return response()->json(['photos' => $roomType->fresh()->photosPayload()]);
    }

    /**
     * Entrega pública de la foto (wizard, sin login). Cache de un día: las
     * fotos cambian poco y el borrado emite otra URL (id nuevo al resubir).
     */
    public function show(Request $request, int $mediaId): BinaryFileResponse
    {
        $media = Media::query()->find($mediaId);

        abort_unless(
            $media !== null
            && $media->model_type === (new RoomType)->getMorphClass()
            && $media->collection_name === 'photos',
            404,
        );

        $path = $media->getPath();

        if ($request->query('v') === 'thumb') {
            $thumb = $media->getPath('thumb');
            $path = is_file($thumb) ? $thumb : $path;
        }

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    protected function ownPhoto(RoomType $roomType, int $mediaId): Media
    {
        $media = $roomType->getMedia('photos')->firstWhere('id', $mediaId);

        abort_unless($media !== null, 404);

        return $media;
    }
}
