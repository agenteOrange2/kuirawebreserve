<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Migra al panel las reservas que vivían en el sitio anterior (el plugin de
 * hotel de WordPress): las de habitación y las grupales, anteriores y
 * posteriores a hoy, con su huésped, su dinero conocido y su folio.
 *
 * Lee un JSON exportado del respaldo (tablas wp_hotel_room_bookings,
 * wp_hotel_group_orders, wp_hotel_group_order_items y
 * wp_hotel_group_order_payments) para no depender de un dump de 100 MB ni
 * de una conexión al WordPress viejo.
 *
 * Entra por debajo del motor de reservas a propósito: NO pasa por
 * CreateReservation porque estas reservas ya existen — recalcular precios,
 * exigir ventana de anticipación o cobrar disponibilidad rompería historia
 * que ya ocurrió. El precio, las fechas y el estado son los del sitio
 * anterior tal cual.
 *
 * Idempotente: cada reserva y cada grupo quedan marcados con
 * `[web-anterior #id]` en sus notas; una segunda corrida los salta.
 */
class ImportLegacyWebReservations extends Command
{
    protected $signature = 'reservas:importar-web-anterior
        {archivo : JSON exportado del respaldo del sitio anterior}
        {--tenant= : Id del tenant destino}
        {--dry-run : Simula todo y deshace al final}';

    protected $description = 'Migra las reservas (y las grupales) del sitio anterior al panel del hotel';

    /** Marca de origen: hace la importación idempotente y rastreable. */
    protected const MARCA = '[web-anterior #%d]';

    protected const MARCA_GRUPO = '[web-anterior GRP #%d]';

    /** @var array<string, int> */
    protected array $conteo = [];

    /** @var array<int, string> */
    protected array $avisos = [];

    /** Solicitudes sin pago cuya cabaña terminó vendida a otra reserva. */
    /** @var array<int, true> */
    protected array $sinCuarto = [];

    public function handle(): int
    {
        $ruta = $this->argument('archivo');

        if (! is_file($ruta)) {
            $this->error("No encontré el archivo {$ruta}.");

            return self::FAILURE;
        }

        $datos = json_decode((string) file_get_contents($ruta), true);

        if (! is_array($datos) || ! isset($datos['reservas'])) {
            $this->error('El archivo no trae la estructura esperada (reservas, grupos, grupo_lineas, grupo_pagos).');

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');

        if (! $tenantId) {
            $this->error('Falta --tenant: hay que decir a qué hotel se migra.');

            return self::FAILURE;
        }

        $tenant = Tenant::find($tenantId);

        if (! $tenant) {
            $this->error("No existe el hotel '{$tenantId}'.");

            return self::FAILURE;
        }

        return $tenant->run(fn () => $this->importar($datos));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    protected function importar(array $datos): int
    {
        $seco = (bool) $this->option('dry-run');

        $catalogo = $this->resolverCatalogo($datos['habitaciones'] ?? []);

        if ($catalogo === null) {
            return self::FAILURE;
        }

        $reservasWp = collect($datos['reservas'] ?? [])->keyBy('id');
        $gruposWp = collect($datos['grupos'] ?? [])->keyBy('id');
        $lineasWp = collect($datos['grupo_lineas'] ?? []);
        $pagosGrupoWp = collect($datos['grupo_pagos'] ?? [])->groupBy('order_id');

        $grupoPorReserva = $this->mapearGrupos($reservasWp, $gruposWp, $lineasWp);
        $this->sinCuarto = $this->pendientesSinCuarto($reservasWp);

        DB::beginTransaction();

        try {
            // Sin eventos de modelo (withoutEvents cambia el dispatcher
            // para TODOS): 500 reservas de golpe llenarían la bitácora de
            // "creada" fechadas hoy y taparían la actividad real del hotel.
            // La corrida deja UNA entrada al final.
            $resultado = Model::withoutEvents(
                fn () => $this->correr($catalogo, $reservasWp, $gruposWp, $lineasWp, $pagosGrupoWp, $grupoPorReserva)
            );

            if ($seco) {
                DB::rollBack();
                $this->warn('SIMULACIÓN: no se guardó nada.');
            } else {
                DB::commit();
                activity('reservation')
                    ->withProperties($this->conteo)
                    ->log('Migración de reservas del sitio anterior');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Se deshizo todo por un error: '.$e->getMessage());
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }

        $this->reportar();

        return $resultado;
    }

    /**
     * Tipo de habitación, cuarto físico y tarifa por noche de cada cabaña
     * del sitio anterior, casados por nombre exacto.
     *
     * @param  array<string, string>  $habitaciones
     * @return array<int, array{room_type: RoomType, room: Room, rate_plan: RatePlan}>|null
     */
    protected function resolverCatalogo(array $habitaciones): ?array
    {
        $catalogo = [];
        $faltantes = [];

        foreach ($habitaciones as $wpId => $nombre) {
            $tipo = RoomType::query()->where('name', $nombre)->first();
            $cuarto = $tipo?->rooms()->orderBy('number')->first();
            $tarifa = $tipo?->ratePlans()->where('active', true)->where('type', 'night')->orderBy('price')->first();

            if (! $tipo || ! $cuarto || ! $tarifa) {
                $faltantes[] = $nombre;

                continue;
            }

            $catalogo[(int) $wpId] = ['room_type' => $tipo, 'room' => $cuarto, 'rate_plan' => $tarifa];
        }

        if ($faltantes !== []) {
            $this->error('En el catálogo del hotel faltan (con cuarto y tarifa por noche): '.implode(', ', $faltantes));

            return null;
        }

        return $catalogo;
    }

    /**
     * Qué reserva pertenece a qué grupo. El plugin dejaba el vínculo en el
     * meta de la línea (`linked_booking_id`), pero varias solicitudes
     * grupales se capturaron a mano cuarto por cuarto y nunca quedaron
     * ligadas: esas se rescatan por coincidencia exacta de cabaña, fecha de
     * entrada y nombre del responsable — sin el nombre no se une, porque
     * misma cabaña y misma fecha también las comparte quien no venía en el
     * grupo.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $reservasWp
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $gruposWp
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $lineasWp
     * @return array<int, int>
     */
    protected function mapearGrupos($reservasWp, $gruposWp, $lineasWp): array
    {
        $mapa = [];

        foreach ($lineasWp as $linea) {
            if (($linea['item_type'] ?? null) !== 'room') {
                continue;
            }

            $ligada = $linea['linked_booking_id'] ?? null;

            if ($ligada && $reservasWp->has((int) $ligada)) {
                $mapa[(int) $ligada] = (int) $linea['order_id'];
            }
        }

        foreach ($lineasWp as $linea) {
            if (($linea['item_type'] ?? null) !== 'room' || ($linea['linked_booking_id'] ?? null)) {
                continue;
            }

            $grupo = $gruposWp->get((int) $linea['order_id']);

            if (! $grupo) {
                continue;
            }

            $candidata = $reservasWp->first(fn (array $r) => ! isset($mapa[(int) $r['id']])
                && (int) $r['room_id'] === (int) $linea['item_id']
                && $r['check_in'] === $grupo['check_in']
                && ! in_array($r['status'], ['cancelled', 'rejected'], true)
                && $this->mismoNombre($r['guest_name'] ?? '', $grupo['guest_name'] ?? ''));

            if ($candidata) {
                $mapa[(int) $candidata['id']] = (int) $grupo['id'];
                $this->conteo['reservas_unidas_por_nombre'] = ($this->conteo['reservas_unidas_por_nombre'] ?? 0) + 1;
            }
        }

        return $mapa;
    }

    /**
     * Solicitudes sin pago que ya no tienen cabaña: en el sitio anterior no
     * bloqueaban nada, así que la fecha se le vendió a quien sí pagó y
     * quedaron dos "reservas" sobre la misma noche. Entrarían como
     * pendientes vivas y el rack pintaría la cabaña ocupada dos veces, así
     * que se migran canceladas — que es lo que de verdad pasó.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $reservasWp
     * @return array<int, true>
     */
    protected function pendientesSinCuarto($reservasWp): array
    {
        $pisa = fn (array $a, array $b) => (int) $a['room_id'] === (int) $b['room_id']
            && $a['check_in'] < $b['check_out']
            && $b['check_in'] < $a['check_out'];

        $vivas = $reservasWp->filter(fn (array $r) => $r['status'] === 'confirmed')->values();
        $pendientes = $reservasWp
            ->filter(fn (array $r) => in_array($r['status'], ['pending', 'pending_payment'], true))
            ->sortBy('id')
            ->values();

        $sinCuarto = [];

        foreach ($pendientes as $p) {
            $chocaConVendida = $vivas->first(fn (array $r) => $pisa($p, $r)) !== null;

            // Dos solicitudes sin pago sobre la misma noche: se queda la que
            // llegó primero (que es como se apartaba en el sitio anterior).
            $chocaConOtraViva = $pendientes->first(fn (array $r) => (int) $r['id'] < (int) $p['id']
                && ! isset($sinCuarto[(int) $r['id']])
                && $pisa($p, $r)) !== null;

            if ($chocaConVendida || $chocaConOtraViva) {
                $sinCuarto[(int) $p['id']] = true;
            }
        }

        return $sinCuarto;
    }

    protected function mismoNombre(string $a, string $b): bool
    {
        $normalizar = fn (string $v) => preg_replace('/\s+/', ' ', trim(Str::upper(Str::ascii($v))));

        return $normalizar($a) !== '' && $normalizar($a) === $normalizar($b);
    }

    /**
     * @param  array<int, array{room_type: RoomType, room: Room, rate_plan: RatePlan}>  $catalogo
     * @param  array<int, int>  $grupoPorReserva
     */
    protected function correr(array $catalogo, $reservasWp, $gruposWp, $lineasWp, $pagosGrupoWp, array $grupoPorReserva): int
    {
        $propiedad = Property::query()->firstOrFail();

        $yaImportadas = $this->marcasExistentes(Reservation::query()->pluck('notes', 'id'), self::MARCA);
        $yaGrupos = $this->marcasExistentes(ReservationGroup::query()->pluck('notes', 'id'), self::MARCA_GRUPO);

        // Grupos primero: la reserva nace ya colgada de su folio GRP-.
        $gruposCreados = [];

        foreach ($gruposWp as $wpId => $grupo) {
            $reservasDelGrupo = array_keys($grupoPorReserva, (int) $wpId, true);

            if ($reservasDelGrupo === []) {
                $this->avisos[] = sprintf(
                    'Grupo %s (%s, %s) sin reservas que migrar: quedó en cotización en el sitio anterior.',
                    $grupo['reference_code'],
                    $grupo['status'],
                    $grupo['check_in'],
                );
                $this->conteo['grupos_omitidos'] = ($this->conteo['grupos_omitidos'] ?? 0) + 1;

                continue;
            }

            if (isset($yaGrupos[(int) $wpId])) {
                $gruposCreados[(int) $wpId] = $yaGrupos[(int) $wpId];
                $this->conteo['grupos_ya_estaban'] = ($this->conteo['grupos_ya_estaban'] ?? 0) + 1;

                continue;
            }

            $huesped = $this->resolverHuesped($grupo['guest_name'] ?? null, $grupo['guest_phone'] ?? null, $grupo['guest_email'] ?? null);
            $creado = Carbon::parse($grupo['created_at']);

            $nuevo = ReservationGroup::create([
                'property_id' => $propiedad->id,
                'guest_id' => $huesped?->id,
                'guest_name' => $grupo['guest_name'] ?: null,
                'notes' => $this->notaGrupo($grupo, $lineasWp, $pagosGrupoWp->get((int) $wpId, collect())),
            ]);

            $nuevo->forceFill([
                'code' => ReservationGroup::formatCode($nuevo->id, $creado),
                'created_at' => $creado,
                'updated_at' => $creado,
            ])->saveQuietly();

            $gruposCreados[(int) $wpId] = $nuevo->id;
            $this->conteo['grupos'] = ($this->conteo['grupos'] ?? 0) + 1;
        }

        // Reservas de habitación, en el orden en que se hicieron.
        foreach ($reservasWp->sortBy('id') as $r) {
            $wpId = (int) $r['id'];

            if (isset($yaImportadas[$wpId])) {
                $this->conteo['reservas_ya_estaban'] = ($this->conteo['reservas_ya_estaban'] ?? 0) + 1;

                continue;
            }

            $mapa = $catalogo[(int) $r['room_id']] ?? null;

            if ($mapa === null) {
                $this->avisos[] = "Reserva #{$wpId}: la cabaña {$r['room_id']} no existe en el catálogo; no se migró.";
                $this->conteo['reservas_sin_cabana'] = ($this->conteo['reservas_sin_cabana'] ?? 0) + 1;

                continue;
            }

            $this->crearReserva($r, $mapa, $propiedad, $gruposCreados[$grupoPorReserva[$wpId] ?? -1] ?? null);
        }

        // Dinero que solo quedó registrado a nivel del grupo (transferencias
        // capturadas en la solicitud grupal, no en cada cabaña).
        foreach ($gruposCreados as $wpId => $grupoId) {
            $this->repartirPagoGrupal($grupoId, $pagosGrupoWp->get((int) $wpId, collect()));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array{room_type: RoomType, room: Room, rate_plan: RatePlan}  $mapa
     */
    protected function crearReserva(array $r, array $mapa, Property $propiedad, ?int $grupoId): void
    {
        [[$horaEntrada, $minEntrada], [$horaSalida, $minSalida]] = $mapa['room_type']->effectiveScheduleTimes();

        $inicio = Carbon::parse($r['check_in'])->setTime($horaEntrada, $minEntrada);
        $fin = Carbon::parse($r['check_out'])->setTime($horaSalida, $minSalida);

        $total = round((float) $r['total_price'], 2);
        $pagado = $this->pagoConocido($r);
        $huesped = $this->resolverHuesped($r['guest_name'] ?? null, $r['guest_phone'] ?? null, $r['guest_email'] ?? null);

        [$estado, $motivo] = $this->estado($r, $fin);

        $personas = max(1, (int) $r['guests']);
        $cupo = $mapa['room']->effectiveMaxOccupancy();

        if ($cupo !== null && $personas > $cupo) {
            $this->avisos[] = sprintf(
                'Reserva #%d (%s, %s): venían %d personas y la cabaña admite %d — se migró tal cual.',
                $r['id'], $mapa['room_type']->name, $r['check_in'], $personas, $cupo,
            );
        }

        $reserva = Reservation::create([
            'property_id' => $propiedad->id,
            'room_type_id' => $mapa['room_type']->id,
            'room_id' => $mapa['room']->id,
            'rate_plan_id' => $mapa['rate_plan']->id,
            'reservation_group_id' => $grupoId,
            'guest_id' => $huesped?->id,
            'guest_name' => $r['guest_name'] ?: null,
            'num_people' => $personas,
            'adults' => $personas,
            'children' => 0,
            'starts_at' => $inicio,
            'ends_at' => $fin,
            'status' => $estado,
            // Sin hold: un apartado de 30 minutos importado nacería vencido
            // y el barrido nocturno lo cancelaría solo.
            'hold_expires_at' => null,
            'source_channel' => match ($r['source']) {
                'web' => 'web',
                default => 'front_desk',
            },
            'total_amount' => $total,
            'deposit_amount' => $this->anticipo($r, $mapa['rate_plan'], $total, $pagado),
            'payment_status' => $this->estadoPago($r, $total, $pagado),
            'notes' => $this->nota($r),
            'cancellation_reason' => $motivo,
        ]);

        $creada = Carbon::parse($r['created_at']);

        $reserva->forceFill([
            'code' => Reservation::formatCode($reserva->id, $creada),
            'created_at' => $creada,
            'updated_at' => Carbon::parse($r['updated_at'] ?: $r['created_at']),
        ])->saveQuietly();

        $this->conteo['reservas'] = ($this->conteo['reservas'] ?? 0) + 1;
        $this->conteo['reservas_'.$estado->value] = ($this->conteo['reservas_'.$estado->value] ?? 0) + 1;

        if ($pagado !== null && $pagado > 0) {
            $this->registrarPago(
                $reserva,
                $pagado,
                $this->metodo($r['payment_method'] ?? ''),
                Carbon::parse($r['updated_at'] ?: $r['created_at']),
                sprintf('Cobro registrado en el sitio anterior (#%d)', $r['id']),
            );
        }
    }

    /**
     * Lo que el hotel de verdad tenía cobrado, y solo cuando el dato es
     * numérico: `paid` es el total y `partial` con saldo es la diferencia.
     * Los "partial" sin saldo capturado NO se adivinan de la nota — ahí el
     * texto suele traer el abono del grupo entero ("dio 8k total 25.500"),
     * y repetirlo cabaña por cabaña inventaría dinero que nadie recibió.
     *
     * @param  array<string, mixed>  $r
     */
    protected function pagoConocido(array $r): ?float
    {
        $total = round((float) $r['total_price'], 2);
        $saldo = round((float) $r['balance_due'], 2);

        return match ($r['payment_status']) {
            'paid' => $total,
            'partial' => $saldo > 0 && $saldo < $total ? round($total - $saldo, 2) : null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $r
     */
    protected function anticipo(array $r, RatePlan $tarifa, float $total, ?float $pagado): float
    {
        $saldo = round((float) $r['balance_due'], 2);

        // Lo que ya abonó (anticipo real) o lo que el sitio anterior le
        // pedía; si no hay ninguno de los dos, la política de la tarifa.
        return match (true) {
            $pagado !== null && $pagado > 0 && $pagado < $total => $pagado,
            (bool) $r['is_deposit'] && $saldo > 0 && $saldo < $total => $saldo,
            default => round((float) ($tarifa->depositAmountFor($total) ?? 0), 2),
        };
    }

    /**
     * @param  array<string, mixed>  $r
     */
    protected function estadoPago(array $r, float $total, ?float $pagado): PaymentStatus
    {
        return match (true) {
            $pagado !== null && $pagado >= $total && $total > 0 => PaymentStatus::Paid,
            $pagado !== null && $pagado > 0 => PaymentStatus::DepositPaid,
            // Anticipo cobrado del que solo quedó el texto de la nota:
            // se marca para que recepción no lo cobre dos veces.
            $r['payment_status'] === 'partial' => PaymentStatus::DepositPaid,
            default => PaymentStatus::Unpaid,
        };
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{0: ReservationStatus, 1: ?string}
     */
    protected function estado(array $r, Carbon $fin): array
    {
        $pasada = $fin->isPast();

        return match ($r['status']) {
            'confirmed' => [$pasada ? ReservationStatus::Completed : ReservationStatus::Confirmed, null],
            'cancelled' => [ReservationStatus::Cancelled, 'Cancelada en el sitio anterior'],
            'rejected' => [ReservationStatus::Cancelled, 'Rechazada en el sitio anterior'],
            default => match (true) {
                $pasada => [ReservationStatus::Cancelled, 'Solicitud sin pago del sitio anterior; la fecha ya pasó'],
                isset($this->sinCuarto[(int) $r['id']]) => [
                    ReservationStatus::Cancelled,
                    'Solicitud sin pago; la cabaña se vendió a otra reserva en el sitio anterior',
                ],
                default => [ReservationStatus::Pending, null],
            },
        };
    }

    protected function metodo(string $metodoWp): string
    {
        return match ($metodoWp) {
            'cash', 'efectivo' => 'cash',
            'tarjeta_presencial', 'card' => 'card',
            'stripe', 'paypal', 'mercadopago' => Payment::METHOD_ONLINE,
            default => 'transfer',
        };
    }

    /**
     * @param  array<string, mixed>  $r
     */
    protected function nota(array $r): string
    {
        $partes = array_filter([
            trim((string) ($r['notes'] ?? '')),
            $r['payment_status'] === 'partial' && $this->pagoConocido($r) === null
                ? 'Anticipo cobrado en el sitio anterior; el monto quedó solo en esta nota.'
                : null,
            sprintf(self::MARCA, $r['id']),
        ]);

        return implode("\n", $partes);
    }

    /**
     * @param  array<string, mixed>  $grupo
     */
    protected function notaGrupo(array $grupo, $lineasWp, $pagos): string
    {
        $tours = $lineasWp->where('order_id', (int) $grupo['id'])->where('item_type', 'tour');

        $partes = array_filter([
            trim((string) ($grupo['notes'] ?? '')),
            'Solicitud grupal '.$grupo['reference_code'].' del sitio anterior ('.$grupo['guests_total'].' personas).',
            $pagos->isNotEmpty()
                ? 'Pagos capturados en la solicitud: '.$pagos->map(
                    fn ($p) => '$'.number_format((float) $p['amount'], 2).' '.$p['method'].' '.Carbon::parse($p['created_at'])->format('d/m/Y')
                )->implode(', ').'.'
                : null,
            $tours->isNotEmpty()
                ? 'Traía '.$tours->sum('quantity').' recorrido(s) CANAM cotizados; los recorridos no se migraron.'
                : null,
            sprintf(self::MARCA_GRUPO, $grupo['id']),
        ]);

        return Str::limit(implode(' ', $partes), 499, '');
    }

    /**
     * Transferencias que el sitio anterior guardó en la solicitud grupal y
     * no en cada cabaña. Se reparte solo lo que NO está ya cobrado en las
     * reservas del grupo (si no, se contaría dos veces), a prorrata del
     * total de cada habitación viva.
     */
    protected function repartirPagoGrupal(int $grupoId, $pagos): void
    {
        if ($pagos->isEmpty()) {
            return;
        }

        $grupo = ReservationGroup::find($grupoId);
        $reservas = $grupo?->reservations()
            ->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow])
            ->orderByDesc('total_amount')
            ->get();

        if ($reservas === null || $reservas->isEmpty()) {
            return;
        }

        $delGrupo = round((float) $pagos->sum(fn ($p) => (float) $p['amount']), 2);
        $yaCobrado = round((float) Payment::query()->whereIn('reservation_id', $reservas->pluck('id'))->sum('amount'), 2);
        $faltante = round($delGrupo - $yaCobrado, 2);

        if ($faltante < 0.5) {
            return;
        }

        $ultimo = $pagos->sortBy('created_at')->last();
        $totalCuartos = max(0.01, (float) $reservas->sum('total_amount'));
        $repartido = 0.0;

        foreach ($reservas as $i => $reserva) {
            $parte = $i === $reservas->count() - 1
                ? round($faltante - $repartido, 2)
                : round($faltante * ((float) $reserva->total_amount / $totalCuartos), 2);

            if ($parte <= 0) {
                continue;
            }

            $repartido = round($repartido + $parte, 2);

            $this->registrarPago(
                $reserva,
                $parte,
                $this->metodo($ultimo['method'] ?? ''),
                Carbon::parse($ultimo['created_at']),
                'Parte del pago de la solicitud grupal en el sitio anterior',
            );
        }

        $this->conteo['grupos_con_pago_repartido'] = ($this->conteo['grupos_con_pago_repartido'] ?? 0) + 1;
    }

    /**
     * Abono migrado: SIEMPRE sin `received_by` ni turno — es dinero que se
     * cobró en el sistema viejo, así que no debe aparecer en el corte de
     * caja de nadie (CashCutService filtra por encargado).
     */
    protected function registrarPago(Reservation $reserva, float $monto, string $metodo, Carbon $fecha, string $nota): void
    {
        Payment::create([
            'reservation_id' => $reserva->id,
            'amount' => $monto,
            'method' => $metodo,
            'notes' => $nota,
            'received_by' => null,
            'shift_id' => null,
            'paid_at' => $fecha,
        ]);

        $pagado = round((float) $reserva->payments()->sum('amount'), 2);

        $reserva->forceFill([
            'payment_status' => match (true) {
                $pagado >= (float) $reserva->total_amount && (float) $reserva->total_amount > 0 => PaymentStatus::Paid,
                $pagado > 0 => PaymentStatus::DepositPaid,
                default => $reserva->payment_status,
            },
        ])->saveQuietly();

        $this->conteo['pagos'] = ($this->conteo['pagos'] ?? 0) + 1;
        $this->conteo['pagos_monto'] = round(($this->conteo['pagos_monto'] ?? 0) + $monto, 2);
    }

    /**
     * Huésped del CRM: se busca por teléfono (lo único confiable — muchos
     * correos son el mismo `sincorreo@gmail.com` de mostrador) y si no hay
     * teléfono, por correo.
     */
    protected function resolverHuesped(?string $nombre, ?string $telefono, ?string $correo): ?Guest
    {
        $telefono = $this->telefono($telefono);
        $correo = filter_var(trim((string) $correo), FILTER_VALIDATE_EMAIL) ?: null;
        $nombre = trim((string) $nombre) ?: null;

        if ($telefono === null && $correo === null) {
            return null;
        }

        $huesped = Guest::query()
            ->when($telefono, fn ($q) => $q->where('phone', $telefono))
            ->when(! $telefono, fn ($q) => $q->where('email', $correo))
            ->first();

        if ($huesped === null) {
            $huesped = Guest::create([
                'first_name' => $nombre,
                'phone' => $telefono,
                'email' => $correo,
            ]);

            $this->conteo['huespedes'] = ($this->conteo['huespedes'] ?? 0) + 1;

            return $huesped;
        }

        // Ficha ya conocida a la que este registro le agrega un dato que le
        // faltaba; nunca pisa lo que ya tenía capturado el hotel.
        $huesped->fill(array_filter([
            'first_name' => $huesped->first_name ? null : $nombre,
            'email' => $huesped->email ? null : $correo,
        ]))->save();

        return $huesped;
    }

    protected function telefono(?string $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor);

        if (strlen((string) $digitos) < 10) {
            return null;
        }

        // Mismo número escrito con y sin lada de país es el mismo huésped.
        $digitos = preg_replace('/^52(1)?(?=\d{10}$)/', '', $digitos);

        return $digitos;
    }

    /**
     * Lo que ya trajo una corrida anterior: id del sitio anterior => id en
     * el panel, leído de la marca que quedó en las notas.
     *
     * @param  \Illuminate\Support\Collection<int, ?string>  $notas  notas por id del panel
     * @return array<int, int>
     */
    protected function marcasExistentes($notas, string $formato): array
    {
        $patron = '/'.str_replace('%d', '(\d+)', preg_quote($formato, '/')).'/';
        $marcas = [];

        foreach ($notas as $id => $nota) {
            if ($nota && preg_match($patron, (string) $nota, $m)) {
                $marcas[(int) $m[1]] = (int) $id;
            }
        }

        return $marcas;
    }

    protected function reportar(): void
    {
        $this->newLine();
        $this->info('Resumen de la migración');

        foreach ($this->conteo as $clave => $valor) {
            $this->line(sprintf('  %-32s %s', str_replace('_', ' ', $clave), is_float($valor) ? '$'.number_format($valor, 2) : $valor));
        }

        if ($this->avisos !== []) {
            $this->newLine();
            $this->warn('Para revisar ('.count($this->avisos).'):');

            foreach ($this->avisos as $aviso) {
                $this->line('  · '.$aviso);
            }
        }
    }
}
