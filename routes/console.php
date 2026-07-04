<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Corre en todos los tenants: cancela holds vencidos (el motor de
// disponibilidad ya los ignora; esto es limpieza para el panel).
Schedule::command('tenants:run reservations:expire-holds')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Estancias cuyo tiempo venció (+ gracia): check-out automático y la
// habitación pasa a "sucia" para housekeeping (spec-profundidad §2.4).
Schedule::command('tenants:run stays:auto-checkout')
    ->everyMinute()
    ->withoutOverlapping();

// El bot retoma conversaciones: holds por vencer/vencidos, reservas
// confirmadas y cotizaciones abandonadas (plantillas, sin LLM).
Schedule::command('tenants:run conversations:follow-up')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Resumen rodante de conversaciones inactivas (memoria del bot).
Schedule::command('tenants:run conversations:summarize')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
