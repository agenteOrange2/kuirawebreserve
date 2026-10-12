<?php

use App\Support\FriendlyDate;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Viernes 9 de octubre de 2026, 11:00 en el hotel.
    Carbon::setTestNow(Carbon::parse('2026-10-09 11:00:00'));
});

afterEach(fn () => Carbon::setTestNow());

it('dice hoy, mañana y ayer con la hora', function () {
    expect(FriendlyDate::moment(Carbon::parse('2026-10-09 15:37')))->toBe('Hoy 15:37')
        ->and(FriendlyDate::moment(Carbon::parse('2026-10-10 12:00')))->toBe('Mañana 12:00')
        ->and(FriendlyDate::moment(Carbon::parse('2026-10-08 09:05')))->toBe('Ayer 09:05')
        ->and(FriendlyDate::moment(null))->toBeNull();
});

it('los demás días van con su nombre corto, sin puntos, y el año solo si no es este', function () {
    expect(FriendlyDate::moment(Carbon::parse('2026-10-11 15:00')))->toBe('dom 11 oct 15:00')
        ->and(FriendlyDate::moment(Carbon::parse('2027-01-04 15:00')))->toBe('lun 4 ene 2027 15:00');
});

it('el rótulo del grupo nombra el día completo', function () {
    expect(FriendlyDate::day(Carbon::parse('2026-10-09 18:00')))->toBe('Hoy · viernes 9 oct')
        ->and(FriendlyDate::day(Carbon::parse('2026-10-10 08:00')))->toBe('Mañana · sábado 10 oct')
        ->and(FriendlyDate::day(Carbon::parse('2026-10-12 08:00')))->toBe('Lunes 12 oct');
});

it('la duración se dice en noches, o en horas si es el mismo día', function () {
    expect(FriendlyDate::span(Carbon::parse('2026-10-09 15:00'), Carbon::parse('2026-10-10 12:00')))->toBe('1 noche')
        ->and(FriendlyDate::span(Carbon::parse('2026-10-09 15:00'), Carbon::parse('2026-10-12 12:00')))->toBe('3 noches')
        ->and(FriendlyDate::span(Carbon::parse('2026-10-09 15:00'), Carbon::parse('2026-10-09 20:00')))->toBe('5 h')
        ->and(FriendlyDate::span(Carbon::parse('2026-10-09 15:00'), Carbon::parse('2026-10-09 15:40')))->toBe('40 min')
        ->and(FriendlyDate::span(Carbon::parse('2026-10-09 15:00'), Carbon::parse('2026-10-09 14:00')))->toBeNull();
});
