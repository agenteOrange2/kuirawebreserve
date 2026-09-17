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

    // Los caminos de venta los arma el plano en un solo lugar (saleOptionsFor),
    // porque de ahí sale la decisión hotel/motel/ambos.
    expect(file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    ))
        ->toContain('Llegó sin reserva')
        ->toContain('Registrar su entrada ahora')
        ->toContain('Apartar para otra fecha');
});

it('separa entregar ahora de apartar para otra fecha', function () {
    $plan = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    );

    // Entregar el cuarto exige que esté físicamente listo; apartarlo para
    // otra fecha no, y esconderlo detrás del semáforo dejaba sin vender
    // fechas que el motor de disponibilidad sí acepta.
    expect($plan)
        ->toContain('sellNowActions')
        ->toContain('bookAheadAction')
        ->toContain('saleOptionsFor');

    $summary = floorPlanRoomSource('tabs/SummaryTab.vue');

    expect($summary)
        // El bloque de venta ya no se esconde por el color del semáforo.
        ->not->toContain("room.status === 'available' && canManageReservations")
        ->toContain('roomSale.sellNow.length')
        ->toContain('Otras fechas');

    // Y el aviso nombra a la reserva que de verdad aparta el cuarto, no a la
    // próxima, que puede ser de dentro de un mes: cancelarla no liberaba nada.
    expect(floorPlanRoomSource('tabs/CleaningTab.vue'))
        ->toContain('room.holding_reservation')
        ->not->toContain('para liberarla,');
});

it('limpieza y mantenimiento tienen tab propio, no una sección al fondo', function () {
    $dialog = floorPlanRoomSource('RoomDialog.vue');

    expect($dialog)
        ->toContain("key: 'limpieza'")
        ->toContain("key: 'mantenimiento'")
        ->toContain('<CleaningTab')
        ->toContain('<MaintenanceTab');

    // El Resumen se queda con la venta y el huésped: el semáforo era una
    // sección al fondo, debajo de todo, titulada "Limpieza y mantenimiento".
    expect(floorPlanRoomSource('tabs/SummaryTab.vue'))
        ->not->toContain('Limpieza y mantenimiento')
        ->not->toContain('transitionMeta');

    // Y el tab Cuarto vuelve a ser la ficha del cuarto: las fallas y los
    // bloqueos de fechas se fueron a Mantenimiento.
    expect(floorPlanRoomSource('tabs/RoomTab.vue'))
        ->not->toContain('Reportar una falla')
        ->not->toContain('Mantenimiento programado');

    // El reporte y el bloqueo de fechas viven en sus diálogos: dentro del tab
    // eran dos formularios largos que dejaban la tarjeta vacía cuando nadie
    // estaba capturando.
    expect(floorPlanRoomSource('tabs/MaintenanceTab.vue'))
        ->toContain('ReportIncidentDialog')
        ->toContain('ScheduleBlockDialog')
        ->toContain('Mantenimiento programado')
        ->toContain('Fallas sin resolver');

    // La limpieza en curso solo se veía como badge sobre el plano: al abrir
    // el cuarto no había manera de saber quién estaba adentro.
    expect(floorPlanRoomSource('tabs/CleaningTab.vue'))
        ->toContain('room.cleaning');
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
        // La revisión de la habitación al salir ya NO es exclusiva de motel:
        // un hotel que retiene depósito en garantía la necesita igual (el
        // contrato de cabañas retiene $1,500 por cabaña). Por eso el diálogo
        // de salida la ofrece siempre y el prop :can-review desapareció.
        ->not->toContain(':can-review=')
        // El cobro sí lo hace el encargado solo en motel PURO: en "ambos"
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
