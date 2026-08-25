<script setup lang="ts">
import { computed, inject } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { FloorPlanKey } from '../../context';
import { transitionMeta } from '../../status';
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
 * está adentro con su cuenta, la próxima reserva, y el semáforo.
 *
 * Viene de la ficha vieja, que traía esto y todo lo demás en un solo scroll.
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
    canManage,
    canManageReservations,
    canChargeConsumption,
    canViewDocuments,
    manualCheckinAllowed,
    arrivalActions,
    transitions,
    busyAction,
    saving,
    changeStatus,
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

const countdown = (iso: string | null | undefined) =>
    countdownLabel(iso, ctx.nowMs.value);
const tone = (iso: string | null | undefined) => stayTone(iso, ctx.nowMs.value);
</script>

<template>
    <div class="space-y-5">
        <section
            v-if="room.status === 'available' && canManageReservations"
            class="rounded-2xl border border-primary/20 bg-primary/5 p-5 dark:border-primary/30 dark:bg-primary/10"
        >
            <div class="flex items-start gap-3">
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                >
                    <Lucide icon="MousePointerClick" class="h-5 w-5" />
                </div>
                <div>
                    <h3
                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        ¿Qué necesitas hacer?
                    </h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        La habitación está libre. Elige cómo quieres venderla.
                    </p>
                </div>
            </div>
            <div
                class="mt-4 grid grid-cols-1 gap-3"
                :class="
                    arrivalActions.length > 2
                        ? 'sm:grid-cols-3'
                        : 'sm:grid-cols-2'
                "
            >
                <Button
                    v-for="action in arrivalActions"
                    :key="action.key"
                    :variant="action.primary ? 'primary' : 'outline-primary'"
                    class="h-auto min-h-14 justify-start rounded-xl px-4 py-3 text-left"
                    :class="
                        action.primary ? '' : 'bg-white dark:bg-darkmode-600'
                    "
                    @click="dispatchArrival(action.key, room)"
                >
                    <Lucide :icon="action.icon" class="mr-3 h-5 w-5 shrink-0" />
                    <span class="min-w-0">
                        <span class="block font-semibold">{{
                            action.label
                        }}</span>
                        <span
                            class="mt-0.5 block text-xs font-normal"
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
            class="rounded-2xl border border-pending/30 bg-pending/5 p-5 dark:border-pending/30 dark:bg-pending/10"
        >
            <div class="flex items-start gap-3">
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-pending/20 bg-pending/10 text-pending"
                >
                    <Lucide icon="ClipboardPen" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <h3
                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Falta capturar esta llegada
                    </h3>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                        La caseta abrió el acceso. Cuando regrese el papel,
                        anota la placa —o la identificación— y marca el cobro
                        que hizo el encargado.
                    </p>
                </div>
            </div>
            <Button
                variant="primary"
                class="mt-4 min-h-11 w-full justify-center rounded-[0.5rem] sm:w-auto"
                @click="completeArrival(room)"
            >
                <Lucide icon="ClipboardPen" class="mr-2 h-4 w-4" />
                Completar registro
            </Button>
        </section>

        <section
            v-if="room.active_stay"
            class="rounded-2xl border border-primary/20 bg-primary/5 p-5 dark:border-primary/30 dark:bg-primary/10"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3
                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Estancia activa
                    </h3>
                    <p class="mt-1 text-lg font-semibold">
                        {{ room.active_stay.guest_name }}
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{ room.active_stay.rate_plan ?? 'Sin tarifa' }}
                        ·
                        {{ formatChannel(room.active_stay.channel) }}
                    </p>
                </div>
                <div
                    class="rounded-xl bg-white px-3 py-2 text-right shadow-sm dark:bg-darkmode-600"
                >
                    <div
                        class="text-[11px] tracking-wide text-slate-500 uppercase"
                    >
                        Salida prevista
                    </div>
                    <div
                        class="mt-1 text-sm font-semibold"
                        :class="tone(room.active_stay.planned_end_at_iso)"
                    >
                        {{ countdown(room.active_stay.planned_end_at_iso) }}
                    </div>
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Entrada registrada</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ room.active_stay.check_in_at ?? '—' }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Fin estimado</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ room.active_stay.planned_end_at ?? '—' }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Hospedaje</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ formatMoney(room.active_stay.amount) }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Consumos</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ formatMoney(room.active_stay.consumos_total) }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Total acumulado</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ formatMoney(room.active_stay.total_due) }}
                    </dd>
                </div>
                <div
                    class="rounded-xl p-3"
                    :class="
                        room.active_stay.balance_due > 0
                            ? 'bg-danger/10'
                            : 'bg-white/80 dark:bg-darkmode-600/80'
                    "
                >
                    <dt
                        :class="
                            room.active_stay.balance_due > 0
                                ? 'text-danger'
                                : 'text-slate-500'
                        "
                    >
                        Saldo pendiente
                    </dt>
                    <dd
                        class="mt-1 font-medium"
                        :class="
                            room.active_stay.balance_due > 0
                                ? 'text-danger'
                                : 'text-slate-900 dark:text-slate-100'
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

            <!-- Motel: la visita se reconoce por el carro. La ficha guarda sus
                 visitas anteriores, así que se abre desde aquí. -->
            <a
                v-if="room.active_stay.vehicle_id"
                :href="`/vehiculos/${room.active_stay.vehicle_id}`"
                class="mt-3 inline-flex items-center gap-2 text-sm text-primary hover:underline"
            >
                <Lucide icon="Car" class="h-4 w-4" />
                Ver la ficha del vehículo
                <span
                    v-if="room.active_stay.vehicle_plate"
                    class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs tracking-wider text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                    >{{ room.active_stay.vehicle_plate }}</span
                >
            </a>

            <!-- Por qué debe lo que debe: el saldo solo dice cuánto falta, y
                 en el mostrador la pregunta que sigue es "¿ya me pagó algo?". -->
            <div
                v-if="payments.length"
                class="mt-4 rounded-xl bg-white/80 p-4 dark:bg-darkmode-600/80"
            >
                <div class="mb-2 text-xs font-medium text-slate-500">
                    Ya pagado
                </div>
                <div
                    v-for="payment in payments"
                    :key="payment.id"
                    class="flex items-center gap-3 py-1 text-sm"
                >
                    <span class="min-w-0 flex-1 truncate">
                        {{ payment.kind_label }} ·
                        <span class="text-slate-500"
                            >{{ payment.method_label
                            }}<template v-if="payment.reference">
                                · {{ payment.reference }}</template
                            ></span
                        >
                    </span>
                    <span class="shrink-0 text-xs text-slate-500">{{
                        payment.paid_at
                    }}</span>
                    <span class="shrink-0 font-medium">{{
                        formatMoney(payment.amount)
                    }}</span>
                </div>
            </div>

            <div
                v-if="
                    room.active_stay.num_people > 0 ||
                    room.active_stay.vehicle_plate
                "
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300"
            >
                <span
                    v-if="room.active_stay.num_people > 0"
                    class="inline-flex items-center gap-1.5"
                >
                    <Lucide
                        icon="Users"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                    {{ room.active_stay.num_people }}
                    {{
                        room.active_stay.num_people === 1
                            ? 'persona'
                            : 'personas'
                    }}
                </span>
                <span
                    v-if="room.active_stay.vehicle_plate"
                    class="inline-flex items-center gap-1.5"
                >
                    <Lucide
                        icon="Car"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                    {{ room.active_stay.vehicle_plate
                    }}<template v-if="room.active_stay.vehicle_desc">
                        ·
                        {{ room.active_stay.vehicle_desc }}</template
                    >
                </span>
            </div>

            <!-- Identificación del huésped a pie (exprés
                         motel): tipo + fotos, solo con permiso. -->
            <div
                v-if="
                    canViewDocuments &&
                    (room.active_stay.id_document_type ||
                        room.active_stay.id_document_photos.length)
                "
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-slate-600 dark:text-slate-300"
            >
                <span class="inline-flex items-center gap-1.5">
                    <Lucide
                        icon="IdCard"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                    Identificación:
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
            </div>

            <div
                v-if="canManageReservations || canChargeConsumption"
                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3"
            >
                <Button
                    v-if="canChargeConsumption"
                    variant="outline-primary"
                    class="min-h-11 justify-center"
                    @click="openPos(room.active_stay.id)"
                >
                    <Lucide icon="ReceiptText" class="mr-2 h-5 w-5" />
                    Cargar consumo
                </Button>
                <Button
                    v-if="
                        canManageReservations && room.active_stay.reservation_id
                    "
                    variant="outline-primary"
                    class="min-h-11 justify-center"
                    @click="
                        openReservationDetail(room.active_stay.reservation_id)
                    "
                >
                    <Lucide icon="CalendarSearch" class="mr-2 h-5 w-5" />
                    Ver reserva
                </Button>
                <!-- Antes, "una noche más" o mover de cuarto a
                             alguien que ya está adentro obligaba a
                             registrar su salida y darle entrada otra
                             vez, perdiendo el folio. -->
                <Button
                    v-if="canManageReservations"
                    variant="outline-primary"
                    class="min-h-11 justify-center"
                    @click="openExtend(room)"
                >
                    <Lucide icon="CalendarPlus" class="mr-2 h-5 w-5" />
                    Extender
                </Button>
                <Button
                    v-if="canManageReservations"
                    variant="outline-primary"
                    class="min-h-11 justify-center"
                    @click="openMove(room)"
                >
                    <Lucide icon="ArrowRightLeft" class="mr-2 h-5 w-5" />
                    Cambiar de cuarto
                </Button>
                <Button
                    v-if="canManageReservations"
                    variant="primary"
                    class="min-h-11 justify-center"
                    :disabled="busyAction === `stay:${room.active_stay.id}`"
                    @click="requestCheckout(room)"
                >
                    <Lucide icon="LogOut" class="mr-2 h-5 w-5" />
                    {{
                        busyAction === `stay:${room.active_stay.id}`
                            ? 'Procesando…'
                            : 'Registrar salida'
                    }}
                </Button>
            </div>
        </section>

        <section
            v-if="room.upcoming_reservation"
            class="rounded-2xl border border-info/20 bg-info/5 p-5 dark:border-info/30 dark:bg-info/10"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3
                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Reserva próxima
                    </h3>
                    <p class="mt-1 text-lg font-semibold">
                        {{ room.upcoming_reservation.guest_name }}
                    </p>
                    <p class="mt-1 text-sm text-slate-500">
                        {{
                            room.upcoming_reservation.rate_plan ?? 'Sin tarifa'
                        }}
                        ·
                        {{ room.upcoming_reservation.status_label }}
                    </p>
                </div>
                <div
                    class="rounded-xl bg-white px-3 py-2 text-right shadow-sm dark:bg-darkmode-600"
                >
                    <div
                        class="text-[11px] tracking-wide text-slate-500 uppercase"
                    >
                        Llegada
                    </div>
                    <div class="mt-1 text-sm font-semibold text-info">
                        {{ room.upcoming_reservation.starts_at }}
                    </div>
                    <div
                        v-if="room.upcoming_reservation.starts_today"
                        class="mt-1 text-[11px] font-medium tracking-wide text-info uppercase"
                    >
                        Llega hoy
                    </div>
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Entrada</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ room.upcoming_reservation.starts_at }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Salida</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ room.upcoming_reservation.ends_at }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Folio</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
                        {{ room.upcoming_reservation.code }}
                    </dd>
                </div>
                <div class="rounded-xl bg-white/80 p-3 dark:bg-darkmode-600/80">
                    <dt class="text-slate-500">Monto</dt>
                    <dd
                        class="mt-1 font-medium text-slate-900 dark:text-slate-100"
                    >
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
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300"
            >
                <span
                    v-if="room.upcoming_reservation.eta"
                    class="inline-flex items-center gap-1.5"
                >
                    <Lucide
                        icon="Clock"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                    Llegada estimada
                    {{ room.upcoming_reservation.eta }}
                </span>
                <span
                    v-if="room.upcoming_reservation.vehicle_plate"
                    class="inline-flex items-center gap-1.5"
                >
                    <Lucide
                        icon="Car"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                    {{ room.upcoming_reservation.vehicle_plate }}
                </span>
                <span
                    v-if="
                        room.upcoming_reservation.adults +
                            room.upcoming_reservation.children >
                        0
                    "
                    class="inline-flex items-center gap-1.5"
                >
                    <Lucide
                        icon="Users"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
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
                class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2"
            >
                <Button
                    v-if="manualCheckinAllowed"
                    variant="primary"
                    class="min-h-11 justify-center"
                    :disabled="
                        busyAction ===
                        `reservation:${room.upcoming_reservation.id}`
                    "
                    @click="checkInReservation(room)"
                >
                    <Lucide icon="LogIn" class="mr-2 h-5 w-5" />
                    {{
                        busyAction ===
                        `reservation:${room.upcoming_reservation.id}`
                            ? 'Procesando…'
                            : 'Registrar llegada'
                    }}
                </Button>
                <Button
                    variant="outline-primary"
                    class="min-h-11 justify-center"
                    @click="openReservationDetail(room.upcoming_reservation.id)"
                >
                    <Lucide icon="CalendarSearch" class="mr-2 h-5 w-5" />
                    Ver reserva
                </Button>
            </div>
        </section>

        <section
            v-if="room.status === 'available'"
            class="rounded-2xl border border-success/20 bg-success/5 p-5 dark:border-success/30 dark:bg-success/10"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3
                        class="text-base font-semibold text-slate-900 dark:text-slate-100"
                    >
                        Precios disponibles
                    </h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Elige según el tiempo que ocuparán la habitación.
                    </p>
                </div>
                <div
                    class="rounded-xl bg-white px-4 py-3 text-right shadow-sm dark:bg-darkmode-600"
                >
                    <div
                        class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                    >
                        Desde
                    </div>
                    <div class="mt-1 text-lg font-semibold text-success">
                        {{
                            room.rate_plans.length
                                ? formatMoney(room.rate_plans[0].price)
                                : '—'
                        }}
                    </div>
                </div>
            </div>

            <div class="mt-4 space-y-2">
                <div
                    v-for="plan in room.rate_plans"
                    :key="plan.id"
                    class="flex items-center justify-between gap-4 rounded-xl bg-white/90 p-4 shadow-sm dark:bg-darkmode-600/80"
                >
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="Clock" class="h-5 w-5" />
                        </div>
                        <div>
                            <div
                                class="text-base font-semibold text-slate-900 dark:text-slate-100"
                            >
                                {{ plan.name }}
                            </div>
                            <div class="mt-0.5 text-sm text-slate-500">
                                Duración:
                                {{ plan.duration_label }}
                            </div>
                        </div>
                    </div>
                    <div
                        class="shrink-0 text-lg font-semibold text-slate-900 dark:text-slate-100"
                    >
                        {{ formatMoney(plan.price) }}
                    </div>
                </div>
            </div>

            <p
                v-if="room.price_modifier !== null"
                class="mt-3 flex items-center gap-1.5 text-xs"
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

        <section
            v-if="
                room.status === 'dirty' ||
                room.status === 'cleaning' ||
                room.status === 'maintenance'
            "
            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
        >
            <h3 class="text-sm font-medium text-slate-900 dark:text-slate-100">
                Contexto operativo
            </h3>
            <p class="mt-2 text-sm text-slate-500">
                <span v-if="room.status === 'dirty'"
                    >La habitación está pendiente de limpieza antes de volver a
                    venderse.</span
                >
                <span v-else-if="room.status === 'cleaning'"
                    >El cuarto está en proceso de limpieza; al terminar, el
                    semáforo puede volver a disponible.</span
                >
                <span v-else
                    >La habitación está fuera de servicio por mantenimiento o
                    bloqueo manual.</span
                >
            </p>

            <div
                v-if="room.status === 'maintenance' && room.maintenance_notes"
                class="mt-3 flex items-start gap-2 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-slate-700 dark:text-slate-200"
            >
                <Lucide
                    icon="Wrench"
                    class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                />
                <span class="whitespace-pre-line">{{
                    room.maintenance_notes
                }}</span>
            </div>
        </section>

        <section
            v-if="canManage && room.transitions.length"
            class="rounded-2xl border border-slate-200/70 p-4 dark:border-darkmode-400"
        >
            <h3
                class="text-base font-semibold text-slate-900 dark:text-slate-100"
            >
                Limpieza y mantenimiento
            </h3>
            <p class="mt-1 text-sm text-slate-500">
                Aquí solo vive la operación física del cuarto (limpieza y
                mantenimiento). Reservada y ocupada se mueven solas cuando creas
                una reserva o registras la llegada del huésped.
            </p>
            <p
                v-if="room.status === 'reserved' && room.upcoming_reservation"
                class="mt-2 flex items-start gap-2 rounded-xl border border-info/30 bg-info/5 p-3 text-sm text-slate-600 dark:text-slate-300"
            >
                <Lucide icon="Info" class="mt-0.5 h-4 w-4 shrink-0 text-info" />
                <span>
                    Esta habitación está apartada por la reserva
                    {{ room.upcoming_reservation.code }}; para liberarla,
                    cancela la reserva desde "Ver reserva".
                </span>
            </p>
            <div class="mt-4 flex flex-col gap-2">
                <Button
                    v-for="status in room.transitions"
                    :key="status"
                    :variant="transitionMeta[status].variant"
                    :disabled="saving"
                    class="min-h-11 w-full justify-center py-2.5"
                    @click="changeStatus(room, status)"
                >
                    <Lucide
                        :icon="transitionMeta[status].icon"
                        class="mr-2 h-5 w-5"
                    />
                    {{ transitionMeta[status].label }}
                </Button>
            </div>
        </section>
    </div>
</template>
