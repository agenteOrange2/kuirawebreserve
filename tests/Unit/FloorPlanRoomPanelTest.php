<?php

function floorPlanRoomPanelSource(): string
{
    $source = file_get_contents(
        dirname(__DIR__, 2).'/resources/js/pages/tenant/FloorPlan.vue',
    );

    expect($source)->toBeString();

    return $source;
}

it('mantiene acciones principales grandes y con lenguaje cotidiano', function () {
    $floorPlan = floorPlanRoomPanelSource();

    expect($floorPlan)
        ->toContain('sm:w-[820px]')
        ->toContain('¿Qué necesitas hacer?')
        ->toContain('Llegó sin reserva')
        ->toContain('Registrar su entrada ahora')
        ->toContain('Crear una reserva')
        ->toContain('Apartarla para otra fecha')
        ->toContain('Registrar llegada')
        ->toContain('Registrar salida');
});

it('mantiene la información y las amenidades agrupadas', function () {
    $floorPlan = floorPlanRoomPanelSource();

    expect($floorPlan)
        ->toContain('Información de la habitación')
        ->toContain('Lo que incluye')
        ->toContain('Descanso y comodidad')
        ->toContain('Entretenimiento y conexión')
        ->toContain('Servicios y acceso')
        ->toContain('Amenidades agrupadas para encontrarlas');
});
