<?php

use App\Support\BankAccountNumber;

// Caso real cabañas 2026-09-16: el número guardado como "clabe" era una
// tarjeta de débito de 16 dígitos. Y el 2026-09-10 el bot lo mandó con dos
// dígitos cambiados (4152313477952941): la huésped transfirió y le
// regresaron el dinero.

it('distingue tarjeta, CLABE y número de cuenta', function (string $numero, string $tipo, string $etiqueta) {
    expect(BankAccountNumber::kind($numero))->toBe($tipo)
        ->and(BankAccountNumber::label($numero))->toBe($etiqueta);
})->with([
    'la tarjeta de cabañas' => ['4152314577952941', BankAccountNumber::CARD, 'Tarjeta de débito'],
    'tarjeta con espacios' => ['4152 3145 7795 2941', BankAccountNumber::CARD, 'Tarjeta de débito'],
    'CLABE' => ['032180000118359719', BankAccountNumber::CLABE, 'CLABE interbancaria'],
    'cuenta de 10' => ['0119870255', BankAccountNumber::ACCOUNT, 'Número de cuenta'],
]);

it('rechaza el número con dígitos cambiados que mandó el bot el 10 de septiembre', function () {
    expect(BankAccountNumber::isValid('4152313477952941'))->toBeFalse();
});

it('rechaza una CLABE con el dígito verificador equivocado', function () {
    expect(BankAccountNumber::isValid('032180000118359718'))->toBeFalse();
});

it('rechaza números que no son ni tarjeta ni CLABE ni cuenta', function (string $numero) {
    expect(BankAccountNumber::isValid($numero))->toBeFalse();
})->with(['12345', '4152314577952', '41523145779529411234']);

it('el bloque de una tarjeta advierte cómo transferirle', function () {
    $renglones = BankAccountNumber::blockLines([
        'bank' => 'BBVA Bancomer',
        'holder' => 'Jatziry Sofía Salazar Salazar',
        'clabe' => '4152314577952941',
    ]);

    expect($renglones)->toBe([
        '- Banco: BBVA Bancomer',
        '- Titular: Jatziry Sofía Salazar Salazar',
        '- Tarjeta de débito: 4152314577952941',
        '- Importante: es una tarjeta de débito. En tu app de banco elige transferir a tarjeta, no a CLABE ni a número de cuenta.',
    ]);
});

it('solo a las tarjetas les da la indicación para transferir', function () {
    expect(BankAccountNumber::guestHint('4152314577952941'))->toContain('elige transferir a tarjeta')
        ->and(BankAccountNumber::guestHint('032180000118359719'))->toBeNull()
        ->and(BankAccountNumber::guestHint('0119870255'))->toBeNull();
});
