<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Property;
use Carbon\CarbonInterface;

/**
 * Tiempos objetivo de atención por prioridad: cuánto puede vivir una
 * incidencia antes de que el sistema la marque como vencida y avise.
 *
 * Viven en `properties.settings['incident_sla']` (mismo patrón que los
 * ajustes de limpieza y redes): son tres números por hotel y no ameritan
 * tabla propia.
 */
class IncidentPolicy
{
    /** Horas por defecto: una fuga no espera lo mismo que un foco fundido. */
    public const DEFAULTS = [
        'high' => 4,
        'medium' => 24,
        'low' => 72,
    ];

    /** Tope de captura: más allá de un mes, el plazo deja de ser un plazo. */
    public const MAX_HOURS = 720;

    public function __construct(protected ?Property $property = null) {}

    /**
     * Horas objetivo por prioridad, ya saneadas.
     *
     * @return array<string, int>
     */
    public function hours(): array
    {
        $stored = $this->settings()['incident_sla'] ?? [];

        $hours = [];
        foreach (self::DEFAULTS as $priority => $default) {
            $value = (int) ($stored[$priority] ?? $default);
            $hours[$priority] = $value >= 1 && $value <= self::MAX_HOURS ? $value : $default;
        }

        return $hours;
    }

    public function hoursFor(string $priority): int
    {
        return $this->hours()[$priority] ?? self::DEFAULTS['medium'];
    }

    /**
     * Cuándo vence una incidencia abierta ahora con esa prioridad. Se calcula
     * al crear y al cambiar la prioridad: subir a alta debe adelantar el
     * plazo, no dejar el viejo.
     */
    public function dueAt(string $priority, ?CarbonInterface $from = null): CarbonInterface
    {
        // Ojo: en esta app now() y las fechas de Eloquent son inmutables,
        // así que addHours() devuelve una instancia nueva.
        return ($from ?? now())->copy()->addHours($this->hoursFor($priority));
    }

    /**
     * ¿Esta incidencia ya se pasó de su tiempo? Una resuelta nunca vence:
     * su tiempo dejó de correr al cerrarse.
     */
    public function isOverdue(Incident $incident): bool
    {
        return $incident->status !== Incident::STATUS_RESOLVED
            && $incident->due_at !== null
            && $incident->due_at->isPast();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(array $input): void
    {
        $property = $this->property ??= Property::query()->first();

        if (! $property) {
            return;
        }

        $hours = [];
        foreach (self::DEFAULTS as $priority => $default) {
            $value = (int) ($input[$priority] ?? $default);
            $hours[$priority] = max(1, min($value, self::MAX_HOURS));
        }

        $property->update([
            'settings' => array_merge($property->settings ?? [], ['incident_sla' => $hours]),
        ]);

        $this->property = $property->fresh();
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        $this->property ??= Property::query()->first();

        return $this->property?->settings ?? [];
    }
}
