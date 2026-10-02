<?php

namespace App\Support;

/**
 * Armar un número para WhatsApp cuando el huésped solo dejó 10 dígitos.
 *
 * El wizard y la ficha piden "10 dígitos" y hasta el 2026-09-24 se les
 * anteponía SIEMPRE la lada del hotel (52). Para Juárez eso es correcto… pero
 * la mitad de la clientela cruza el puente: en el VPS de cabañas hay **86
 * conversaciones desde números de Estados Unidos** (65 de ellas con lada 915,
 * El Paso) y **56 de 417 huéspedes** con teléfono gringo. A todos ellos el
 * sistema les armaba "52 + 915…", que no existe: 88 entregas rechazadas con
 * el error 131026 (número no entregable). Nunca recibieron su confirmación,
 * su contrato ni sus recordatorios.
 *
 * La lada NO se adivina por reglas generales: 614 es Chihuahua en México y
 * Columbus, Ohio en Estados Unidos, así que una tabla completa de códigos
 * gringos rompería números mexicanos. Solo se asumen como gringas las ladas
 * de la franja fronteriza que de verdad le escriben a este hotel, y que en
 * todo el historial NUNCA aparecieron como mexicanas (52 + esa lada = 0
 * conversaciones):
 *
 *   915 El Paso · 575 y 505 Nuevo México · 806 Lubbock/Amarillo · 432 Midland
 *
 * Cualquier otra cosa sigue tomando la lada del hotel, como siempre.
 */
class Phone
{
    /** @var array<int, string> */
    public const US_BORDER_AREA_CODES = ['915', '575', '505', '806', '432'];

    /**
     * Número listo para WhatsApp (solo dígitos, con lada de país).
     *
     * @param  string  $default  Lada del hotel (`phone_country_code`).
     */
    public static function whatsapp(string $raw, string $default = '52'): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        // Ya trae lada de país: se respeta tal cual… salvo el destrozo que
        // dejó el defecto viejo, "52" pegado a un número gringo.
        if (strlen($digits) > 10) {
            return self::repairMexicanPrefix($digits);
        }

        if (strlen($digits) < 10) {
            return $digits; // incompleto; quien llama ya valida el largo
        }

        if (in_array(substr($digits, 0, 3), self::US_BORDER_AREA_CODES, true)) {
            return '1'.$digits;
        }

        $code = preg_replace('/\D+/', '', $default !== '' ? $default : '52') ?? '52';

        return $code.$digits;
    }

    /**
     * "529153048512" y "5219153048512" son el teléfono de El Paso que el
     * defecto viejo dejó guardado con lada mexicana. Se devuelven como
     * "1915…", que es a donde de verdad hay que escribirle.
     */
    protected static function repairMexicanPrefix(string $digits): string
    {
        foreach (['52', '521'] as $prefijo) {
            if (! str_starts_with($digits, $prefijo)) {
                continue;
            }

            $resto = substr($digits, strlen($prefijo));

            if (strlen($resto) === 10 && in_array(substr($resto, 0, 3), self::US_BORDER_AREA_CODES, true)) {
                return '1'.$resto;
            }
        }

        return $digits;
    }
}
