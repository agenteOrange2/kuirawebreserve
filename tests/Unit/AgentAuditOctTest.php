<?php

use App\Services\Agent\AgentBrain;

/**
 * Defectos de la auditoría de cabañas del 24-sep al 8-oct de 2026 (358
 * conversaciones del VPS), en las piezas puras del cerebro del bot.
 */
function cerebroPuro(): AgentBrain
{
    return (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();
}

function invocar(string $method, mixed ...$args): mixed
{
    return (new ReflectionMethod(AgentBrain::class, $method))->invoke(cerebroPuro(), ...$args);
}

it('arregla las fugas de idioma conocidas sin rehacer el mensaje', function (string $sucio, string $limpio) {
    expect(cerebroPuro()->fixKnownLeaks($sucio))->toBe($limpio);
})->with([
    'whenever/any' => [
        'Con gusto, quédese con la información whenever you need it. Cuando tenga any pregunta sobre la cabaña, aquí estoy para las fechas.',
        'Con gusto, quédese con la información cuando la necesite. Cuando tenga alguna pregunta sobre la cabaña, aquí estoy para las fechas.',
    ],
    'christmas' => [
        'Para Christmas, ¿para qué fechas busca la reservación de la cabaña?',
        'Para Navidad, ¿para qué fechas busca la reservación de la cabaña?',
    ],
    'downstairs' => [
        'La Luxury tiene cama queen downstairs y matrimonial upstairs, con estufa para la noche.',
        'La Luxury tiene cama queen en planta baja y matrimonial en planta alta, con estufa para la noche.',
    ],
    'palabras pegadas' => [
        '¿Te gustaríaapartar alguna de las combinaciones que te ofrecí para la noche?',
        '¿Te gustaría apartar alguna de las combinaciones que te ofrecí para la noche?',
    ],
]);

it('a quien escribe en inglés no le toca su inglés', function () {
    $texto = 'Hi! Whenever you need it, the cabin is upstairs. Any questions?';

    expect(cerebroPuro()->fixKnownLeaks($texto))->toBe($texto);
});

it('reconoce cuando anuncia una acción y no la hace', function (string $texto, bool $esperado) {
    expect(cerebroPuro()->announcesPendingAction($texto))->toBe($esperado);
})->with([
    'procedo a crear' => ['El saldo se liquida 7 días antes. Procedo a crear tu apartado. Te comparto el link de pago en un momento.', true],
    'un momento' => ['Son 4 personas en total. Ahora verifico la disponibilidad y te doy la cotización. Un momento.', true],
    'déjame verificar' => ['Entendido, del sábado 10 al domingo 11 con 10 personas. Déjame verificar la disponibilidad para esas fechas.', true],
    'ya trae el resultado' => ['Déjame verificar... Sí hay: la Cabaña Sencilla 1 está libre el sábado 10 por $3,000.', false],
    'respuesta normal' => ['La entrega de la cabaña es de 2:00 a 4:00 PM y la salida a las 11:00 AM.', false],
]);

it('"23 al 26 de diciembre": el primer día es de diciembre, no el próximo 23', function () {
    // Conv. 1578: el guardián contestó "del viernes 23 de octubre al viernes
    // 6 de noviembre ya no están disponibles" a un grupo de diciembre.
    $fechas = array_values(invocar('datesMentioned', 'Para el 23 al 26 de diciembre tenemos lugar', true));

    expect(collect($fechas)->map(fn ($d) => $d->format('m-d'))->sort()->values()->all())
        ->toBe(['12-23', '12-26']);
});

it('dos rangos en un renglón son dos estancias, no dos semanas seguidas', function () {
    // Conv. 813: "sábado 10 al domingo 11 o viernes 23 al domingo 25" se
    // leía como del 10 al 24. Fijo al día de la auditoría: con el reloj real
    // el 10 de octubre ya pasó y sus noches se descartan.
    \Carbon\Carbon::setTestNow('2026-10-08 12:00');
    \Carbon\CarbonImmutable::setTestNow('2026-10-08 12:00');
    $year = 2026;
    $noches = invocar('nightsClaimed', "Para el 10 de octubre al 11 de octubre de {$year} o el 23 de octubre al 25 de octubre de {$year} hay dos cabañas libres");
    \Carbon\Carbon::setTestNow();
    \Carbon\CarbonImmutable::setTestNow();

    expect(collect($noches)->map(fn ($d) => $d->format('m-d'))->all())
        ->toBe(['10-10', '10-23', '10-24']);
});
