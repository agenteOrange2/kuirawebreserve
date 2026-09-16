<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Folio corto del error para la petición en curso.
 *
 * El mismo valor viaja a dos lugares: al contexto del log (lo engancha
 * bootstrap/app.php con $exceptions->context()) y a la pantalla que ve el
 * usuario. Así, cuando alguien del hotel reporta "me salió un error y dice
 * K7F3QA", soporte encuentra en el log esa línea exacta en vez de adivinar
 * cuál de los cien errores del día era.
 *
 * Se calcula una sola vez por petición: si una misma petición dispara dos
 * excepciones, ambas quedan bajo el mismo folio (es el mismo incidente).
 */
class ErrorReference
{
    protected static ?string $current = null;

    public static function current(): string
    {
        return static::$current ??= strtoupper(Str::random(6));
    }

    /**
     * Solo para las pruebas: olvida el folio de la petición anterior.
     */
    public static function forget(): void
    {
        static::$current = null;
    }
}
