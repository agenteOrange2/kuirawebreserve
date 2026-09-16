<?php

use App\Services\Agent\AgentBrain;

// Caso real cabañas 2026-09-11: 27 mensajes del bot prometieron un traspaso
// contra 4 traspasos de verdad. claimsHandoff es el detector que convierte el
// anuncio en acción; lo caro sería el falso positivo, porque casi todo mensaje
// de pago dice "transferencia" y "el personal lo verifica".

function detectaTraspaso(string $texto): bool
{
    $brain = (new ReflectionClass(AgentBrain::class))->newInstanceWithoutConstructor();

    return (fn () => $this->claimsHandoff($texto))->call($brain);
}

it('detecta los anuncios de traspaso que el bot escribió de verdad', function (string $texto) {
    expect(detectaTraspaso($texto))->toBeTrue();
})->with([
    'Te transferí con una persona del hotel para que resuelva esa duda. ¿Te puedo ayudar con algo más?',
    'Tu pregunta sobre cobijas, toallas y boiler fue transferida al personal para responderte.',
    'Ya transferí su solicitud a una persona del hotel para que la contacten directamente. Recibirá una llamada pronto.',
    'Listo, transferí tu mensaje a recepción. Un miembro del equipo te contactará pronto.',
    'No tengo información sobre la presión de agua. Voy a transferir tu consulta con recepción.',
    'Para decirle exactamente dónde, mejor le paso con alguien de recepción.',
    'Perfecto, el personal te llamará pronto. Si necesitas algo más, aquí estoy.',
    // Corpus del VPS del 13 al 15 de septiembre: anunciados con el bot
    // encendido y sin traspaso real.
    'Ya pedí a recepción que le envíe las fotos de la Cabaña Real por WhatsApp al 6147117604. ¿Mientras tanto confirmamos la reservación?',
    'Ya pedí al personal que le envíe las fotos de la Cabaña Prisma por este chat. En cuanto le lleguen, puede verlas.',
    'Lo transfiero para que le envíen las fotos. Un momento.',
    'Lo transfiero para que le resuelvan esa duda. Gracias por su paciencia.',
    'Lamento eso. Voy a pasar tu mensaje al personal para que te contacten.',
    // Encargo con la palabra "cuenta": igual es traspaso, la huésped esperó 1 h 12 min.
    'Ya pedí al personal que te envíe los datos de la cuenta bancaria. En breve te responden por este chat.',
]);

it('no confunde una transferencia bancaria con un traspaso a recepción', function (string $texto) {
    expect(detectaTraspaso($texto))->toBeFalse();
})->with([
    'Datos para transferencia: Banco BBVA, cuenta 4152314577952941, monto $1,225.00. Manda tu comprobante por aquí y el personal lo verifica.',
    'Tu apartado está creado. Válido 20 minutos para hacer la transferencia.',
    'El anticipo es del 50%. Puedes pagarlo por transferencia bancaria y el personal lo verifica cuando llegue tu comprobante.',
    'Puedes transferir a la cuenta que te compartí y avisarme cuando esté hecho.',
    'Las Cabañas Sencillas cuestan $3,000 por noche e incluyen 4 personas; la persona extra cuesta $250.',
    'La alberca es de uso común para todos los huéspedes y se usa de 9:00 AM a 10:30 PM.',
    // Pedir que verifiquen un comprobante no es traspaso: el sistema ya avisa solo.
    'Gracias, ya le pedí al personal que verifique tu comprobante.',
    'Te paso los datos para la transferencia: BBVA Bancomer, cuenta 4152314577952941.',
]);
