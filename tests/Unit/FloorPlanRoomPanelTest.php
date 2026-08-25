<?php

/**
 * Guardia de diseño de la superficie de una habitación en el plano. Vivía sobre
 * FloorPlan.vue cuando la ficha era un slideover dentro de ese archivo; hoy es
 * el modal con tabs de resources/js/pages/tenant/floorplan/room/.
 *
 * Lo que protege es lo mismo: acciones grandes, lenguaje de mostrador y las
 * amenidades agrupadas en vez de una lista plana de veinte.
 */
function floorPlanRoomSource(string ...$files): string
{
    $base = dirname(__DIR__, 2).'/resources/js/pages/tenant/floorplan/room/';

    return collect($files)
        ->map(fn (string $file) => file_get_contents($base.$file))
        ->implode("\n");
}

it('mantiene acciones principales grandes y con lenguaje cotidiano', function () {
    $source = floorPlanRoomSource('tabs/SummaryTab.vue');

    expect($source)
        ->toContain('¿Qué necesitas hacer?')
        ->toContain('Registrar salida')
        // Alturas de dedo: el mostrador opera con tablet.
        ->toContain('min-h-11');

    // Los caminos de venta los arma el plano en un solo lugar (arrivalActions),
    // porque de ahí sale la decisión hotel/motel/ambos.
    expect(file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    ))
        ->toContain('Llegó sin reserva')
        ->toContain('Registrar su entrada ahora')
        ->toContain('Crear una reserva')
        ->toContain('Apartarla para otra fecha');
});

it('mantiene la información y las amenidades agrupadas', function () {
    $source = floorPlanRoomSource('tabs/RoomTab.vue');

    expect($source)
        ->toContain('Información de la habitación')
        ->toContain('Lo que incluye')
        ->toContain('Descanso y comodidad')
        ->toContain('Entretenimiento y conexión')
        ->toContain('Servicios y acceso')
        ->toContain('Amenidades agrupadas para encontrarlas');
});

it('la habitación tiene UNA superficie con tabs, no varias tarjetas', function () {
    $dialog = floorPlanRoomSource('RoomDialog.vue');
    $plan = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    );

    expect($dialog)
        ->toContain('Resumen')
        ->toContain('Consumos y cobro')
        ->toContain('Historial')
        ->toContain('Cuarto')
        // Encabezado y tabs fijos, cuerpo con scroll propio. El alto se
        // descuenta del panel (mt-16 del tema): con 92vh a secas el modal
        // se salía de la pantalla por abajo.
        ->toContain('max-h-[calc(100dvh-6rem)]')
        ->toContain('overflow-y-auto')
        // Ancho por pasos hasta 1400px: el tab de consumos trabaja a dos
        // columnas (catálogo y cuenta) y con 1200 se amontonaba.
        ->toContain('2xl:w-[1400px]');

    // El plano solo monta el modal: la ficha ya no vive dentro de este archivo.
    expect($plan)
        ->toContain('<RoomDialog />')
        ->not->toContain('<Slideover');
});

it('la operación de caseta y la revisión de daños son solo de motel', function () {
    $plan = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    );

    expect($plan)
        // La revisión de la habitación al salir es un paso de motel; en hotel
        // puro no existe y el diálogo de salida no debe ofrecerla.
        ->toContain(':can-review="hasMotel"')
        // Y el cobro lo hace el encargado solo en motel PURO: en "ambos"
        // decide quien atiende, así que arranca como el hotel de siempre.
        ->toContain(":collector-default=\"isMotel ? 'encargado' : 'caseta'\"");
});

it('el aviso de "falta capturar" gana al saldo en la tarjeta del cuarto', function () {
    $plan = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    );

    $capturar = strpos($plan, "if (room.active_stay?.arrival_pending) {\n        const owed");
    $debe = strpos($plan, "if ((room.active_stay?.balance_due ?? 0) > 0) {\n        return {");

    // En la caseta SIEMPRE hay saldo hasta que el encargado cobre: si el
    // "Debe $X" ganara, la tarjeta nunca diría qué hay que hacer.
    expect($capturar)->toBeInt()
        ->and($debe)->toBeInt()
        ->and($capturar)->toBeLessThan($debe);
});
