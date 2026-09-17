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

// ---------------------------------------------- los tres campos separados

/** Los datos reales de cabañas, ya capturados por separado. */
function cuentaDeCabanas(): array
{
    return [
        'bank' => 'BBVA',
        'holder' => 'Jatziry Sofía Salazar Salazar',
        'clabe' => '012164015648463025',
        'card' => '4152314577952941',
        'account' => '1564846302',
        'active' => true,
    ];
}

it('con los tres campos da la CLABE primero y la tarjeta como alternativa', function () {
    $datos = BankAccountNumber::normalize(cuentaDeCabanas());

    expect($datos['primary']['number'])->toBe('012164015648463025')
        ->and($datos['primary']['label'])->toBe('CLABE interbancaria')
        ->and($datos['alternate']['number'])->toBe('4152314577952941')
        ->and($datos['alternate']['label'])->toBe('Tarjeta de débito')
        // La cuenta es interna: no viaja al huésped.
        ->and($datos['internal']['number'])->toBe('1564846302')
        ->and($datos['guestDigits'])->toBe(['012164015648463025', '4152314577952941'])
        ->and($datos['digits'])->toContain('1564846302')
        ->and($datos['legacy'])->toBeFalse();
});

it('el bloque lleva la alternativa y nunca el número interno', function () {
    $renglones = BankAccountNumber::blockLines(cuentaDeCabanas());

    expect($renglones)->toBe([
        '- Banco: BBVA',
        '- Titular: Jatziry Sofía Salazar Salazar',
        '- CLABE interbancaria: 012164015648463025',
        '- Si tu app solo permite transferir a tarjeta: Tarjeta de débito 4152314577952941',
    ])
        // La cuenta interna no sale como dato propio. (No se puede buscar el
        // número a secas: la CLABE lo lleva adentro, por construcción.)
        ->and(implode(' ', $renglones))->not->toContain('Número de cuenta');
});

it('la línea de los avisos dice qué es cada número', function () {
    $linea = BankAccountNumber::inlineSummary(cuentaDeCabanas());

    expect($linea)->toBe('BBVA, titular Jatziry Sofía Salazar Salazar, CLABE interbancaria 012164015648463025 (o tarjeta de débito 4152314577952941)')
        ->and($linea)->not->toContain('Número de cuenta');
});

// -------------------------------------------- compatibilidad con lo viejo

it('una tarjeta guardada en la llave vieja sigue siendo tarjeta', function () {
    $datos = BankAccountNumber::normalize([
        'bank' => 'BBVA Bancomer',
        'holder' => 'Jatziry Sofía Salazar Salazar',
        'clabe' => '4152314577952941',
    ]);

    expect($datos['clabe'])->toBeNull()
        ->and($datos['card']['number'])->toBe('4152314577952941')
        ->and($datos['primary']['label'])->toBe('Tarjeta de débito')
        ->and($datos['internal'])->toBeNull()
        ->and($datos['legacy'])->toBeTrue();

    // Y el formulario la muestra en SU campo, no en el de CLABE.
    $campos = BankAccountNumber::formFields(['bank' => 'BBVA', 'holder' => 'X', 'clabe' => '4152314577952941']);

    expect($campos['card'])->toBe('4152314577952941')
        ->and($campos['clabe'])->toBe('')
        ->and($campos['account'])->toBe('');
});

it('un número legado que no clasifica sigue viéndose, con etiqueta genérica', function (string $numero) {
    $datos = BankAccountNumber::normalize(['bank' => 'BBVA', 'holder' => 'X', 'clabe' => $numero]);

    expect($datos['primary']['number'])->toBe($numero)
        ->and($datos['primary']['label'])->toBe('Cuenta')
        ->and($datos['guestDigits'])->toBe([$numero]);
})->with([
    'el de los fixtures' => ['012345678901234567'],
    'uno corto' => ['0123'],
]);

it('una cuenta guardada en la llave vieja sigue siendo visible', function () {
    // Es lo único que ese hotel capturó: ocultarla lo dejaría sin datos.
    $datos = BankAccountNumber::normalize(['bank' => 'BBVA', 'holder' => 'X', 'clabe' => '0119870255']);

    expect($datos['primary']['number'])->toBe('0119870255')
        ->and($datos['primary']['label'])->toBe('Número de cuenta')
        ->and($datos['guestDigits'])->toBe(['0119870255']);
});

it('la cuenta capturada en su propio campo NO se le da al huésped', function () {
    $datos = BankAccountNumber::normalize([
        'bank' => 'BBVA', 'holder' => 'X',
        'clabe' => '012164015648463025',
        'account' => '1564846302',
    ]);

    expect($datos['guestDigits'])->toBe(['012164015648463025'])
        ->and($datos['internal']['number'])->toBe('1564846302')
        ->and(BankAccountNumber::blockLines([
            'bank' => 'BBVA', 'holder' => 'X',
            'clabe' => '012164015648463025', 'account' => '1564846302',
        ]))->toBe([
            '- Banco: BBVA',
            '- Titular: X',
            '- CLABE interbancaria: 012164015648463025',
        ]);
});

it('valida cada campo por separado', function () {
    expect(BankAccountNumber::isClabe('012164015648463025'))->toBeTrue()
        ->and(BankAccountNumber::isClabe('012164015648463024'))->toBeFalse()
        ->and(BankAccountNumber::isClabe('4152314577952941'))->toBeFalse()
        ->and(BankAccountNumber::isCard('4152314577952941'))->toBeTrue()
        ->and(BankAccountNumber::isCard('4152313477952941'))->toBeFalse()
        ->and(BankAccountNumber::isAccountNumber('1564846302'))->toBeTrue()
        ->and(BankAccountNumber::isAccountNumber('156484630212'))->toBeFalse();
});
