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

    /**
     * El bloque que se le entrega al huésped, siempre igual y siempre desde
     * la configuración. El bot nunca lo redacta.
     *
     * @param  array<string, mixed>  $account
     * @return list<string>
     */
    public static function blockLines(array $account): array
    {
        $number = self::digits((string) ($account['clabe'] ?? $account['account'] ?? $account['cuenta'] ?? $account['card'] ?? ''));

        $lines = array_values(array_filter([
            ! empty($account['bank']) ? '- Banco: '.$account['bank'] : null,
            ! empty($account['holder']) ? '- Titular: '.$account['holder'] : null,
            '- '.self::label($number).': '.$number,
        ]));

        if (self::kind($number) === self::CARD) {
            $lines[] = '- Importante: es una tarjeta de débito. En tu app de banco elige transferir a tarjeta, no a CLABE ni a número de cuenta.';
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
