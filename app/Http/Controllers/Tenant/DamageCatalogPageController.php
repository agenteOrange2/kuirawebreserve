<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\PropertyMode;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Área AISLADA del catálogo de daños (/ajustes/danos): lo que el hotel cobra
 * cuando revisa la habitación antes de dejar salir al cliente — toalla,
 * sábana, control, cerradura. Cada concepto con su precio sugerido.
 *
 * Tiene página propia por la misma regla que wizard, pagos y avisos: es una
 * lista con precios, no una casilla que quepa en Ajustes general. Y vive en
 * los settings de la propiedad (como las cuentas bancarias), así que no
 * necesita tabla: son diez conceptos, no un inventario.
 *
 * Es de la operación de motel; en hotel puro la revisión de salida no existe
 * como paso, pero el catálogo no estorba y se deja disponible en ambos.
 */
class DamageCatalogPageController extends Controller
{
    public function __invoke(PropertyMode $mode): Response
    {
        $property = Property::firstOrFail();

        return Inertia::render('tenant/settings/Damages', [
            'property' => $property->only(['id', 'name']),
            'damages' => self::catalog($property),
            'isMotel' => $mode->hasMotel(),
        ]);
    }

    /**
     * El catálogo tal como lo consumen la página y el plano.
     *
     * @return array<int, array{concept: string, amount: float}>
     */
    public static function catalog(?Property $property = null): array
    {
        $settings = ($property ?? Property::query()->first())?->settings ?? [];

        return collect($settings['damage_catalog'] ?? [])
            ->map(fn (array $item) => [
                'concept' => (string) ($item['concept'] ?? ''),
                'amount' => round((float) ($item['amount'] ?? 0), 2),
            ])
            ->filter(fn (array $item) => $item['concept'] !== '')
            ->values()
            ->all();
    }
}
