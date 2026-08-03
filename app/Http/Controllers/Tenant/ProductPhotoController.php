<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Foto del producto: la que se ve en el POS y en el inventario. Subir y
 * quitar exige inventory.manage; servirla es público porque el wizard
 * ofrece productos al huésped sin login (available_in_wizard), pero SOLO
 * entrega la colección `photo` de Product — nunca otro archivo del tenant.
 */
class ProductPhotoController extends Controller
{
    public function store(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:6144'],
        ], [
            'photo.max' => 'La foto puede pesar máximo 6 MB.',
            'photo.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
        ]);

        // La colección es singleFile: subir reemplaza la anterior.
        $product->addMedia($request->file('photo'))->toMediaCollection('photo');

        return response()->json(['photo' => $product->fresh()->photoPayload()], 201);
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->clearMediaCollection('photo');

        return response()->json(['photo' => null]);
    }

    public function show(Request $request, int $mediaId): BinaryFileResponse
    {
        $media = Media::query()->find($mediaId);

        abort_unless(
            $media !== null
            && $media->model_type === (new Product)->getMorphClass()
            && $media->collection_name === 'photo',
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
