<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Horario de atención del hotel: a qué horas hay gente para contestar.
 *
 * No tiene nada que ver con el check-in/check-out (eso es la casa); esto es
 * el turno de quien atiende el chat. Sirve para dos cosas que el huésped
 * agradece: que el asistente no prometa que "en un momento te atienden" a
 * las 11 de la noche, y que el hotel se entere por WhatsApp de la cotización
 * que entró de madrugada en vez de descubrirla al otro día.
 *
 * Opt-in: sin `support_hours_enabled` el comportamiento es el de siempre
 * (atención 24/7, sin avisos).
 */
class SupportHours
{
    public const DEFAULT_OPEN = '09:00';

    public const DEFAULT_CLOSE = '17:00';

    /** Días ISO (1 lunes ... 7 domingo). */
    public const DEFAULT_DAYS = [1, 2, 3, 4, 5, 6, 7];

    protected ?Property $property = null;

    /** @var array<string, mixed> */
    protected array $settings = [];

    public function __construct()
    {
        $this->property = Property::query()->first();
        $this->settings = $this->property?->settings ?? [];
    }

    public function enabled(): bool
    {
        return (bool) ($this->settings['support_hours_enabled'] ?? false);
    }

    /** ¿Hay alguien de guardia ahora mismo? Sin horario configurado, siempre. */
    public function isOpen(?CarbonInterface $at = null): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $now = $this->localize($at);

        if (! in_array($now->dayOfWeekIso, $this->days(), true)) {
            return false;
        }

        $open = $this->timeOn($now, $this->open());
        $close = $this->timeOn($now, $this->close());

        // Turno que cruza la medianoche (p. ej. 20:00 a 02:00): dentro es
        // "después de abrir O antes de cerrar", no la resta de siempre.
        return $close->lessThanOrEqualTo($open)
            ? $now->greaterThanOrEqualTo($open) || $now->lessThan($close)
            : $now->greaterThanOrEqualTo($open) && $now->lessThan($close);
    }

    public function isClosed(?CarbonInterface $at = null): bool
    {
        return ! $this->isOpen($at);
    }

    /** "de 9:00 a 17:00, todos los días" — para el prompt y la pantalla. */
    public function label(): string
    {
        return 'de '.$this->pretty($this->open()).' a '.$this->pretty($this->close()).', '.$this->daysLabel();
    }

    /**
     * Cuándo vuelve a haber gente, en palabras que el huésped entiende:
     * "hoy a partir de las 9:00", "mañana a partir de las 9:00",
     * "el lunes a partir de las 9:00".
     */
    public function nextOpeningLabel(?CarbonInterface $at = null): string
    {
        $now = $this->localize($at);
        $next = $this->nextOpening($now);

        if ($next === null) {
            return 'en cuanto se reanude la atención';
        }

        $when = match (true) {
            $next->isSameDay($now) => 'hoy',
            $next->isSameDay($now->addDay()) => 'mañana',
            default => 'el '.$next->locale('es')->isoFormat('dddd'),
        };

        return $when.' a partir de las '.$this->pretty($next->format('H:i'));
    }

    /** Próxima apertura real (hasta 8 días adelante), o null si no hay días. */
    public function nextOpening(?CarbonInterface $at = null): ?CarbonImmutable
    {
        $now = $this->localize($at);
        $days = $this->days();

        if ($days === []) {
            return null;
        }

        for ($i = 0; $i <= 7; $i++) {
            $day = $now->addDays($i);

            if (! in_array($day->dayOfWeekIso, $days, true)) {
                continue;
            }

            $opening = $this->timeOn($day, $this->open());

            if ($opening->greaterThan($now)) {
                return $opening;
            }
        }

        return null;
    }

    /**
     * Aviso para el huésped cuando escribe fuera de horario. Se dice UNA vez
     * al día por conversación: repetirlo en cada mensaje es peor que no
     * decirlo.
     */
    public function afterHoursNotice(?CarbonInterface $at = null): string
    {
        // De usted (opt-in del hotel) y sin el "alguien te contacta": el
        // asistente SÍ cotiza y aparta fuera de horario, y decir que otro lo
        // retoma mañana hizo que el huésped dejara de pedir (Hotel México
        // 2026-10-01, 18:04).
        if ((bool) ($this->settings['formal_address'] ?? false)) {
            return 'Nuestro horario de atención con personal es '.$this->label()
                .'. Mientras tanto yo le atiendo: puedo cotizarle y apartarle su habitación ahora mismo. '
                .'Si necesita hablar con una persona, le responden '.$this->nextOpeningLabel($at).'.';
        }

        return 'Nuestro horario de atención es '.$this->label()
            .', así que en este momento el equipo ya no está en línea. '
            .'Tomo tu solicitud y alguien del hotel te contacta '.$this->nextOpeningLabel($at).'.';
    }

    /**
     * WhatsApp del hotel para los avisos internos (cotización fuera de
     * horario, conversación transferida). Si no se configuró aparte, se usa
     * el teléfono principal del hotel.
     */
    public function alertPhone(): ?string
    {
        $configured = $this->settings['support_alert_phone'] ?? null;

        $digits = is_array($configured)
            ? preg_replace('/\D+/', '', ($configured['code'] ?? '').($configured['number'] ?? ''))
            : preg_replace('/\D+/', '', (string) ($configured ?? ''));

        if ($digits === null || $digits === '') {
            $phone = ($this->settings['phones'][0] ?? null);
            $digits = is_array($phone)
                ? preg_replace('/\D+/', '', ($phone['code'] ?? '').($phone['number'] ?? ''))
                : preg_replace('/\D+/', '', (string) ($this->settings['phone'] ?? ''));
        }

        return $digits !== null && strlen($digits) >= 10 ? $digits : null;
    }

    /** Correo del hotel para el mismo aviso (respaldo del WhatsApp). */
    public function alertEmail(): ?string
    {
        $email = $this->settings['emails'][0] ?? $this->settings['email'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }

    public function open(): string
    {
        return $this->time($this->settings['support_hours_open'] ?? null, self::DEFAULT_OPEN);
    }

    public function close(): string
    {
        return $this->time($this->settings['support_hours_close'] ?? null, self::DEFAULT_CLOSE);
    }

    /** @return array<int, int> */
    public function days(): array
    {
        $days = $this->settings['support_hours_days'] ?? self::DEFAULT_DAYS;
        $days = array_values(array_unique(array_filter(
            array_map('intval', is_array($days) ? $days : []),
            fn (int $d) => $d >= 1 && $d <= 7,
        )));
        sort($days);

        return $days ?: self::DEFAULT_DAYS;
    }

    public function timezone(): string
    {
        return $this->property?->timezone ?: config('app.timezone');
    }

    protected function daysLabel(): string
    {
        $days = $this->days();

        if ($days === self::DEFAULT_DAYS) {
            return 'todos los días';
        }

        $names = ['1' => 'lunes', '2' => 'martes', '3' => 'miércoles', '4' => 'jueves', '5' => 'viernes', '6' => 'sábado', '7' => 'domingo'];

        // Rango corrido (lunes a viernes) en vez de enlistar cinco días.
        $corrido = count($days) > 2 && $days === range($days[0], $days[count($days) - 1]);

        if ($corrido) {
            return $names[(string) $days[0]].' a '.$names[(string) $days[count($days) - 1]];
        }

        $labels = array_map(fn (int $d) => $names[(string) $d], $days);
        $last = array_pop($labels);

        return $labels === [] ? $last : implode(', ', $labels).' y '.$last;
    }

    protected function localize(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($this->timezone());
    }

    protected function timeOn(CarbonInterface $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return CarbonImmutable::instance($day)->setTime((int) $hour, (int) $minute);
    }

    protected function time(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^\d{1,2}:\d{2}$/', $value)
            ? sprintf('%02d:%s', ...array_map('trim', explode(':', $value)))
            : $fallback;
    }

    /** 09:00 se lee mejor como "9:00". */
    protected function pretty(string $time): string
    {
        return ltrim($time, '0') ?: $time;
    }
}
