<?php

namespace App\Services\Channels;

/**
 * Cada canal tiene un tope de caracteres y lo que lo pasa NO se entrega: la
 * API rechaza el mensaje entero.
 *
 * Caso real cabañas 2026-09-15 (conv. 830): el bot contestó con 4,457
 * caracteres —la lista completa de cabañas— y la Cloud API devolvió "Param
 * text.body must be at most 4096 characters long". El mensaje quedó
 * guardado en la bandeja como si hubiera salido y el huésped se quedó
 * esperando una respuesta que nunca llegó.
 *
 * Aquí el texto se parte en trozos que sí entran, cortando por párrafos y,
 * si hace falta, por oraciones: el huésped recibe dos mensajes seguidos,
 * como cuando escribe una persona, en vez de ninguno.
 */
class MessageChunker
{
    /** Topes reales del lado del proveedor. */
    public const LIMITS = [
        'whatsapp' => 4096,
        'whatsapp_evolution' => 4096,
        'telegram' => 4096,
        'messenger' => 2000,
        'instagram' => 1000,
    ];

    /** Margen: el proveedor cuenta emojis y saltos distinto que mb_strlen. */
    public const MARGIN = 96;

    public static function limitFor(?string $channel): int
    {
        return max(200, (self::LIMITS[$channel] ?? 4096) - self::MARGIN);
    }

    /**
     * @return array<int, string> Vacío si no hay nada que mandar.
     */
    public static function split(string $text, int $limit): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $actual = '';

        foreach (self::pieces($text, $limit) as $pieza) {
            $candidato = $actual === '' ? $pieza : $actual."\n\n".$pieza;

            if (mb_strlen($candidato) <= $limit) {
                $actual = $candidato;

                continue;
            }

            if ($actual !== '') {
                $chunks[] = $actual;
            }

            $actual = $pieza;
        }

        if ($actual !== '') {
            $chunks[] = $actual;
        }

        return $chunks;
    }

    /**
     * Piezas que caben por sí solas: párrafos; el párrafo que no entra se
     * parte por oraciones, y la oración que tampoco entra (un enlace
     * larguísimo) se corta a lo bruto.
     *
     * @return array<int, string>
     */
    protected static function pieces(string $text, int $limit): array
    {
        $piezas = [];

        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $parrafo) {
            $parrafo = trim($parrafo);

            if ($parrafo === '') {
                continue;
            }

            if (mb_strlen($parrafo) <= $limit) {
                $piezas[] = $parrafo;

                continue;
            }

            $acumulado = '';

            foreach (preg_split('/(?<=[.!?:])\s+|\n/u', $parrafo) ?: [] as $oracion) {
                $oracion = trim($oracion);

                if ($oracion === '') {
                    continue;
                }

                while (mb_strlen($oracion) > $limit) {
                    if ($acumulado !== '') {
                        $piezas[] = $acumulado;
                        $acumulado = '';
                    }

                    $piezas[] = mb_substr($oracion, 0, $limit);
                    $oracion = mb_substr($oracion, $limit);
                }

                $candidato = $acumulado === '' ? $oracion : $acumulado."\n".$oracion;

                if (mb_strlen($candidato) <= $limit) {
                    $acumulado = $candidato;

                    continue;
                }

                $piezas[] = $acumulado;
                $acumulado = $oracion;
            }

            if ($acumulado !== '') {
                $piezas[] = $acumulado;
            }
        }

        return $piezas;
    }
}
