<?php

use App\Services\Agent\AgentBrain;
use Carbon\CarbonImmutable;

// Caso real cabañas 2026-09-16, conversación 937: el bot le ofreció como
// alternativa "Domingo 27 de septiembre", el huésped contestó "El domingo 27"
// y el bot le dictaminó sobre el SÁBADO 26 —la fecha vieja— y volvió a
// ofrecerle la misma lista con el domingo 27 dentro. El huésped, que venía
// por 10 personas, no volvió a escribir.
//
// El reloj se fija: estas pruebas hablan de días de la semana concretos.

beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-09-16 15:27', 'America/Ciudad_Juarez')));

afterEach(fn () => test()->travelBack());

function cerebro(): AgentBrain
{
    return (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
}

function fechasDe(string $texto, bool $suelto = true): array
{
    return array_keys((fn () => $this->datesMentioned($texto, $suelto))->call(cerebro()));
}

function contestaLaFechaPedida(string $respuesta, array $pedidas): bool
{
    $brain = cerebro();
    $requested = [];

    foreach ($pedidas as $dia) {
        $requested[$dia] = CarbonImmutable::parse($dia);
    }

    return (fn () => $this->answersRequestedDates($respuesta, $requested))->call($brain);
}

it('entiende la fecha que el huésped elige sin repetir el mes', function (string $dicho, string $espera) {
    expect(fechasDe($dicho))->toContain($espera);
})->with([
    // El mensaje exacto que el bot ignoró.
    ['El domingo 27', '2026-09-27'],
    ['Sábado 26 y domingo 27', '2026-09-26'],
    ['sí, el domingo 27 por favor', '2026-09-27'],
    ['para el 27', '2026-09-27'],
    ['El sábado 10 de octubre', '2026-10-10'],
]);

it('no confunde un número cualquiera con una fecha', function (string $dicho) {
    expect(fechasDe($dicho))->toBeEmpty();
})->with([
    'Somos 10 personas',
    'Serían 2 noches',
    'El precio de 3500 está bien',
    'Ok entraría el sábado alas 2pm y salgo el domingo alas 11',
]);

it('detecta el veredicto sobre una fecha que el huésped no pidió', function () {
    // La respuesta real que perdió al huésped.
    $respuesta = "Lamento informarle que el sábado 26 de septiembre tampoco hay disponibilidad.\n\n"
        ."Las alternativas con espacio para 10 personas son:\n\n"
        ."- Domingo 27 de septiembre (llegada y salida lunes 28)\n"
        ."- Sábado 10 de octubre\n"
        ."- Sábado 17 de octubre\n\n"
        .'¿Alguna le funciona?';

    expect(contestaLaFechaPedida($respuesta, ['2026-09-27']))->toBeFalse();
});

it('deja pasar las respuestas que sí contestan lo que se preguntó', function (string $respuesta, array $pedidas) {
    expect(contestaLaFechaPedida($respuesta, $pedidas))->toBeTrue();
})->with([
    // Nombra otra fecha, pero no dictamina sobre ella: es la salida.
    ['¿Sería una noche (salida el domingo 27) o cuántas noches tiene en mente?', ['2026-09-26']],
    // El veredicto incluye la fecha pedida dentro del rango.
    ['Lamentablemente, para el sábado 26 de septiembre al domingo 27 no queda ninguna cabaña disponible.', ['2026-09-27']],
    // Dice que no hay para la fecha pedida y ofrece alternativas: correcto.
    ["Lamento informarte que el domingo 27 de septiembre no hay disponibilidad.\n- Sábado 10 de octubre\n- Sábado 17 de octubre", ['2026-09-27']],
    // Sin fechas en el veredicto no hay nada que contradecir.
    ['Con gusto le confirmo disponibilidad en cuanto me indique cuántas noches.', ['2026-09-27']],
]);

it('corrige el día de la semana que no cuadra con la fecha', function (string $dicho, string $espera) {
    expect(cerebro()->sanitizeWeekdays($dicho))->toBe($espera);
})->with([
    // Los cuatro del corpus del 13 al 16 de septiembre.
    ['El sábado 18 de septiembre tenemos disponibilidad', 'El viernes 18 de septiembre tenemos disponibilidad'],
    ['¿Le interesa el lunes 21 de octubre?', '¿Le interesa el miércoles 21 de octubre?'],
    ['Le confirmo el martes 16 de septiembre', 'Le confirmo el miércoles 16 de septiembre'],
    ['Queda para el jueves 18 de septiembre de 2026', 'Queda para el viernes 18 de septiembre de 2026'],
    ['Para el Sábado 18 de Septiembre', 'Para el Viernes 18 de Septiembre'],
]);

it('no toca los días que sí cuadran', function (string $dicho) {
    expect(cerebro()->sanitizeWeekdays($dicho))->toBe($dicho);
})->with([
    'Lamento informarle que el sábado 26 de septiembre no hay disponibilidad',
    'Domingo 27 de septiembre al lunes 28 de septiembre de 2026',
    // Una estancia del año pasado: el viernes 5 de septiembre de 2025 existió.
    'Su estancia del viernes 5 de septiembre quedó registrada',
    'Los sábados 10 de octubre tenemos promoción',
    // Con el año escrito manda el año, no el calendario de este año.
    'Su estancia del jueves 18 de septiembre de 2025 quedó registrada',
]);

// Caso real cabañas 2026-09-29 (Messenger, conv. 1631): "¿Tiene disponible
// 24 y 25?" no resolvía a ninguna fecha; el modelo puso septiembre, ya
// pasado, y el huésped recibió una cotización para septiembre de 2027.
it('dos días sin mes que ya pasaron este mes son los del mes que entra', function (string $dicho, array $espera) {
    test()->travelTo(CarbonImmutable::parse('2026-09-29 16:56', 'America/Ciudad_Juarez'));

    expect(fechasDe($dicho))->toBe($espera);
})->with([
    ['Tiene disponible 24 y 25?', ['2026-10-24', '2026-10-25']],
    ['del 24 al 26', ['2026-10-24', '2026-10-26']],
    ['el 30 y 31?', ['2026-09-30']],
]);

it('dos números que no son un rango de días no son fecha', function (string $dicho) {
    test()->travelTo(CarbonImmutable::parse('2026-09-29 16:56', 'America/Ciudad_Juarez'));

    expect(fechasDe($dicho))->toBeEmpty();
})->with([
    'Somos 4 y 2 niños',
    'Seríamos 6 y 2 menores',
    'de 2 a 3 noches',
    'entre 3 y 5 personas',
]);
