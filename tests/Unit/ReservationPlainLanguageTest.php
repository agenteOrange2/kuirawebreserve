<?php

function reservationUiSource(string $path): string
{
    $source = file_get_contents(dirname(__DIR__, 2).'/resources/'.$path);

    expect($source)->toBeString();

    return $source;
}

it('mantiene el flujo público de reserva en lenguaje cotidiano', function () {
    $wizard = reservationUiSource(
        'js/pages/tenant/reservar/Wizard.vue',
    );

    expect($wizard)
        ->toContain('¿Cuándo nos visitas?')
        ->toContain('Por unas horas')
        ->toContain('Buscar habitaciones')
        ->toContain('Paso {{ stepNumber(step) }} de');
});

it('mantiene las acciones del crud de reservas en lenguaje cotidiano', function () {
    $reservations = reservationUiSource(
        'js/pages/tenant/reservations/Index.vue',
    );

    expect($reservations)
        ->toContain('Llegó sin reserva')
        ->toContain('Registrar llegada')
        ->toContain('Registrar salida')
        ->toContain('El huésped no llegó')
        ->toContain('Encuentra una reserva')
        ->toContain('Nombre, teléfono, folio o habitación')
        ->toContain('+52 MX')
        ->toContain('+1 US/CA')
        ->toContain('Se guardará como')
        ->toContain('sm:w-[94vw] lg:w-[980px]')
        ->toContain('size="lg"')
        ->toContain('class="h-7 w-7"')
        ->toContain('Agregar cargos, vehículo o notas')
        // El apartado dura lo que el hotel configure (holdLabel), dicho
        // en lenguaje cotidiano, nunca como "hold" a secas.
        ->toContain('Apartado de {{ holdLabel }}')
        ->toContain('Aparta la habitación')
        ->not->toContain('Se creará como hold');
});

it('explica el historial sin exigir términos hoteleros', function () {
    $history = reservationUiSource(
        'js/pages/tenant/reservations/History.vue',
    );

    expect($history)
        ->toContain('huéspedes que no llegaron')
        ->toContain("status === 'no_show' ? 'No llegó'")
        ->toContain('Tipo de estancia: {{ detail.rate_plan }}');
});
