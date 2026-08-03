<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\WaitlistEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Captura pública de la lista de espera (módulo lista-espera): el wizard
 * sin disponibilidad ofrece "Avísame si se libera" — nombre + contacto y
 * el rango buscado. Stateless, con throttle y detrás del middleware
 * module:lista-espera (ruta). El aviso lo dispara la cancelación que
 * libera fechas (WaitlistNotifier).
 */
class WaitlistPublicController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_phone' => ['nullable', 'string', 'max:30'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'starts_at' => ['required', 'date', 'after_or_equal:today'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // null = le sirve cualquier tipo (búsqueda general sin elegir).
            'room_type_id' => ['nullable', 'integer', 'exists:room_types,id'],
        ]);

        if (blank($data['guest_phone'] ?? null) && blank($data['guest_email'] ?? null)) {
            throw ValidationException::withMessages([
                'guest_phone' => ['Deja tu teléfono o tu correo para poder avisarte.'],
            ]);
        }

        // Anti-duplicado suave: mismo contacto esperando el mismo rango no
        // crea otra entrada (doble clic, reintento del huésped).
        $existing = WaitlistEntry::query()
            ->waiting()
            ->whereDate('starts_at', $data['starts_at'])
            ->whereDate('ends_at', $data['ends_at'])
            ->where(fn ($q) => $q
                ->when($data['guest_phone'] ?? null, fn ($qq, $phone) => $qq->orWhere('guest_phone', $phone))
                ->when($data['guest_email'] ?? null, fn ($qq, $email) => $qq->orWhere('guest_email', $email)))
            ->first();

        $entry = $existing ?? WaitlistEntry::create([
            ...$data,
            'status' => WaitlistEntry::STATUS_WAITING,
        ]);

        return response()->json([
            'ok' => true,
            'id' => $entry->id,
            'message' => 'Listo, te avisaremos si se libera espacio para tus fechas.',
        ], $existing ? 200 : 201);
    }
}
