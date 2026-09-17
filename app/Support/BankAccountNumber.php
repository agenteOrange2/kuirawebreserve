<?php

namespace App\Support;

/**
 * Qué es el número que el hotel guardó para recibir transferencias, y cómo
 * decírselo al huésped.
 *
 * Caso real cabañas 2026-09-16: la configuración traía "clabe":
 * "4152314577952941" — 16 dígitos, o sea una TARJETA de débito BBVA, no una
 * CLABE (18) ni un número de cuenta (10). El bot lo anunciaba como
 * "Cuenta: 4152314577952941" y quien lo capturaba en su app como cuenta o
 * CLABE recibía "número inválido". Y el 2026-09-10 el bot lo escribió con
 * dos dígitos cambiados (4152313477952941): el dígito verificador de las
 * tarjetas lo habría delatado.
 */
final class BankAccountNumber
{
    public const CLABE = 'clabe';

    public const CARD = 'card';

    public const ACCOUNT = 'account';

    public const UNKNOWN = 'unknown';

    public static function digits(?string $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    public static function kind(?string $value): string
    {
        $digits = self::digits($value);

        return match (true) {
            strlen($digits) === 18 && self::isValidClabe($digits) => self::CLABE,
            strlen($digits) === 16 && self::passesLuhn($digits) => self::CARD,
            in_array(strlen($digits), [10, 11], true) => self::ACCOUNT,
            default => self::UNKNOWN,
        };
    }

    /** Lo que el huésped tiene que elegir en su app de banco. */
    public static function label(?string $value): string
    {
        return match (self::kind($value)) {
            self::CLABE => 'CLABE interbancaria',
            self::CARD => 'Tarjeta de débito',
            self::ACCOUNT => 'Número de cuenta',
            default => 'Cuenta',
        };
    }

    /** Lo que el huésped necesita saber para que la transferencia pase. */
    public static function guestHint(?string $value): ?string
    {
        return self::kind($value) === self::CARD
            ? 'Es una tarjeta de débito: en tu app de banco elige transferir a tarjeta, no a CLABE ni a número de cuenta.'
            : null;
    }

    /** Una CLABE, una tarjeta o una cuenta bien formadas: sin esto no se guarda. */
    public static function isValid(?string $value): bool
    {
        return self::kind($value) !== self::UNKNOWN;
    }

    /** Validadores por campo, para la pantalla que los captura por separado. */
    public static function isClabe(?string $value): bool
    {
        return self::kind($value) === self::CLABE;
    }

    public static function isCard(?string $value): bool
    {
        return self::kind($value) === self::CARD;
    }

    public static function isAccountNumber(?string $value): bool
    {
        return self::kind($value) === self::ACCOUNT;
    }

    /**
     * Los números de UNA cuenta, ya clasificados y con lo que hay que decirle
     * al huésped de cada uno. Es el ÚNICO lugar donde se juega con las llaves:
     * la etiqueta sale siempre de aquí, nunca del nombre del campo (hay hoteles
     * con 18 dígitos guardados en `clabe` que NO son una CLABE válida).
     *
     * `clabe` es además la llave legada: antes ahí se escribía cualquier
     * número. Si lo que trae no es una CLABE, se reclasifica al leer — así los
     * registros viejos siguen funcionando sin migrar nada.
     *
     * @param  array<string, mixed>  $account
     * @return array{bank: string, holder: string, clabe: ?array, card: ?array, account: ?array, guest: list<array>, primary: ?array, alternate: ?array, internal: ?array, digits: list<string>, guestDigits: list<string>, legacy: bool}
     */
    public static function normalize(array $account): array
    {
        $bank = trim((string) ($account['bank'] ?? ''));
        $holder = trim((string) ($account['holder'] ?? ''));

        $enClabe = self::digits((string) ($account['clabe'] ?? ''));
        $card = self::digits((string) ($account['card'] ?? $account['tarjeta'] ?? ''));
        // La llave NUEVA: esta cuenta es interna, no se le da al huésped.
        $interna = self::digits((string) ($account['account'] ?? $account['cuenta'] ?? ''));

        $clabe = '';
        $legado = '';
        $legacy = false;

        if ($enClabe !== '') {
            if (self::kind($enClabe) === self::CLABE) {
                $clabe = $enClabe;
            } elseif (self::kind($enClabe) === self::CARD && $card === '') {
                // Una tarjeta guardada en la llave vieja: sigue siendo tarjeta.
                $card = $enClabe;
                $legacy = true;
            } else {
                // Una cuenta o un número que no clasifica: es lo único que ese
                // hotel capturó, así que el huésped lo sigue viendo.
                $legado = $enClabe;
                $legacy = true;
            }
        }

        $num = fn (string $digits): ?array => $digits === '' ? null : [
            'number' => $digits,
            'kind' => self::kind($digits),
            'label' => self::label($digits),
            'hint' => self::guestHint($digits),
        ];

        $guest = array_values(array_filter([$num($clabe), $num($card), $num($legado)]));
        $internal = $num($interna);

        return [
            'bank' => $bank,
            'holder' => $holder,
            'clabe' => $num($clabe),
            'card' => $num($card),
            'account' => $internal ?? $num($legado),
            'guest' => $guest,
            'primary' => $guest[0] ?? null,
            'alternate' => $guest[1] ?? null,
            'internal' => $internal,
            // Los tres, para reconocer a dónde llegó un comprobante.
            'digits' => array_values(array_filter([$clabe, $card, $legado, $interna])),
            // Solo lo que el huésped pudo recibir: con esto el bot decide si un
            // número escrito en un mensaje es legítimo o inventado.
            'guestDigits' => array_map(fn (array $item) => $item['number'], $guest),
            'legacy' => $legacy,
        ];
    }

    /**
     * Los campos del formulario del panel, con el número legado ya puesto en
     * la ranura que le toca: el hotel abre una cuenta vieja y ve su tarjeta en
     * "Tarjeta", no en "CLABE".
     *
     * @param  array<string, mixed>  $account
     * @return array{bank: string, holder: string, clabe: string, card: string, account: string, active: bool}
     */
    public static function formFields(array $account): array
    {
        $activa = (bool) ($account['active'] ?? true);
        $bank = trim((string) ($account['bank'] ?? ''));
        $holder = trim((string) ($account['holder'] ?? ''));

        // Ya viene capturada por campos: se respeta TAL CUAL. Reinterpretarla
        // aquí escondería un número mal escrito en vez de que la validación lo
        // rechace y el hotel lo corrija.
        if (array_key_exists('card', $account) || array_key_exists('account', $account)) {
            return [
                'bank' => $bank,
                'holder' => $holder,
                'clabe' => self::digits((string) ($account['clabe'] ?? '')),
                'card' => self::digits((string) ($account['card'] ?? '')),
                'account' => self::digits((string) ($account['account'] ?? '')),
                'active' => $activa,
            ];
        }

        // Registro viejo: el número que traía la llave `clabe` se coloca en la
        // ranura que le toca. Si no clasifica se queda a la vista en CLABE, y
        // al guardar la validación obliga a corregirlo.
        $datos = self::normalize($account);

        return [
            'bank' => $bank,
            'holder' => $holder,
            'clabe' => (string) ($datos['clabe']['number'] ?? (
                ($datos['card'] === null && ($datos['primary']['kind'] ?? null) === self::UNKNOWN)
                    ? ($datos['primary']['number'] ?? '')
                    : ''
            )),
            'card' => (string) ($datos['card']['number'] ?? ''),
            'account' => (string) (($datos['primary']['kind'] ?? null) === self::ACCOUNT
                ? $datos['primary']['number']
                : ''),
            'active' => $activa,
        ];
    }

    /**
     * Una línea para los avisos: "BBVA, titular X, CLABE interbancaria 012…
     * (o tarjeta de débito 4152…)". Antes cada aviso armaba su propio texto
     * con la palabra "cuenta" fija, y anunciaba una tarjeta como cuenta.
     *
     * @param  array<string, mixed>  $account
     */
    public static function inlineSummary(array $account): string
    {
        $datos = self::normalize($account);

        if ($datos['primary'] === null) {
            return trim($datos['bank'].($datos['holder'] !== '' ? ', titular '.$datos['holder'] : ''), ', ');
        }

        $linea = trim(implode(', ', array_filter([
            $datos['bank'],
            $datos['holder'] !== '' ? 'titular '.$datos['holder'] : null,
            $datos['primary']['label'].' '.$datos['primary']['number'],
        ])));

        if ($datos['alternate'] !== null) {
            $linea .= ' (o '.mb_strtolower($datos['alternate']['label']).' '.$datos['alternate']['number'].')';
        }

        return $linea;
    }

    /**
     * El bloque que se le entrega al huésped, siempre igual y siempre desde
     * la configuración. El bot nunca lo redacta.
     *
     * @param  array<string, mixed>  $account
     * @return list<string>
     */
    public static function blockLines(array $account): array
    {
        $datos = self::normalize($account);

        $lines = array_values(array_filter([
            $datos['bank'] !== '' ? '- Banco: '.$datos['bank'] : null,
            $datos['holder'] !== '' ? '- Titular: '.$datos['holder'] : null,
            $datos['primary'] !== null ? '- '.$datos['primary']['label'].': '.$datos['primary']['number'] : null,
        ]));

        if (($datos['primary']['kind'] ?? null) === self::CARD) {
            $lines[] = '- Importante: es una tarjeta de débito. En tu app de banco elige transferir a tarjeta, no a CLABE ni a número de cuenta.';
        }

        // La alternativa resuelve el caso real: "mi app solo me deja
        // transferir a tarjeta". El número interno NUNCA entra aquí.
        if ($datos['alternate'] !== null) {
            $lines[] = '- Si tu app solo permite transferir a tarjeta: '
                .$datos['alternate']['label'].' '.$datos['alternate']['number'];
        }

        return $lines;
    }

    /** Dígito verificador de la CLABE: pesos 3, 7, 1 sobre los primeros 17. */
    public static function isValidClabe(string $digits): bool
    {
        if (strlen($digits) !== 18) {
            return false;
        }

        $weights = [3, 7, 1];
        $sum = 0;

        for ($i = 0; $i < 17; $i++) {
            $sum += ((int) $digits[$i] * $weights[$i % 3]) % 10;
        }

        return (10 - ($sum % 10)) % 10 === (int) $digits[17];
    }

    /** Algoritmo de Luhn: el dígito verificador de las tarjetas. */
    public static function passesLuhn(string $digits): bool
    {
        if ($digits === '' || ! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];

            if ($double) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }

            $sum += $n;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
