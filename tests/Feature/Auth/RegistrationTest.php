<?php

// El registro público está deshabilitado a propósito: las cuentas las crea
// la plataforma (admin central / owners por invitación). Estos tests
// protegen esa decisión.

test('registration screen does not exist', function () {
    $this->get('/register')->assertNotFound();
});

test('registration route is not registered', function () {
    expect(\Illuminate\Support\Facades\Route::has('register'))->toBeFalse();
});
