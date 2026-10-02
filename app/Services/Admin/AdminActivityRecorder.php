<?php

namespace App\Services\Admin;

use App\Models\Central\AdminActivity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Único escritor de la bitácora del panel. Nunca tumba la petición: si la
 * escritura falla se deja en el log y la acción del administrador sigue.
 */
class AdminActivityRecorder
{
    /** Campos que jamás se guardan con su valor (contraseñas, llaves, cuentas). */
    public const SENSITIVE = '/(pass|secret|token|api_?key|access_key|private|clabe|card|account_number|credential|recovery|two_factor)/i';

    /**
     * @param  array{type?: string, id?: string|int|null, label?: string|null, tenant_id?: string|null}  $subject
     * @param  array<string, mixed>  $properties
     */
    public static function record(?User $user, string $action, array $subject = [], array $properties = [], ?Request $request = null): void
    {
        $request ??= request();

        try {
            AdminActivity::create([
                'user_id' => $user?->getKey(),
                'action' => $action,
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => isset($subject['id']) ? (string) $subject['id'] : null,
                'subject_label' => isset($subject['label']) ? Str::limit((string) $subject['label'], 250, '') : null,
                'tenant_id' => $subject['tenant_id'] ?? null,
                'properties' => $properties ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo registrar la bitácora del admin', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lo que el administrador mandó, sin secretos y recortado: basta para
     * saber qué tocó, no para reconstruir el formulario.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input, int $depth = 0): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if (in_array($key, ['_token', '_method'], true)) {
                continue;
            }
            if (is_string($key) && preg_match(self::SENSITIVE, $key)) {
                $out[$key] = filled($value) ? '(oculto)' : null;

                continue;
            }
            $out[$key] = match (true) {
                $value instanceof UploadedFile => 'archivo: '.$value->getClientOriginalName(),
                is_string($value) => Str::limit($value, 150),
                is_array($value) && $depth < 1 => self::sanitize(array_slice($value, 0, 20, true), $depth + 1),
                is_array($value) => Str::limit((string) json_encode($value, JSON_UNESCAPED_UNICODE), 150),
                default => $value,
            };
        }

        return $out;
    }
}
