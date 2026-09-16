<?php

use App\Services\Agent\AgentBrain;

// Caso real cabañas 2026-09-13 (RES-2026-1727): el apartado vencía a las
// 8:50 PM y el bot le prometió al huésped que podía transferir "mañana". Lo
// caro sería el falso positivo: en español "mañana" también es "de la mañana".

function prometePagarOtroDia(string $linea): bool
{
    $brain = (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();

    return (fn () => $this->promisesLaterPayment($linea))->call($brain);
}

it('detecta las promesas de pagar otro día que el bot escribió de verdad', function (string $linea) {
    expect(prometePagarOtroDia($linea))->toBeTrue();
})->with([
    'Si prefieres hacer la transferencia, puedo proporcionarte los datos mañana dentro del horario de 9:00 AM a 5:00 PM.',
    'Puedes hacer la transferencia mañana dentro del horario de 9:00 AM a 5:00 PM.',
    'Los datos para transferencia los proporcionaré mañana a la hora de tu preferencia.',
    'Entonces, mañana cuando te envíe los datos de transferencia, tendrás 1 hora para completar el pago.',
]);

it('no confunde un horario ni la regla de anticipación con una promesa', function (string $linea) {
    expect(prometePagarOtroDia($linea))->toBeFalse();
})->with([
    'Las transferencias se reciben de 9:00 de la mañana a 5:00 de la tarde.',
    'Las reservaciones se hacen con mínimo 2 días de anticipación: hoy puedes reservar a partir de pasado mañana.',
    'El pago total debe quedar liquidado a más tardar el viernes 30 de octubre.',
    'Tu apartado RES-2026-1727 queda guardado hasta hoy a las 8:50 PM.',
    '¿A qué hora te gustaría llegar mañana?',
]);
