<?php

namespace App\Services\Social;

use App\Models\SocialComment;

/**
 * Lo que un comentario dice sin lugar a dudas, decidido ANTES de la IA.
 *
 * En la revisión de cabañas (2026-09-28, 346 comentarios) el modelo fallaba
 * justo con los textos más cortos: el mismo "Inf" salió como compra, como
 * elogio y como spam en días distintos; "Inbox", "Información" y "¿dónde
 * es?" acabaron en spam, clientes que nadie atendió. Y etiquetar a un amigo
 * ("Karla Franco", "Raquel siii vamos") caía al azar en elogio — se publicó
 * "gracias por recomendarnos" a quien no nos recomendó —, en spam — 50
 * pendientes para el personal — o en compra, con un privado no pedido.
 *
 * Aquí solo se decide lo evidente; todo lo demás sigue yendo a la IA.
 */
class SocialIntentRules
{
    /** Más largo que esto ya no es "evidente": lo lee la IA. */
    protected const MAX_WORDS = 12;

    /** Pide información para hospedarse (texto ya sin acentos ni mayúsculas). */
    protected const INTENT = '/\b(inf|info|infor\w*|informes?|inbox|mp|md|precios?|presios?|costos?|cuanto|tarifas?|disponib\w*|reserv\w*|recerv\w*|apart\w*|donde (es|esta|estan|estas|queda|quedan|qeda|son|mero|se ubica\w*|se encuentra\w*)|^(x |por )?donde\b|ubicacion|ubicad\w*|ubican|me interesa|interesad[oa]|(me )?(quiero|kiero) (hospedar|ospedar|reservar)\w*|m quiero (hospedar|ospedar)|renta)\b/u';

    /**
     * Enojo o reclamo: aunque diga "reservar" ("les marco y no contestan
     * para reservar"), eso lo lee la IA, que sabe qué es una queja.
     */
    protected const COMPLAINT = '/\b(no contestan|no responden|nadie contesta|no me contestan|pesim\w*|mal servicio|estafa\w*|fraude|no lo recomiendo|no recomiendo|caris\w*|robo|groser\w*)\b/u';

    /** Plática con el amigo etiquetado, no con el hotel. */
    protected const FRIEND = '/\b(vamos|vamonos|hay que ir|k ir|que ir|mira|mire|miren|me llevas|llevame|me invitas|arres?|de una+|puesta|jalo|jalamos|ya nos vimos|amor|bebe|amiga?|amigo?)\b/u';

    /** Partículas que caben dentro de un nombre propio ("Cabañas Real de la Sierra"). */
    protected const NAME_PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'da', 'do', 'van', 'von'];

    /**
     * Palabras que se escriben con mayúscula al empezar pero no son nombres:
     * un "Yo" contestando "¿quién se apunta?" es interés, no una etiqueta.
     */
    protected const NOT_NAMES = ['yo', 'si', 'no', 'ok', 'hola', 'gracias', 'que', 'me', 'mi', 'te', 'el', 'es', 'esta', 'muy', 'bien', 'bonito', 'bonita', 'hermoso', 'hermosa', 'lindo', 'linda', 'excelente', 'super', 'wow', 'quiero', 'buenas', 'buenos', 'dias', 'tardes', 'noches'];

    /**
     * @return string|null SocialComment::CLASS_PURCHASE, SocialComment::CLASS_TAG
     *                     o null (que decida la IA)
     */
    public function classify(?string $body): ?string
    {
        $raw = trim((string) $body);

        if ($raw === '') {
            return null;
        }

        $plain = $this->plain($raw);
        $words = $plain === '' ? [] : explode(' ', $plain);

        if (count($words) > self::MAX_WORDS || preg_match(self::COMPLAINT, $plain)) {
            return null;
        }

        if (preg_match(self::INTENT, $plain) || preg_match('/^\s*\$+\s*$/u', $raw)) {
            return SocialComment::CLASS_PURCHASE;
        }

        // Respuesta a una dinámica de la publicación ("1", "3", "opción 2").
        if (preg_match('/^(opcion\s*)?\d{1,2}$/u', $plain)) {
            return SocialComment::CLASS_TAG;
        }

        if ($this->onlyNames($raw) || preg_match(self::FRIEND, $plain)) {
            return SocialComment::CLASS_TAG;
        }

        return null;
    }

    /**
     * ¿Se puede publicar "gracias por recomendarnos" sin quedar mal? Solo si
     * el comentario dice algo bueno del lugar y no trae un "pero" ni una
     * queja escondida. Caso real: a "me encantaría pero ya se metió la pache
     * pache a la alberca, necesitarían desinfectar" se le contestó "¡Qué
     * alegría leer tu comentario!".
     */
    public function praiseIsSafe(?string $body): bool
    {
        $plain = $this->plain((string) $body);

        if (preg_match('/\b(pero|aunque|sin embargo|sucio\w*|feo\w*|mal servicio|mala atencion|malo|mala|desinfect\w*|asco\w*|caro\w*|caris\w*|no me|nada|lastima|decepcion\w*|jaja\w*|jeje\w*)\b/u', $plain)) {
            return false;
        }

        return (bool) preg_match('/\b(hermos\w*|bonit\w*|lind\w*|precios\w*|bell\w*|excelente\w*|recomend\w*|encant\w*|padr[ei]\w*|chingon\w*|super\w*|genial\w*|increible\w*|maravill\w*|limpi\w*|amabl\w*|atencion|atent\w*|servicio|gusto|gust[oó]|disfrut\w*|tranquil\w*|paz|vista\w*|10 de 10|me gusta|nos gusto|la pase|la pasamos|volver\w*|regres\w*|inolvidable\w*|perron\w*)\b/u', $plain);
    }

    /** Minúsculas, sin acentos, sin emojis ni signos: solo palabras. */
    protected function plain(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Solo nombres de personas: cada palabra empieza con mayúscula (salvo
     * "de", "la"...). Así llegan las etiquetas de Facebook: "Karla Franco",
     * "Cristina Saucedo Yvette Trevizo".
     */
    protected function onlyNames(string $raw): bool
    {
        $clean = trim(preg_replace('/[^\p{L}\s\'\.]+/u', ' ', $raw) ?? '');
        $tokens = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === [] || count($tokens) > 8 || preg_match('/[\d?¿]/u', $raw)) {
            return false;
        }

        // Todo en mayúsculas es gritar, no nombrar ("LA RIFA PARA CUANDO ES").
        if (count($tokens) > 1 && mb_strtoupper($clean) === $clean) {
            return false;
        }

        $capitalized = 0;

        foreach ($tokens as $token) {
            $token = trim($token, "'.");

            if ($token === '') {
                continue;
            }

            $lower = mb_strtolower($token);

            if (in_array(strtr($lower, ['í' => 'i', 'é' => 'e', 'á' => 'a']), self::NOT_NAMES, true)) {
                return false;
            }

            if (in_array($lower, self::NAME_PARTICLES, true)) {
                continue;
            }

            if (! preg_match('/^\p{Lu}/u', $token)) {
                return false;
            }

            $capitalized++;
        }

        return $capitalized > 0;
    }
}
