<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Foto de perfil de la persona que usa el panel. Vive igual en los dos
 * paneles (admin y hotel): cada uno resuelve su propia tabla de usuarios
 * porque la conexión ya viene puesta por el tenant.
 *
 * Subir y borrar es SIEMPRE sobre uno mismo — el id de la URL solo sirve
 * para servir la imagen, nunca para escribir en el perfil de otro.
 */
class AvatarController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ], [
            'avatar.max' => 'La foto puede pesar máximo 2 MB.',
            'avatar.mimes' => 'Formatos permitidos: JPG, PNG o WebP.',
            'avatar.image' => 'El archivo debe ser una imagen.',
        ]);

        $user = $request->user();
        // Explícito con el archivo de ESTA petición (no addMediaFromRequest,
        // que va por el request global del contenedor).
        $user->addMedia($request->file('avatar'))->toMediaCollection('avatar');

        return response()->json(['avatar_url' => $user->refresh()->avatarUrl()], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->clearMediaCollection('avatar');

        return response()->json(['avatar_url' => null]);
    }

    /**
     * Entrega la foto. Solo la colección `avatar` de ese usuario: nunca
     * sirve otro archivo aunque venga un id de media en la URL.
     */
    public function show(Request $request, User $user): BinaryFileResponse
    {
        $media = $user->getFirstMedia('avatar');

        abort_unless($media && file_exists($media->getPath()), 404);

        return response()
            ->file($media->getPath(), ['Content-Type' => $media->mime_type])
            ->setMaxAge(86400)
            ->setPublic();
    }
}
