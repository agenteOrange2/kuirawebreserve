<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Logo del hotel para el wizard público (/reservas/ajustes → Apariencia).
 * Subir/quitar exige properties.manage; servir es público (el wizard no
 * tiene login) pero SOLO entrega la colección `wizard_logo` de Property —
 * nunca otros archivos del tenant. Sin SVG a propósito: puede incrustar
 * scripts y se sirve desde el mismo origen.
 */
class PropertyLogoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ], [
            'logo.max' => 'El logo puede pesar máximo 2 MB.',
            'logo.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
        ]);

        $property = Property::firstOrFail();
        $property->addMediaFromRequest('logo')->toMediaCollection('wizard_logo');

        return response()->json(['logo_url' => $property->fresh()->wizardAppearance()['logo_url']], 201);
    }

    public function destroy(): JsonResponse
    {
        Property::firstOrFail()->clearMediaCollection('wizard_logo');

        return response()->json(['logo_url' => null]);
    }

    /** Entrega pública del logo (cache de un día; resubir cambia la URL). */
    public function show(): BinaryFileResponse
    {
        $media = Property::first()?->getFirstMedia('wizard_logo');

        abort_unless($media !== null && is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
