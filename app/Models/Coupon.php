<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cupón de descuento (módulo cupones): código que el huésped aplica en el
 * wizard público. percent = % sobre el subtotal; amount = monto fijo. El
 * descuento aplicado se congela en la reserva (coupon_code +
 * discount_amount); used_count se incrementa al CONFIRMARSE la reserva
 * (TransitionReservation), nunca en el hold.
 */
class Coupon extends Model
{
    use LogsActivity;

    public const KIND_PERCENT = 'percent';

    public const KIND_AMOUNT = 'amount';

    /** Ventana del cupón de cumpleaños: ± días alrededor de la fecha. */
    public const BIRTHDAY_WINDOW_DAYS = 7;

    /** Nombres por dayOfWeek de Carbon (0=domingo..6=sábado). */
    public const WEEKDAY_NAMES = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    protected $fillable = [
        'code',
        'kind',
        'value',
        'min_nights',
        'min_visits',
        'room_type_id',
        'birthday',
        'weekdays',
        'starts_at',
        'ends_at',
        'max_uses',
        'used_count',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_nights' => 'integer',
            'min_visits' => 'integer',
            'birthday' => 'boolean',
            'weekdays' => 'array',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'active' => 'boolean',
        ];
    }

    public function roomType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * Bitácora: quién creó, editó o apagó cada cupón. El canje NO pasa por
     * aquí (used_count se incrementa con query builder, sin eventos): lo
     * registra TransitionReservation sobre la reserva que lo usó.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('coupon')
            ->logOnly(['code', 'kind', 'value', 'active', 'starts_at', 'ends_at', 'max_uses', 'weekdays'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Llave de comparación de un código: solo letras y números, en
     * mayúsculas. Así "pache pache", "Pache-Pache" y "PACHEPACHE" son el
     * mismo cupón, lo escriba como lo escriba el huésped.
     */
    public static function keyOf(?string $code): string
    {
        return mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $code));
    }

    /**
     * El cupón vivo que aparece mencionado en un texto del huésped, aunque
     * no diga "cupón" ni "código" ("vengo del video del pache pache").
     *
     * Los códigos de menos de 5 caracteres no se buscan: una palabra común
     * dentro de una frase dispararía un descuento que nadie ofreció.
     */
    public static function mentionedIn(?string $text): ?self
    {
        $said = self::keyOf($text);

        if ($said === '') {
            return null;
        }

        return self::query()
            ->where('active', true)
            ->get()
            ->first(function (self $coupon) use ($said) {
                $key = self::keyOf($coupon->code);

                return mb_strlen($key) >= 5 && $coupon->isRedeemable() && str_contains($said, $key);
            });
    }

    /** ¿Se puede aplicar hoy? Activo, dentro de vigencia y con usos libres. */
    public function isRedeemable(): bool
    {
        if (! $this->active) {
            return false;
        }

        $today = now()->toDateString();

        if ($this->starts_at !== null && $today < $this->starts_at->toDateString()) {
            return false;
        }

        if ($this->ends_at !== null && $today > $this->ends_at->toDateString()) {
            return false;
        }

        return $this->max_uses === null || $this->used_count < $this->max_uses;
    }

    /**
     * Condiciones del cupón contra la reserva concreta (documento base:
     * estancia larga, tipo de habitación, cliente frecuente, cumpleaños,
     * días de la semana). Devuelve NULL si todo cumple, o el motivo en
     * texto para el huésped. La vigencia/usos base se valida aparte con
     * isRedeemable().
     */
    public function rejectionReason(
        ?Guest $guest,
        ?CarbonInterface $start,
        ?int $nights,
        ?int $roomTypeId,
        ?CarbonInterface $end = null,
    ): ?string {
        return $this->stayRejectionReason($start, $nights, $roomTypeId, $end)
            ?? $this->guestRejectionReason($guest, $start);
    }

    /**
     * Lo que depende de la ESTANCIA: noches mínimas, tipo de habitación,
     * días de la semana y para cuándo vale la vigencia.
     *
     * Se juzga aparte porque es lo único que una edición puede mover: al
     * reagendar una reserva que ya traía cupón hay que volver a pasar por
     * aquí (las fechas nuevas pueden caer fuera), pero NO por las
     * condiciones del huésped, que no cambiaron por editar.
     */
    public function stayRejectionReason(
        ?CarbonInterface $start,
        ?int $nights,
        ?int $roomTypeId,
        ?CarbonInterface $end = null,
        // La búsqueda de la fecha alternativa llama aquí en bucle: ahí NO se
        // vuelve a sugerir (si no, cada candidata buscaría otra candidata y
        // el proceso se queda sin memoria — pasó en la primera corrida).
        bool $withHint = true,
    ): ?string {
        if ($this->min_nights !== null && ($nights === null || $nights < $this->min_nights)) {
            return "Este cupón aplica en estancias de al menos {$this->min_nights} noches.";
        }

        if ($this->room_type_id !== null && $roomTypeId !== $this->room_type_id) {
            $name = $this->roomType?->name;

            return $name !== null
                ? "Este cupón aplica solo para habitaciones {$name}."
                : 'Este cupón aplica solo para otro tipo de habitación.';
        }

        // Días de la semana: TODAS las noches de la estancia deben caer en
        // los días marcados (con viernes-sábado-domingo, vie→lun aplica y
        // jue→sáb no). Sin fechas (consulta previa del wizard) no se juzga:
        // el hold revalida con las fechas reales.
        if (! empty($this->weekdays) && $start !== null) {
            $allowed = array_map('intval', $this->weekdays);

            if (array_diff(self::stayWeekdays($start, $end), $allowed) !== []) {
                // Decir solo "no aplica" es perder al huésped: 40 de los 48
                // rechazos de PACHEPACHE (cabañas, 30 días) fueron por el día
                // de la semana — el video de la promoción trae gente de fin
                // de semana. Con la fecha cercana que SÍ aplica, el bot vende
                // en vez de negar.
                return 'Este cupón aplica solo para estancias en '.$this->weekdaysLabel().'.'
                    .($withHint ? $this->nextQualifyingHint($start, $end) : '');
            }
        }

        // La vigencia acota TAMBIÉN las noches de la estancia: un cupón que
        // vence el 15/10 no paga una estancia del 20/10 aunque se aparte
        // hoy (caso real cabañas 2026-09-11). isRedeemable() solo mira si
        // HOY se puede canjear; esto mira PARA CUÁNDO.
        if ($start !== null) {
            $firstNight = CarbonImmutable::instance($start)->startOfDay();
            $lastNight = $end !== null
                ? CarbonImmutable::instance($end)->startOfDay()->subDay()
                : $firstNight;

            if ($lastNight->lt($firstNight)) {
                $lastNight = $firstNight;
            }

            if ($this->starts_at !== null && $firstNight->lt($this->starts_at->startOfDay())) {
                return 'Este cupón aplica para estancias a partir del '.$this->starts_at->format('d/m/Y').'.';
            }

            if ($this->ends_at !== null && $lastNight->gt($this->ends_at->startOfDay())) {
                return 'Este cupón aplica solo para estancias hasta el '.$this->ends_at->format('d/m/Y').'.'
                    .($withHint ? $this->nextQualifyingHint($start, $end) : '');
            }
        }

        return null;
    }

    /**
     * Lo que depende del HUÉSPED: cliente frecuente y cumpleaños. Solo se
     * exige al aplicar el cupón, nunca al recalcular uno ya prometido.
     */
    public function guestRejectionReason(?Guest $guest, ?CarbonInterface $start): ?string
    {
        if ($this->min_visits !== null) {
            if ($guest === null || ($guest->metrics()['visits'] ?? 0) < $this->min_visits) {
                return 'Este cupón es para clientes frecuentes; aún no alcanzas las visitas necesarias.';
            }
        }

        if ($this->birthday) {
            if ($guest?->birth_date === null || $start === null) {
                return 'Este cupón de cumpleaños requiere tu fecha de nacimiento registrada.';
            }

            // Cumpleaños más cercano al check-in (maneja el cruce de año).
            $birthday = $guest->birth_date->copy()->year($start->year);
            $distance = min(
                abs($start->copy()->startOfDay()->diffInDays($birthday->startOfDay(), false)),
                abs($start->copy()->startOfDay()->diffInDays($birthday->copy()->addYear()->startOfDay(), false)),
                abs($start->copy()->startOfDay()->diffInDays($birthday->copy()->subYear()->startOfDay(), false)),
            );

            if ($distance > self::BIRTHDAY_WINDOW_DAYS) {
                return 'Este cupón aplica solo en fechas cercanas a tu cumpleaños.';
            }
        }

        return null;
    }

    /**
     * La estancia más cercana que SÍ cumple las condiciones del cupón, dicha
     * en una frase lista para mandar. Vacío si no hay ninguna (p. ej. el
     * cupón ya venció): ahí no se le da esperanza a nadie.
     */
    public function nextQualifyingHint(?CarbonInterface $start, ?CarbonInterface $end = null): string
    {
        $noches = 1;

        if ($start !== null && $end !== null) {
            $noches = max(1, (int) CarbonImmutable::instance($start)->startOfDay()
                ->diffInDays(CarbonImmutable::instance($end)->startOfDay()));
        }

        // Se busca a partir de la fecha que el huésped pidió, no de hoy:
        // a quien quiere el sábado 26 se le ofrece el lunes 28, no "hoy
        // jueves", que no es lo que anda buscando.
        $desde = CarbonImmutable::today();

        if ($start !== null && CarbonImmutable::instance($start)->startOfDay()->gt($desde)) {
            $desde = CarbonImmutable::instance($start)->startOfDay();
        }

        if ($this->starts_at !== null && $desde->lt($this->starts_at->startOfDay())) {
            $desde = CarbonImmutable::instance($this->starts_at)->startOfDay();
        }

        $tope = $this->ends_at !== null
            ? CarbonImmutable::instance($this->ends_at)->startOfDay()
            : $desde->addDays(120);

        for ($dia = $desde; $dia->lte($tope); $dia = $dia->addDay()) {
            $salida = $dia->addDays($noches);

            if ($this->stayRejectionReason($dia, $noches, $this->room_type_id, $salida, withHint: false) === null) {
                $llegada = $dia->locale('es')->isoFormat('dddd D [de] MMMM');

                return $noches === 1
                    ? " La fecha más cercana en la que sí aplica es llegando el {$llegada}."
                    : " La fecha más cercana en la que sí aplica es llegando el {$llegada} ({$noches} noches).";
            }
        }

        return '';
    }

    /**
     * Días de la semana que ocupa la estancia: cada noche desde la llegada;
     * el día de salida no cuenta. Sin salida, o saliendo el mismo día
     * (bloque de horas), solo cuenta el día de llegada.
     *
     * @return list<int>
     */
    protected static function stayWeekdays(CarbonInterface $start, ?CarbonInterface $end): array
    {
        // Inmutable a propósito: el caller puede mandar Carbon mutable.
        $day = CarbonImmutable::instance($start)->startOfDay();
        $checkout = $end !== null ? CarbonImmutable::instance($end)->startOfDay() : $day->addDay();
        $weekdays = [];

        // Con siete noches ya pasó por todos los días: no hace falta seguir.
        do {
            $weekdays[$day->dayOfWeek] = true;
            $day = $day->addDay();
        } while ($day < $checkout && count($weekdays) < 7);

        return array_keys($weekdays);
    }

    /** "viernes, sábado y domingo" (empezando en lunes), o null si vale toda la semana. */
    public function weekdaysLabel(): ?string
    {
        if (empty($this->weekdays)) {
            return null;
        }

        return collect($this->weekdays)
            ->map(fn ($day) => (int) $day)
            ->sortBy(fn (int $day) => ($day + 6) % 7)
            ->map(fn (int $day) => self::WEEKDAY_NAMES[$day] ?? (string) $day)
            ->join(', ', ' y ');
    }

    /**
     * Descuento en pesos para un subtotal dado: % del subtotal o el monto
     * fijo, nunca más que el propio subtotal (el total jamás baja de 0).
     */
    public function discountFor(float $subtotal): float
    {
        $subtotal = max(0, $subtotal);

        $discount = $this->kind === self::KIND_PERCENT
            ? $subtotal * ((float) $this->value / 100)
            : (float) $this->value;

        return round(min($discount, $subtotal), 2);
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_PERCENT
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'%'
            : '$'.number_format((float) $this->value, 2);
    }
}
