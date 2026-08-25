<?php

namespace App\Services;

use App\Models\Property;

/**
 * Checklist e insumos de limpieza que el hotel define para su operación.
 *
 * Viven en `properties.settings` (mismo patrón que los aspectos de encuesta y
 * los ajustes de redes): son una decena de renglones por hotel y no ameritan
 * tabla propia. Lo que sí se guarda por limpieza —qué se marcó y cuánta ropa
 * se usó— va en `room_cleanings`.
 */
class HousekeepingChecklist
{
    /** @var array<int, array{key: string, label: string}> */
    public const DEFAULT_TASKS = [
        ['key' => 'sabanas', 'label' => 'Cambio de sábanas'],
        ['key' => 'toallas', 'label' => 'Cambio de toallas'],
        ['key' => 'bano', 'label' => 'Baño'],
        ['key' => 'aspirado', 'label' => 'Barrido y trapeado'],
        ['key' => 'amenidades', 'label' => 'Reposición de amenidades'],
        ['key' => 'ventilacion', 'label' => 'Ventilación'],
    ];

    /** @var array<int, array{key: string, label: string}> */
    public const DEFAULT_LINENS = [
        ['key' => 'sabanas', 'label' => 'Juegos de sábanas'],
        ['key' => 'toallas', 'label' => 'Toallas'],
    ];

    public function __construct(protected ?Property $property = null) {}

    /**
     * Tareas activas del checklist, en orden.
     *
     * @return array<int, array{key: string, label: string, active: bool}>
     */
    public function tasks(bool $onlyActive = false): array
    {
        $stored = $this->settings()['hk_checklist'] ?? null;

        $tasks = is_array($stored) && $stored !== []
            ? array_values(array_filter(array_map(
                fn ($task) => is_array($task) && ! empty($task['key']) && ! empty($task['label'])
                    ? [
                        'key' => (string) $task['key'],
                        'label' => (string) $task['label'],
                        'active' => (bool) ($task['active'] ?? true),
                    ]
                    : null,
                $stored,
            )))
            : array_map(fn (array $task) => $task + ['active' => true], self::DEFAULT_TASKS);

        return $onlyActive
            ? array_values(array_filter($tasks, fn (array $task) => $task['active']))
            : $tasks;
    }

    /**
     * Insumos que se cuentan por limpieza (ropa de cama y baño).
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function linens(): array
    {
        $stored = $this->settings()['hk_linens'] ?? null;

        if (! is_array($stored) || $stored === []) {
            return self::DEFAULT_LINENS;
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_array($item) && ! empty($item['key']) && ! empty($item['label'])
                ? ['key' => (string) $item['key'], 'label' => (string) $item['label']]
                : null,
            $stored,
        )));
    }

    /**
     * Deja solo las llaves que el hotel tiene configuradas: así un checklist
     * viejo guardado en el navegador no mete tareas que ya no existen.
     *
     * @param  array<int, string>  $marked
     * @return array<int, string>
     */
    public function sanitizeChecklist(array $marked): array
    {
        $valid = array_column($this->tasks(), 'key');

        return array_values(array_intersect(array_map('strval', $marked), $valid));
    }

    /**
     * Cantidades de ropa, acotadas a los insumos configurados y sin ceros
     * (no tiene caso guardar "0 toallas" en cada limpieza).
     *
     * @param  array<string, mixed>  $counts
     * @return array<string, int>
     */
    public function sanitizeLinens(array $counts): array
    {
        $valid = array_column($this->linens(), 'key');
        $clean = [];

        foreach ($counts as $key => $value) {
            $quantity = (int) $value;

            if (in_array((string) $key, $valid, true) && $quantity > 0) {
                $clean[(string) $key] = min($quantity, 99);
            }
        }

        return $clean;
    }

    /** @return array<string, mixed> */
    protected function settings(): array
    {
        $this->property ??= Property::query()->first();

        return $this->property?->settings ?? [];
    }
}
