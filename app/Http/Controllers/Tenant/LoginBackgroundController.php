<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Support\TenantLoginBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Foto de fondo del login en el dominio del hotel (/ajustes/general/
 * apariencia). Subir/quitar exige properties.manage; servir es público
 * porque el login se ve sin sesión, y SOLO entrega la colección
 * `login_background` de Property. Sin SVG, igual que el logo.
 */
class LoginBackgroundController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'background' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
        ], [
            'background.max' => 'La imagen puede pesar máximo 4 MB.',
            'background.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
        ]);

        $property = Property::firstOrFail();
        $property->addMediaFromRequest('background')->toMediaCollection('login_background');

        return response()->json(['background_url' => TenantLoginBrand::backgroundUrl($property->fresh())], 201);
    }

    public function destroy(): JsonResponse
    {
        Property::firstOrFail()->clearMediaCollection('login_background');

        return response()->json(['background_url' => null]);
    }

    /** Entrega pública (cache de un día; resubir cambia la URL). */
    public function show(): BinaryFileResponse
    {
        $media = Property::first()?->getFirstMedia('login_background');

        abort_unless($media !== null && is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
