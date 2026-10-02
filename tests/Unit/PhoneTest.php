<?php

use App\Support\Phone;

// Auditoría del VPS (2026-09-24, cabañas): 86 conversaciones entran desde
// números de Estados Unidos —65 con lada 915, El Paso— y 56 de 417 huéspedes
// tienen teléfono gringo. Al armarles "52 + 915…" WhatsApp los rechazaba con
// el error 131026: nunca recibieron confirmación, contrato ni recordatorios.

it('los 10 dígitos mexicanos siguen llevando la lada del hotel', function (string $tecleado, string $espera) {
    expect(Phone::whatsapp($tecleado))->toBe($espera);
})->with([
    'Juárez' => ['6561234567', '526561234567'],
    'Chihuahua' => ['6141234567', '526141234567'],
    'con espacios y guiones' => ['656 123-4567', '526561234567'],
    'CDMX' => ['5512345678', '525512345678'],
]);

it('la frontera se manda a Estados Unidos', function (string $tecleado, string $espera) {
    expect(Phone::whatsapp($tecleado))->toBe($espera);
})->with([
    'El Paso' => ['9153048512', '19153048512'],
    'Las Cruces' => ['5755551234', '15755551234'],
    'Nuevo México' => ['5054909534', '15054909534'],
    'Lubbock' => ['8065551234', '18065551234'],
    'Midland' => ['4325551234', '14325551234'],
]);

it('respeta el número que ya trae su lada', function (string $tecleado, string $espera) {
    expect(Phone::whatsapp($tecleado))->toBe($espera);
})->with([
    'gringo completo' => ['19157041725', '19157041725'],
    'mexicano completo' => ['526561234567', '526561234567'],
    'mexicano con el 1 viejo' => ['5216561234567', '5216561234567'],
]);

it('repara el destrozo que dejó el defecto viejo', function (string $guardado, string $espera) {
    expect(Phone::whatsapp($guardado))->toBe($espera);
})->with([
    // Así quedaron guardados en el VPS los de El Paso.
    'con 52' => ['529153048512', '19153048512'],
    'con 521' => ['5219153048512', '19153048512'],
]);

it('respeta la lada que el hotel tenga configurada', function () {
    expect(Phone::whatsapp('5551234567', '1'))->toBe('15551234567');
});

it('no inventa nada con un número incompleto', function () {
    expect(Phone::whatsapp('656'))->toBe('656')
        ->and(Phone::whatsapp(''))->toBe('');
});
