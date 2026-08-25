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

// Recordatorios de llegada: 24 horas antes y el aviso del día de la
// llegada (N horas antes de la entrada, /ajustes/metodos-pago). Cada 15
// minutos para que el segundo llegue puntual.
Schedule::command('tenants:run reservations:arrival-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Llegadas confirmadas del día: el semáforo pasa solo a "reservada" cuando
// la fecha de una reserva pagada/confirmada llega (o cuando limpieza libera
// una habitación que tiene llegada hoy).
Schedule::command('tenants:run rooms:reserve-arrivals')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Cierre de día (reservas confirmadas cuya salida venció sin check-in) y
// limpieza automática sucia → limpieza → disponible según /ajustes/limpieza.
Schedule::command('tenants:run rooms:advance-housekeeping')
    ->everyMinute()
    ->withoutOverlapping();

// Check-in automático a la hora de llegada (modos auto/ambos de
// /ajustes/limpieza): crea la estancia y ocupa la habitación si el
// personal no registró la llegada.
Schedule::command('tenants:run reservations:auto-checkin')
    ->everyMinute()
    ->withoutOverlapping();

// Incidencias que pasaron su tiempo objetivo de atención: avisan a la
// campana una sola vez por ticket. Sin esto, una falla puede quedarse
// abierta semanas sin que nadie se entere.
Schedule::command('tenants:run incidents:check-overdue')
    ->everyFifteenMinutes()
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

// El archivo de la bandeja se vacía solo: lo archivado se elimina
// definitivamente a los 30 días (el staff también puede vaciarlo a mano).
Schedule::command('tenants:run conversations:prune-archived')
    ->dailyAt('04:45')
    ->withoutOverlapping();

// Horizonte de venta de experiencias con programación semanal: cada día
// se materializa el día que entra a la ventana (los cambios desde el
// panel regeneran al momento; esto es el rodillo).
Schedule::command('tenants:run experiences:generate-sessions')
    ->dailyAt('04:30')
    ->withoutOverlapping();
