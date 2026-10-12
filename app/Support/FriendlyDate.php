<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Fechas como las dice el mostrador: "Hoy 15:37", "Mañana 12:00",
 * "dom 12 oct 15:00". Las tablas de reservas decían "09/10/2026 15:37 /
 * sale 10/10/2026 12:00" y en la capacitación nadie las leía de un vistazo.
 *
 * Se arma en el servidor y no en el navegador: la hora de la computadora
 * del mostrador puede no ser la del hotel, y "hoy" depende de eso.
 */
class FriendlyDate
{
    /** "Hoy 15:37", "Mañana 15:00", "Ayer 12:00", "dom 12 oct 15:00". */
    public static function moment(?CarbonInterface $at, ?CarbonInterface $now = null): ?string
    {
        if (! $at) {
            return null;
        }

        return self::dayWord($at, $now, short: true).' '.$at->format('H:i');
    }

    /**
     * Rótulo del grupo de un día en la tabla: "Hoy · viernes 9 oct",
     * "Mañana · sábado 10 oct", "Domingo 12 oct".
     */
    public static function day(CarbonInterface $at, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        $long = self::clean(Carbon::instance($at)->locale('es')->isoFormat('dddd D MMM'));
        $long .= $at->year !== $now->year ? ' '.$at->year : '';

        if ($at->isSameDay($now)) {
            return 'Hoy · '.$long;
        }
        if ($at->isSameDay($now->copy()->addDay())) {
            return 'Mañana · '.$long;
        }
        if ($at->isSameDay($now->copy()->subDay())) {
            return 'Ayer · '.$long;
        }

        return mb_strtoupper(mb_substr($long, 0, 1)).mb_substr($long, 1);
    }

    /**
     * Cuánto dura: "1 noche", "3 noches"; si entra y sale el mismo día,
     * en horas ("5 h") o minutos ("40 min").
     */
    public static function span(?CarbonInterface $start, ?CarbonInterface $end): ?string
    {
        if (! $start || ! $end || $end->lte($start)) {
            return null;
        }

        $nights = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay());

        if ($nights >= 1) {
            return $nights.' '.($nights === 1 ? 'noche' : 'noches');
        }

        $minutes = (int) round($start->diffInMinutes($end));

        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h';
    }

    /** "Hoy", "Mañana", "Ayer" o "dom 12 oct" (con el año si no es este). */
    protected static function dayWord(CarbonInterface $at, ?CarbonInterface $now, bool $short): string
    {
        $now ??= now();

        if ($at->isSameDay($now)) {
            return 'Hoy';
        }
        if ($at->isSameDay($now->copy()->addDay())) {
            return 'Mañana';
        }
        if ($at->isSameDay($now->copy()->subDay())) {
            return 'Ayer';
        }

        $text = self::clean(Carbon::instance($at)->locale('es')->isoFormat($short ? 'ddd D MMM' : 'dddd D MMMM'));

        return $at->year !== $now->year ? $text.' '.$at->year : $text;
    }

    /** Carbon en español pone punto a las abreviaturas ("dom.", "oct."). */
    protected static function clean(string $text): string
    {
        return str_replace('.', '', $text);
    }
}
