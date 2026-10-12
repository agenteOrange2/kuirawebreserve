<?php

namespace App\Support;

/**
 * Una liga nunca termina pegada a un signo. WhatsApp (y Messenger,
 * Instagram, Telegram) toman el punto, la coma o los dos puntos que siguen a
 * la liga como parte de ella, y al abrirla la página no existe. Casos reales
 * cabañas (30 días al 12-oct-2026: 40 ligas así): "link seguro: {checkout}.
 * Al completar el pago…", "Cuéntanos en un minuto: {encuesta}. Te
 * esperamos…", "en {reserva}: entra con tu código…".
 *
 * Regla: el signo pegado al final de la liga se quita y, si el texto sigue
 * en el mismo renglón, pasa al renglón de abajo. Un paréntesis de cierre se
 * separa con un espacio en vez de quitarse, salvo que sea de la propia liga.
 */
class UrlSpacing
{
    protected const TRAILING = '.,;:!?)]}»"\'”’';

    public static function separate(string $text): string
    {
        if (! str_contains($text, '://')) {
            return $text;
        }

        if (! preg_match_all('~https?://[^\s<>"]+~u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $text;
        }

        $out = '';
        $cursor = 0;

        foreach ($matches[0] as [$url, $at]) {
            if ($at < $cursor) {
                continue;
            }

            $out .= substr($text, $cursor, $at - $cursor);
            $end = $at + strlen($url);

            $clean = $url;
            $closing = false;

            while ($clean !== '' && str_contains(self::TRAILING, mb_substr($clean, -1))) {
                $last = mb_substr($clean, -1);

                // "…/wiki/Algo_(desambiguación)": el paréntesis es de la liga.
                $pairs = [')' => '(', ']' => '[', '}' => '{'];
                if (isset($pairs[$last]) && substr_count($clean, $pairs[$last]) >= substr_count($clean, $last)) {
                    break;
                }

                $closing = $closing || in_array($last, [')', ']', '}', '»', '"', '”', '’', "'"], true);
                $clean = mb_substr($clean, 0, -1);
            }

            if ($clean === $url) {
                $out .= $url;
                $cursor = $end;

                continue;
            }

            $punct = substr($url, strlen($clean));
            $rest = substr($text, $end);
            $sameLine = ltrim(strtok($rest, "\n") ?: '', " \t");
            $continues = $rest !== '' && ! str_starts_with(ltrim($rest, " \t"), "\n") && $sameLine !== '';

            if ($closing && ! preg_match('/[.,;:!?]/u', $punct)) {
                // "(fotos aquí: https://…)" → "(fotos aquí: https://… )".
                $out .= $clean.' '.$punct;
                $cursor = $end;

                continue;
            }

            if ($continues) {
                // Lo que seguía en el renglón baja al siguiente, sin el signo.
                $out .= $clean."\n";
                $cursor = $end + (strlen($rest) - strlen(ltrim($rest, " \t")));
            } else {
                $out .= $clean;
                $cursor = $end;
            }
        }

        return $out.substr($text, $cursor);
    }
}
