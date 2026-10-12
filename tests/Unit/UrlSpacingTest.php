<?php

use App\Support\UrlSpacing;

/**
 * Ligas que llegaban rotas porque el canal tomaba el signo de al lado como
 * parte de la liga (cabañas, 40 en 30 días al 2026-10-12).
 */
it('separa la liga del signo que la sigue', function (string $antes, string $despues) {
    expect(UrlSpacing::separate($antes))->toBe($despues);
})->with([
    // El cobro (PaymentGuestNotifier), tal cual le llegó al huésped.
    [
        'Puedes pagarlo en este link seguro: https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=2254150246-f84bcbc3. Al completar el pago, tu reserva se confirma sola.',
        "Puedes pagarlo en este link seguro: https://www.mercadopago.com.mx/checkout/v1/redirect?pref_id=2254150246-f84bcbc3\nAl completar el pago, tu reserva se confirma sola.",
    ],
    // Dos puntos pegados.
    [
        'adelanta tu registro en https://cabanas.tureservaenlinea.com/reserva: entra con tu código RES-2026-1',
        "adelanta tu registro en https://cabanas.tureservaenlinea.com/reserva\nentra con tu código RES-2026-1",
    ],
    // Al final del renglón el signo solo se quita.
    ["Aquí la encuesta: https://h.test/encuesta/abc.\nTe esperamos.", "Aquí la encuesta: https://h.test/encuesta/abc\nTe esperamos."],
    ['Fotos: https://h.test/cabana/real/.', 'Fotos: https://h.test/cabana/real/'],
    // Coma.
    ['Mira https://h.test/a, y luego https://h.test/b.', "Mira https://h.test/a\ny luego https://h.test/b"],
    // Paréntesis de cierre: se separa, no se pierde.
    ['(fotos aquí: https://h.test/cabana/real/)', '(fotos aquí: https://h.test/cabana/real/ )'],
]);

it('no toca las ligas bien escritas ni los paréntesis que son de la liga', function (string $texto) {
    expect(UrlSpacing::separate($texto))->toBe($texto);
})->with([
    "Puedes pagarlo en este link seguro:\nhttps://h.test/pago/9f1c",
    'Liga https://h.test/pago/9f1c y luego más texto',
    'https://es.wikipedia.org/wiki/Juárez_(Chihuahua)',
    'Sin ligas. Solo texto: nada que hacer.',
]);
