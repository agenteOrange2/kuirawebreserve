<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Experience;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fotos de experiencias: misma mecánica que las fotos de tipos de
 * habitación (subir/quitar/reordenar con permiso; servir es público pero
 * SOLO la colección photos de Experience).
 */
class ExperiencePhotoController extends Controller
{
    protected const MAX_PHOTOS = 12;

    public function store(Request $request, Experience $experience): JsonResponse
    {
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpeg,png,webp', 'max:6144'],
        ], [
            'photos.*.max' => 'Cada foto puede pesar máximo 6 MB.',
            'photos.*.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
        ]);

        $current = $experience->getMedia('photos')->count();
        $incoming = count($request->file('photos', []));

        if ($current + $incoming > self::MAX_PHOTOS) {
            return response()->json([
                'message' => 'Máximo '.self::MAX_PHOTOS." fotos por experiencia (tienes {$current}).",
            ], 422);
        }

        foreach ($request->file('photos', []) as $file) {
            $experience->addMedia($file)->toMediaCollection('photos');
        }

        return response()->json(['photos' => $experience->fresh()->photosPayload()], 201);
    }

    public function destroy(Experience $experience, int $mediaId): JsonResponse
    {
        $media = $experience->getMedia('photos')->firstWhere('id', $mediaId);
        abort_unless($media !== null, 404);
        $media->delete();

        return response()->json(['photos' => $experience->fresh()->photosPayload()]);
    }

    public function reorder(Request $request, Experience $experience): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        $owned = $experience->getMedia('photos')->pluck('id')->all();

        if (array_diff($data['order'], $owned) !== [] || count($data['order']) !== count($owned)) {
            return response()->json(['message' => 'El orden no corresponde con las fotos de esta experiencia.'], 422);
        }

        Media::setNewOrder($data['order']);

        return response()->json(['photos' => $experience->fresh()->photosPayload()]);
    }

    public function show(Request $request, int $mediaId): BinaryFileResponse
    {
        $media = Media::query()->find($mediaId);

        abort_unless(
            $media !== null
            && $media->model_type === (new Experience)->getMorphClass()
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
}
