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

// Solicitudes de cobro cuya vigencia pasó sin pagarse (spec-pagos §4.1).
Schedule::command('tenants:run payments:expire-requests')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Saldos por vencer: emite el cobro, recuerda a las 24 h y gestiona
// vencidos según la política del hotel (spec-pagos §7.2).
Schedule::command('tenants:run payments:collect-balance')
    ->hourly()
    ->withoutOverlapping();

// Recordatorio de llegada: un aviso a reservas confirmadas que llegan en
// las próximas 24 horas (conversación o WhatsApp/correo directo).
Schedule::command('tenants:run reservations:arrival-reminders')
    ->hourly()
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

// Horizonte de venta de experiencias con programación semanal: cada día
// se materializa el día que entra a la ventana (los cambios desde el
// panel regeneran al momento; esto es el rodillo).
Schedule::command('tenants:run experiences:generate-sessions')
    ->dailyAt('04:30')
    ->withoutOverlapping();
