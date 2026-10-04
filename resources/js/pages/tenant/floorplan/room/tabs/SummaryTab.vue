<script setup lang="ts">
import { computed, inject } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import {
    countdownLabel,
    formatChannel,
    formatMoney,
    guestCountLabel,
    priceModifierLabel,
    stayTone,
} from '../../format';

/**
 * Resumen: lo que se hace con este cuarto AHORA. Vender si está libre, quién
 * está adentro con su cuenta y la próxima reserva.
 *
 * Viene de la ficha vieja, que traía esto y todo lo demás en un solo scroll.
 * La limpieza y el mantenimiento se fueron a su propio tab: son otro oficio y
 * los hacen otras personas, y aquí quedaban debajo del huésped.
 *
 * Aquí no se decide nada: cada botón llama al handler del plano y hereda su
 * toast, su candado de "acción en curso" y su refresco.
 */
// Etiqueta de la identificación del huésped a pie (registro exprés motel).
const documentTypeLabels: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Documento',
};

const ctx = inject(FloorPlanKey)!;

const room = computed(() => ctx.room.value!);

const {
    canManageReservations,
    canChargeConsumption,
    canViewDocuments,
    manualCheckinAllowed,
    roomSale,
    busyAction,
    dispatchArrival,
    checkInReservation,
    requestCheckout,
    openExtend,
    openMove,
    openPos,
    openReservationDetail,
    completeArrival,
} = ctx;

// El reloj es uno solo para toda la página: dos tics distintos harían que la
// tarjeta del plano y esta cuenta regresiva discrepen por un minuto.
/** Lo que el huésped ya pagó (anticipos, abonos, consumos y fianza). */
const payments = computed(() => ctx.folio.value?.payments ?? []);

/** Pagos a la vista; la cuenta completa vive en Consumos y en el PDF. */
const PAYMENTS_CAP = 4;
const visiblePayments = computed(() => payments.value.slice(0, PAYMENTS_CAP));

// Anatomía de tarjeta del canon (§6): cabecera con círculo, cuerpo a ras.
// La base no lleva color de borde: las tarjetas teñidas ponen el suyo y dos
// colores de borde en el mismo elemento dejan que gane el orden del CSS.
const cardBase = 'overflow-hidden rounded-xl border';
const card = `${cardBase} border-slate-200/70 dark:border-darkmode-400`;
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const statCell = 'bg-white px-4 py-2.5 dark:bg-darkmode-600';
const statLabel = 'text-[11px] text-slate-500';
const statValue =
    'mt-0.5 text-xs font-medium text-slate-800 dark:text-slate-100';
const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';

const countdown = (iso: string | null | undefined) =>
    countdownLabel(iso, ctx.nowMs.value);
const tone = (iso: string | null | undefined) => stayTone(iso, ctx.nowMs.value);
</script>

<template>
    <div class="space-y-4">
        <!-- Vender: entregar el cuarto ahora si se puede, y apartarlo para
             otra fecha, que casi siempre se puede aunque hoy esté ocupado,
             sucio o apartado. Quien decide si las fechas caben es el motor
             de disponibilidad, no el semáforo. -->
        <section
            v-if="roomSale.sellNow.length"
            :class="cardBase"
            class="border-primary/25 dark:border-primary/30"
        >
            <div :class="cardHeader" class="bg-primary/5 dark:bg-primary/10">
                <div
                    :class="sectionIcon"
                    class="border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="MousePointerClick" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <h3 class="text-sm font-medium">¿Qué necesitas hacer?</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        La habitación está libre. Elige cómo quieres venderla.
                    </p>
                </div>
            </div>
            <div
                class="grid grid-cols-1 gap-2 px-4 py-3"
                :class="
                    roomSale.actions.length > 2
                        ? 'sm:grid-cols-3'
                        : 'sm:grid-cols-2'
                "
            >
                <Button
                    v-for="action in roomSale.actions"
                    :key="action.key"
                    :variant="action.primary ? 'primary' : 'outline-primary'"
                    class="h-auto min-h-9 justify-start rounded-[0.5rem] px-3 py-2 text-left"
                    :class="
                        action.primary ? '' : 'bg-white dark:bg-darkmode-600'
                    "
                    @click="dispatchArrival(action.key, room)"
                >
                    <Lucide
                        :icon="action.icon"
                        class="mr-2.5 h-3.5 w-3.5 shrink-0"
                    />
                    <span class="min-w-0">
                        <span class="block text-xs font-medium">{{
                            action.label
                        }}</span>
                        <span
                            class="mt-0.5 block text-[11px] font-normal"
                            :class="
                                action.primary ? 'opacity-80' : 'text-slate-500'
                            "
                            >{{ action.hint }}</span
                        >
                    </span>
                </Button>
            </div>
        </section>

        <!-- Caseta de motel: el acceso ya se abrió y falta terminar de
             capturar. Va arriba de todo porque es lo que hay que hacer. -->
        <section
            v-if="room.active_stay?.arrival_pending"
            :class="cardBase"
            class="border-pending/30"
        >
            <div :class="cardHeader" class="bg-pending/5 dark:bg-pending/10">
                <div
                    :class="sectionIcon"
                    class="border-pending/20 bg-pending/10 text-pending"
                >
                    <Lucide icon="ClipboardPen" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <h3 class="text-sm font-medium">
                        Falta capturar esta llegada
                    </h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        La caseta abrió el acceso. Cuando regrese el papel,
                        anota la placa —o la identificación— y marca el cobro
                        que hizo el encargado.
                    </p>
                </div>
                <Button
                    variant="primary"
                    class="h-9 w-full rounded-[0.5rem] text-xs sm:w-auto"
                    @click="completeArrival(room)"
                >
                    <Lucide icon="ClipboardPen" class="mr-1.5 h-3.5 w-3.5" />
                    Completar registro
                </Button>
            </div>
        </section>

        <!-- Quién está adentro: identidad y salida arriba, cifras en rejilla,
             datos de la visita en la franja gris y las acciones al pie. -->
        <section v-if="room.active_stay" :class="card">
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="User" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <div class="text-[11px] text-slate-400">
                        Estancia activa
                    </div>
                    <h3 class="truncate text-sm font-medium">
                        {{ room.active_stay.guest_name }}
                    </h3>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        {{ room.active_stay.rate_plan ?? 'Sin tarifa' }}
                        ·
                        {{ formatChannel(room.active_stay.channel) }}
                    </p>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-[11px] text-slate-400">Sale</div>
                    <div
                        class="text-xs font-semibold"
                        :class="tone(room.active_stay.planned_end_at_iso)"
                    >
                        {{ countdown(room.active_stay.planned_end_at_iso) }}
                    </div>
                </div>
            </div>

            <dl
                class="grid grid-cols-2 gap-px border-b border-slate-200/60 bg-slate-200/60 sm:grid-cols-3 dark:border-darkmode-400 dark:bg-darkmode-400"
            >
                <div :class="statCell">
                    <dt :class="statLabel">Entrada</dt>
                    <dd :class="statValue">
                        {{ room.active_stay.check_in_at ?? '—' }}
                    </dd>
                </div>
                <div :class="statCell">
                    <dt :class="statLabel">Salida prevista</dt>
                    <dd :class="statValue">
                        {{ room.active_stay.planned_end_at ?? '—' }}
                    </dd>
                </div>
                <div :class="statCell">
                    <dt :class="statLabel">Hospedaje</dt>
                    <dd :class="statValue">
                        {{ formatMoney(room.active_stay.amount) }}
                    </dd>
                </div>
                <div :class="statCell">
                    <dt :class="statLabel">Consumos</dt>
                    <dd :class="statValue">
                        {{ formatMoney(room.active_stay.consumos_total) }}
                    </dd>
                </div>
                <div :class="statCell">
                    <dt :class="statLabel">Total acumulado</dt>
                    <dd :class="statValue">
                        {{ formatMoney(room.active_stay.total_due) }}
                    </dd>
                </div>
                <div
                    class="px-4 py-2.5"
                    :class="
                        room.active_stay.balance_due > 0
                            ? 'bg-danger/5 dark:bg-danger/10'
                            : 'bg-white dark:bg-darkmode-600'
                    "
                >
                    <dt
                        class="text-[11px]"
                        :class="
                            room.active_stay.balance_due > 0
                                ? 'text-danger'
                                : 'text-slate-500'
                        "
                    >
                        Saldo pendiente
                    </dt>
                    <dd
                        class="mt-0.5 text-xs font-semibold"
                        :class="
                            room.active_stay.balance_due > 0
                                ? 'text-danger'
                                : 'text-success'
                        "
                    >
                        {{
                            room.active_stay.balance_due > 0
                                ? formatMoney(room.active_stay.balance_due)
                                : 'Al corriente'
                        }}
                    </dd>
                </div>
            </dl>

            <!-- Datos de la visita: personas, vehículo e identificación. -->
            <div
                v-if="
                    room.active_stay.num_people > 0 ||
                    room.active_stay.vehicle_plate ||
                    room.active_stay.vehicle_id ||
                    (canViewDocuments &&
                        (room.active_stay.id_document_type ||
                            room.active_stay.id_document_photos.length))
                "
                class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-2.5 text-xs dark:border-darkmode-400 dark:bg-darkmode-700/40"
            >
                <span v-if="room.active_stay.num_people > 0" :class="stripItem">
                    <Lucide icon="Users" class="h-3.5 w-3.5 shrink-0" />
                    {{ room.active_stay.num_people }}
                    {{
                        room.active_stay.num_people === 1
                            ? 'persona'
                            : 'personas'
                    }}
                </span>
                <!-- Motel: la visita se reconoce por el carro. La ficha guarda
                     sus visitas anteriores, así que se abre desde aquí. -->
                <a
                    v-if="room.active_stay.vehicle_id"
                    :href="`/vehiculos/${room.active_stay.vehicle_id}`"
                    class="inline-flex items-center gap-1.5 text-primary hover:underline"
                    title="Ver la ficha del vehículo"
                >
                    <Lucide icon="Car" class="h-3.5 w-3.5 shrink-0" />
                    {{ room.active_stay.vehicle_plate ?? 'Ficha del vehículo'
                    }}<template v-if="room.active_stay.vehicle_desc">
                        · {{ room.active_stay.vehicle_desc }}</template
                    >
                </a>
                <span
                    v-else-if="room.active_stay.vehicle_plate"
                    :class="stripItem"
                >
                    <Lucide icon="Car" class="h-3.5 w-3.5 shrink-0" />
                    {{ room.active_stay.vehicle_plate
                    }}<template v-if="room.active_stay.vehicle_desc">
                        · {{ room.active_stay.vehicle_desc }}</template
                    >
                </span>
                <!-- Identificación del huésped a pie (exprés motel): tipo y
                     fotos, solo con permiso. -->
                <template
                    v-if="
                        canViewDocuments &&
                        (room.active_stay.id_document_type ||
                            room.active_stay.id_document_photos.length)
                    "
                >
                    <span :class="stripItem">
                        <Lucide icon="IdCard" class="h-3.5 w-3.5 shrink-0" />
                        {{
                            documentTypeLabels[
                                room.active_stay.id_document_type ?? 'otro'
                            ] ?? 'Documento'
                        }}
                    </span>
                    <a
                        v-for="(photo, index) in room.active_stay
                            .id_document_photos"
                        :key="photo"
                        :href="photo"
                        target="_blank"
                        class="inline-flex items-center gap-1 text-primary hover:underline"
                    >
                        <Lucide icon="Camera" class="h-3.5 w-3.5" />
                        Foto {{ index + 1 }}
                    </a>
                </template>
            </div>

            <!-- Por qué debe lo que debe: el saldo solo dice cuánto falta, y
                 en el mostrador la pregunta que sigue es "¿ya me pagó algo?". -->
            <div
                v-if="payments.length"
                class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
            >
                <div :class="sectionLabel">Ya pagado</div>
                <div
                    class="mt-1.5 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="payment in visiblePayments"
                        :key="payment.id"
                        class="flex items-center gap-3 py-1.5 text-xs"
                    >
                        <span class="min-w-0 flex-1 truncate">
                            {{ payment.kind_label }}
                            <span class="text-slate-500"
                                >· {{ payment.method_label
                                }}<template v-if="payment.reference">
                                    · {{ payment.reference }}</template
                                ></span
                            >
                        </span>
                        <span
                            class="hidden shrink-0 text-[11px] text-slate-500 sm:inline"
                            >{{ payment.paid_at }}</span
                        >
                        <span class="shrink-0 font-medium">{{
                            formatMoney(payment.amount)
                        }}</span>
                    </div>
                </div>
                <p
                    v-if="payments.length > PAYMENTS_CAP"
                    class="mt-1 text-[11px] text-slate-400"
                >
                    Y {{ payments.length - PAYMENTS_CAP }} más en la cuenta
                    completa.
                </p>
            </div>

            <!-- Las de apoyo a la izquierda y la salida al extremo derecho,
                 como el pie de un modal. En el teléfono, la salida a lo ancho. -->
            <div
                v-if="canManageReservations || canChargeConsumption"
                class="grid grid-cols-2 gap-2 px-4 py-3 sm:flex sm:flex-wrap sm:items-center"
            >
                <Button
                    v-if="canChargeConsumption"
                    variant="outline-secondary"
                    class="h-9 justify-center rounded-[0.5rem] px-2.5 text-xs whitespace-nowrap"
                    @click="openPos(room.active_stay.id)"
                >
                    <Lucide icon="ReceiptText" class="mr-1.5 h-3.5 w-3.5" />
                    Cargar consumo
                </Button>
                <Button
                    v-if="
                        canManageReservations && room.active_stay.reservation_id
                    "
                    variant="outline-secondary"
                    class="h-9 justify-center rounded-[0.5rem] px-2.5 text-xs whitespace-nowrap"
                    @click="
                        openReservationDetail(room.active_stay.reservation_id)
                    "
                >
                    <Lucide icon="CalendarSearch" class="mr-1.5 h-3.5 w-3.5" />
                    Ver reserva
                </Button>
                <!-- Antes, "una noche más" o mover de cuarto a alguien que ya
                     está adentro obligaba a registrar su salida y darle
                     entrada otra vez, perdiendo el folio. -->
                <Button
                    v-if="canManageReservations"
                    variant="outline-secondary"
                    class="h-9 justify-center rounded-[0.5rem] px-2.5 text-xs whitespace-nowrap"
                    @click="openExtend(room)"
                >
                    <Lucide icon="CalendarPlus" class="mr-1.5 h-3.5 w-3.5" />
                    Extender
                </Button>
                <Button
                    v-if="canManageReservations"
                    variant="outline-secondary"
                    class="h-9 justify-center rounded-[0.5rem] px-2.5 text-xs whitespace-nowrap"
                    @click="openMove(room)"
                >
                    <Lucide icon="ArrowRightLeft" class="mr-1.5 h-3.5 w-3.5" />
                    Cambiar de cuarto
                </Button>
                <Button
                    v-if="canManageReservations"
                    variant="primary"
                    class="col-span-2 h-9 justify-center rounded-[0.5rem] text-xs whitespace-nowrap sm:ml-auto"
                    :disabled="busyAction === `stay:${room.active_stay.id}`"
                    @click="requestCheckout(room)"
                >
                    <Lucide icon="LogOut" class="mr-1.5 h-3.5 w-3.5" />
                    {{
                        busyAction === `stay:${room.active_stay.id}`
                            ? 'Procesando…'
                            : 'Registrar salida'
                    }}
                </Button>
            </div>
        </section>

        <!-- La próxima reserva: quién llega, cuándo y con qué. -->
        <section v-if="room.upcoming_reservation" :class="card">
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-info/10 bg-info/10 text-info"
                >
                    <Lucide icon="CalendarClock" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <div class="text-[11px] text-slate-400">
                        Reserva próxima
                    </div>
                    <h3 class="truncate text-sm font-medium">
                        {{ room.upcoming_reservation.guest_name }}
                    </h3>
                    <p class="mt-0.5 truncate text-xs text-slate-500">
                        {{ room.upcoming_reservation.code }} ·
                        {{
                            room.upcoming_reservation.rate_plan ?? 'Sin tarifa'
                        }}
                        · {{ room.upcoming_reservation.status_label }}
                    </p>
                </div>
                <span
                    v-if="room.upcoming_reservation.starts_today"
                    class="shrink-0 rounded-full bg-info/10 px-2 py-0.5 text-[11px] font-medium text-info"
                    >Llega hoy</span
                >
                <span
                    v-if="room.upcoming_reservation.payment_overdue"
                    class="shrink-0 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                    >Pago vencido</span
                >
            </div>

            <dl
                class="grid grid-cols-3 gap-px border-b border-slate-200/60 bg-slate-200/60 dark:border-darkmode-400 dark:bg-darkmode-400"
            >
                <div :class="statCell">
                    <dt :class="statLabel">Entrada</dt>
                    <dd :class="statValue">
                        {{ room.upcoming_reservation.starts_at }}
                    </dd>
                </div>
                <div :class="statCell">
                    <dt :class="statLabel">Salida</dt>
                    <dd :class="statValue">
                        {{ room.upcoming_reservation.ends_at }}
                    </dd>
                </div>
                <div :class="statCell">
                    <dt :class="statLabel">Monto</dt>
                    <dd :class="statValue">
                        {{
                            formatMoney(room.upcoming_reservation.total_amount)
                        }}
                    </dd>
                </div>
            </dl>

            <div
                v-if="
                    room.upcoming_reservation.eta ||
                    room.upcoming_reservation.vehicle_plate ||
                    room.upcoming_reservation.adults +
                        room.upcoming_reservation.children >
                        0
                "
                class="flex flex-wrap items-center gap-x-4 gap-y-1.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-2.5 text-xs dark:border-darkmode-400 dark:bg-darkmode-700/40"
            >
                <span v-if="room.upcoming_reservation.eta" :class="stripItem">
                    <Lucide icon="Clock" class="h-3.5 w-3.5 shrink-0" />
                    Llegada estimada {{ room.upcoming_reservation.eta }}
                </span>
                <span
                    v-if="room.upcoming_reservation.vehicle_plate"
                    :class="stripItem"
                >
                    <Lucide icon="Car" class="h-3.5 w-3.5 shrink-0" />
                    {{ room.upcoming_reservation.vehicle_plate }}
                </span>
                <span
                    v-if="
                        room.upcoming_reservation.adults +
                            room.upcoming_reservation.children >
                        0
                    "
                    :class="stripItem"
                >
                    <Lucide icon="Users" class="h-3.5 w-3.5 shrink-0" />
                    {{
                        guestCountLabel(
                            room.upcoming_reservation.adults,
                            room.upcoming_reservation.children,
                        )
                    }}
                </span>
            </div>

            <div
                v-if="canManageReservations"
                class="grid grid-cols-1 gap-2 px-4 py-3 sm:flex sm:flex-wrap sm:items-center sm:justify-end"
            >
                <Button
                    variant="outline-secondary"
                    class="h-9 justify-center rounded-[0.5rem] px-2.5 text-xs whitespace-nowrap"
                    @click="openReservationDetail(room.upcoming_reservation.id)"
                >
                    <Lucide icon="CalendarSearch" class="mr-1.5 h-3.5 w-3.5" />
                    Ver reserva
                </Button>
                <!-- Anticipada cuando la entrada es de otro día: botón
                     secundario y con nombre propio, porque registrarla hoy
                     adelanta la entrada real y regala las noches de más. -->
                <Button
                    v-if="manualCheckinAllowed"
                    :variant="
                        room.upcoming_reservation.starts_today
                            ? 'primary'
                            : 'outline-primary'
                    "
                    class="h-9 justify-center rounded-[0.5rem] px-2.5 text-xs whitespace-nowrap"
                    :disabled="
                        busyAction ===
                        `reservation:${room.upcoming_reservation.id}`
                    "
                    @click="checkInReservation(room)"
                >
                    <Lucide icon="LogIn" class="mr-1.5 h-3.5 w-3.5" />
                    {{
                        busyAction ===
                        `reservation:${room.upcoming_reservation.id}`
                            ? 'Procesando…'
                            : room.upcoming_reservation.starts_today
                              ? 'Registrar llegada'
                              : 'Registrar llegada anticipada'
                    }}
                </Button>
            </div>
        </section>

        <!-- Precios: con el cuarto libre, la pregunta del mostrador. -->
        <section v-if="room.status === 'available'" :class="card">
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-success/10 bg-success/10 text-success"
                >
                    <Lucide icon="Tag" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <h3 class="text-sm font-medium">Precios disponibles</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Según el tiempo que ocuparán la habitación.
                    </p>
                </div>
                <div v-if="room.rate_plans.length" class="shrink-0 text-right">
                    <div class="text-[11px] text-slate-400">Desde</div>
                    <div class="text-sm font-semibold text-success">
                        {{ formatMoney(room.rate_plans[0].price) }}
                    </div>
                </div>
            </div>

            <div
                v-if="room.rate_plans.length"
                class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
            >
                <div
                    v-for="plan in room.rate_plans"
                    :key="plan.id"
                    class="flex items-center gap-3 px-4 py-2.5"
                >
                    <Lucide
                        icon="Clock"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-xs font-medium">
                            {{ plan.name }}
                        </div>
                        <div class="text-[11px] text-slate-500">
                            {{ plan.duration_label }}
                        </div>
                    </div>
                    <div class="shrink-0 text-xs font-semibold">
                        {{ formatMoney(plan.price) }}
                    </div>
                </div>
            </div>
            <p v-else class="px-4 py-3 text-xs text-slate-500">
                Este tipo de habitación no tiene tarifas activas; agrégalas en
                <a
                    :href="route('tenant.catalog')"
                    class="font-medium text-primary hover:underline"
                    >Zonas y tipos</a
                >.
            </p>

            <p
                v-if="room.price_modifier !== null"
                class="flex items-center gap-1.5 border-t border-slate-200/60 bg-slate-50/70 px-4 py-2.5 text-xs dark:border-darkmode-400 dark:bg-darkmode-700/40"
                :class="
                    room.price_modifier < 0 ? 'text-success' : 'text-slate-500'
                "
            >
                <Lucide icon="Tag" class="h-3.5 w-3.5 shrink-0" />
                Los precios incluyen el ajuste de esta habitación ({{
                    priceModifierLabel(room.price_modifier)
                }}
                por estancia)
            </p>
        </section>

        <!-- El cuarto no está para entregarse hoy, pero el calendario es
             otra cosa: apartarlo para una fecha que no choque sí se puede, y
             antes esto quedaba escondido detrás del semáforo. -->
        <section
            v-if="!roomSale.sellNow.length && roomSale.bookAhead"
            :class="card"
        >
            <div :class="cardHeader">
                <div
                    :class="sectionIcon"
                    class="border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="CalendarPlus" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <h3 class="text-sm font-medium">Otras fechas</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ roomSale.reason }}
                    </p>
                </div>
                <Button
                    variant="outline-primary"
                    class="h-9 w-full rounded-[0.5rem] text-xs sm:w-auto"
                    @click="dispatchArrival(roomSale.bookAhead.key, room)"
                >
                    <Lucide
                        :icon="roomSale.bookAhead.icon"
                        class="mr-1.5 h-3.5 w-3.5"
                    />
                    {{ roomSale.bookAhead.label }}
                </Button>
            </div>
        </section>

        <!-- Ni entregar ni apartar: mantenimiento o candado por usos. Se dice
             qué hay que hacer para devolverla a la venta, en vez de dejar el
             hueco donde antes iban los botones. -->
        <section
            v-else-if="!roomSale.actions.length && roomSale.reason"
            :class="cardBase"
            class="border-warning/30"
        >
            <div
                class="flex flex-wrap items-center gap-2.5 bg-warning/5 px-4 py-3 dark:bg-warning/10"
            >
                <div
                    :class="sectionIcon"
                    class="border-warning/20 bg-warning/10 text-warning"
                >
                    <Lucide icon="Ban" class="h-4 w-4" />
                </div>
                <div class="min-w-0 flex-1 basis-0">
                    <h3 class="text-sm font-medium">Fuera de venta</h3>
                    <p
                        class="mt-0.5 text-xs text-slate-600 dark:text-slate-300"
                    >
                        {{ roomSale.reason }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
