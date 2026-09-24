<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { useModules } from '@/composables/useModules';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import PaymentModal from './PaymentModal.vue';
import ReopenDialog from './ReopenDialog.vue';
import type { ReservationRow } from './types';

/**
 * Ficha propia de una reserva (/reservas/{id}).
 *
 * El detalle vivía solo en el panel lateral de /reservas, que se cierra con
 * cualquier clic y no tenía lugar para el dinero. Aquí están el detalle, los
 * pagos y la historia, y desde aquí se hace todo: editar, cobrar o registrar
 * un pago (también una transferencia ya verificada), confirmar, registrar la
 * llegada, marcar que no llegó, cancelar y reabrir. Caso real cabañas
 * 2026-09-11: el huésped depositó, su cobro había vencido y no había dónde
 * registrar ese dinero el día de su llegada.
 */
const props = defineProps<{
    reservation: ReservationRow;
    conversationId: number | null;
    /** Último archivo que el huésped mandó por la conversación de la reserva. */
    proofReceivedAt: string | null;
    canManage: boolean;
    /** En check-in automático puro la llegada la registra el reloj. */
    manualCheckinAllowed: boolean;
    gatewayAvailable: boolean;
    chargeOptions?: { gateway: string | null; transfer: boolean };
    holdMinutes: number;
    /** Comprobantes que el huésped mandó por el chat de esta reserva. */
    chatReceipts?: {
        media_id: number;
        name: string;
        is_image: boolean;
        url: string;
        at: string;
        verdict: string | null;
        summary: string | null;
        amount: number | null;
        reference: string | null;
    }[];
}>();

const toast = useToasts();
const { hasModule } = useModules();
const r = computed(() => props.reservation);

const paymentModal = ref<InstanceType<typeof PaymentModal> | null>(null);
const reopenTarget = ref<ReservationRow | null>(null);
const confirming = ref(false);

const live = computed(() =>
    ['pending', 'confirmed', 'checked_in'].includes(r.value.status),
);
/** Pendiente o confirmada: se edita, se registra su llegada o se cancela. */
const actionable = computed(() =>
    ['pending', 'confirmed'].includes(r.value.status),
);
const reopenable = computed(() =>
    ['cancelled', 'no_show'].includes(r.value.status),
);
const pending = computed(() => Number(r.value.pending_balance ?? 0));

/** Qué parte del total ya entró: distingue "pagó la mitad" de "ya pagó". */
const paidPercent = computed(() => {
    const total = Number(r.value.total_amount) || 0;

    if (total <= 0) {
        return 0;
    }

    return Math.min(
        100,
        Math.round((Number(r.value.paid_total ?? 0) / total) * 100),
    );
});

const statusStyles: Record<string, { tone: string; icon: Icon }> = {
    pending: { tone: 'warning', icon: 'Clock' },
    confirmed: { tone: 'primary', icon: 'CalendarCheck' },
    checked_in: { tone: 'success', icon: 'DoorOpen' },
    completed: { tone: 'slate', icon: 'CircleCheck' },
    cancelled: { tone: 'danger', icon: 'Ban' },
    no_show: { tone: 'warning', icon: 'UserX' },
};
const status = computed(
    () => statusStyles[r.value.status] ?? statusStyles.pending,
);
const toneCircle: Record<string, string> = {
    warning: 'border-warning/10 bg-warning/10 text-warning',
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
    danger: 'border-danger/10 bg-danger/10 text-danger',
    slate: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
};
const toneBadge: Record<string, string> = {
    warning: 'bg-warning/10 text-warning',
    primary: 'bg-primary/10 text-primary',
    success: 'bg-success/10 text-success',
    danger: 'bg-danger/10 text-danger',
    slate: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

const paymentTone = computed(() => {
    if (r.value.payment_status === 'paid') return toneBadge.success;
    if (r.value.payment_overdue) return toneBadge.danger;
    if (r.value.payment_status === 'partial')
        return 'bg-pending/10 text-pending';
    if (r.value.payment_status === 'deposit_paid') return toneBadge.primary;
    return toneBadge.slate;
});

const currency = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
});
const money = (value: number | string | null | undefined) =>
    currency.format(Number(value ?? 0));

const methodLabels: Record<string, string> = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
    gateway: 'Pago en línea',
};

const channelLabels: Record<string, string> = {
    front_desk: 'Mostrador',
    phone: 'Teléfono',
    web: 'Web',
    whatsapp: 'WhatsApp',
    walk_in: 'Llegó sin reserva',
    agent: 'Asistente IA',
};

const people = computed(() => {
    const adults = r.value.adults ?? r.value.num_people;
    const children = r.value.children ?? 0;

    return children > 0
        ? `${adults} adulto${adults === 1 ? '' : 's'} y ${children} menor${children === 1 ? '' : 'es'}`
        : `${adults} persona${adults === 1 ? '' : 's'}`;
});

const details = computed<{ label: string; value: string; icon: Icon }[]>(() => {
    const items: { label: string; value: string; icon: Icon }[] = [
        { label: 'Folio', value: r.value.code, icon: 'Hash' },
        {
            label: 'Habitación',
            value: r.value.room ? `Hab. ${r.value.room}` : 'Por asignar',
            icon: 'DoorClosed',
        },
        {
            label: 'Tipo',
            value: r.value.room_type ?? '—',
            icon: 'BedDouble',
        },
        { label: 'Llegada', value: r.value.starts_at, icon: 'LogIn' },
        { label: 'Salida', value: r.value.ends_at, icon: 'LogOut' },
        { label: 'Tarifa', value: r.value.rate_plan ?? '—', icon: 'Tag' },
        { label: 'Personas', value: people.value, icon: 'Users' },
        {
            label: 'Canal',
            value:
                channelLabels[r.value.source_channel] ?? r.value.source_channel,
            icon: 'Radio',
        },
        {
            label: 'Total',
            value: money(r.value.total_amount),
            icon: 'Receipt',
        },
    ];

    if (r.value.eta) {
        items.push({
            label: 'Hora estimada',
            value: r.value.eta,
            icon: 'Clock',
        });
    }

    if (r.value.vehicle_plate) {
        items.push({
            label: 'Vehículo',
            value: [r.value.vehicle_plate, r.value.vehicle_desc]
                .filter(Boolean)
                .join(' · '),
            icon: 'Car',
        });
    }

    return items;
});

const hasCharges = computed(
    () =>
        (r.value.extra_charges ?? []).length > 0 ||
        (r.value.products ?? []).length > 0 ||
        (r.value.extras ?? []).length > 0 ||
        (r.value.experiences ?? []).length > 0,
);

function openPayment() {
    paymentModal.value?.open(r.value);
}

async function confirmReservation() {
    if (confirming.value) return;
    confirming.value = true;
    try {
        await axios.patch(`/api/reservations/${r.value.id}/confirm`);
        toast.success(
            'Reserva confirmada',
            `${r.value.code} quedó confirmada.`,
        );
        router.reload();
    } catch (error: any) {
        toast.error(
            'No se pudo confirmar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        confirming.value = false;
    }
}

const reload = () => router.reload();

// ── Cupón de la reserva (módulo cupones) ──
// El descuento solo se podía aplicar al reservar: si el huésped no escribió
// el código, o lo escribió mal, no había de dónde dárselo. Caso real cabañas
// 2026-09-12: el 30% de un cupón anunciado en video, y la reserva cobrando
// completo sin forma de corregirla.
const discount = computed(() => Number(r.value.discount_amount ?? 0));
/** Lo que costaría sin cupón: el total ya trae el descuento adentro. */
const listPrice = computed(
    () => Number(r.value.total_amount ?? 0) + discount.value,
);
const canCoupon = computed(
    () => props.canManage && live.value && hasModule('cupones'),
);
const couponInput = ref('');
const couponOpen = ref(false);
const couponBusy = ref(false);

function openCoupon() {
    couponInput.value = '';
    couponOpen.value = true;
}

async function applyCoupon() {
    const code = couponInput.value.trim();

    if (!code || couponBusy.value) return;

    couponBusy.value = true;
    try {
        const { data } = await axios.post(
            `/api/reservations/${r.value.id}/coupon`,
            { code },
        );
        couponOpen.value = false;
        toast.success(
            `Cupón ${data.coupon_code} aplicado`,
            `El total bajó a ${money(data.total_amount)} (−${money(data.discount_amount)}).`,
        );
        router.reload();
    } catch (error: any) {
        toast.error(
            'No se pudo aplicar el cupón',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        couponBusy.value = false;
    }
}

async function removeCoupon() {
    if (couponBusy.value) return;

    couponBusy.value = true;
    try {
        const { data } = await axios.delete(
            `/api/reservations/${r.value.id}/coupon`,
        );
        toast.success(
            'Cupón retirado',
            `El total volvió a ${money(data.total_amount)}.`,
        );
        router.reload();
    } catch (error: any) {
        toast.error(
            'No se pudo quitar el cupón',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        couponBusy.value = false;
    }
}

// Llegada desde otra pantalla con ?checkin=1 (el modal de Próximas): abre
// el registro de llegada, con su fianza, sin repetir el formulario allá.
onMounted(() => {
    if (
        new URLSearchParams(window.location.search).get('checkin') &&
        props.canManage &&
        actionable.value &&
        props.manualCheckinAllowed
    ) {
        openCheckIn();
    }
});

// ── Registrar llegada (con la fianza del hotel, si la cobra) ──
const { methods: counterMethods, first: firstCounterMethod } =
    useCounterMethods();
const checkInOpen = ref(false);
const checkInBusy = ref(false);
const guaranteeMethod = ref<CounterMethod>('cash');
const guaranteeReference = ref('');
const guaranteeDue = computed(() => Number(r.value.guarantee_amount ?? 0));

function openCheckIn() {
    guaranteeMethod.value = firstCounterMethod.value;
    guaranteeReference.value = '';
    checkInOpen.value = true;
}

async function submitCheckIn() {
    if (checkInBusy.value) return;
    checkInBusy.value = true;
    try {
        await axios.patch(`/api/reservations/${r.value.id}/check-in`, {
            // Llegada anticipada: el servidor la rechaza sin esta bandera.
            ...(r.value.starts_today ? {} : { early: 1 }),
            ...(guaranteeDue.value > 0
                ? {
                      guarantee_method: guaranteeMethod.value,
                      ...(guaranteeMethod.value === 'transfer'
                          ? {
                                guarantee_reference:
                                    guaranteeReference.value.trim(),
                            }
                          : {}),
                  }
                : {}),
        });
        toast.success(
            'Llegada registrada',
            `${r.value.guest_name ?? 'Huésped'} entró a la hab. ${r.value.room ?? '—'}.`,
        );
        checkInOpen.value = false;
        router.reload();
    } catch (error: any) {
        toast.error(
            'No se pudo registrar la llegada',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        checkInBusy.value = false;
    }
}

// ── No llegó / Cancelar ──
const cancelKind = ref<'cancel' | 'no_show' | null>(null);
const cancelReason = ref('');
const cancelBusy = ref(false);

function askCancel(kind: 'cancel' | 'no_show') {
    cancelReason.value = '';
    cancelKind.value = kind;
}

async function submitCancel() {
    if (!cancelKind.value || cancelBusy.value) return;
    const noShow = cancelKind.value === 'no_show';
    cancelBusy.value = true;
    try {
        await axios.patch(`/api/reservations/${r.value.id}/cancel`, {
            no_show: noShow,
            reason: cancelReason.value.trim() || null,
        });
        toast.success(
            noShow
                ? 'Se registró que el huésped no llegó'
                : 'Reserva cancelada',
            `${r.value.code} pasó al historial y la habitación quedó libre.`,
        );
        cancelKind.value = null;
        router.reload();
    } catch (error: any) {
        toast.error(
            'No se pudo completar la acción',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        cancelBusy.value = false;
    }
}

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
</script>

<template>
    <RazeLayout :title="`Reserva ${reservation.code}`">
        <div class="mt-2">
            <!-- Encabezado de ficha -->
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-4 p-5 xl:flex-row xl:items-start xl:justify-between"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="toneCircle[status.tone]"
                        >
                            <Lucide :icon="status.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div
                                class="flex flex-wrap items-center gap-x-2 gap-y-1"
                            >
                                <h1 class="text-base font-medium">
                                    {{ reservation.guest_name ?? 'Anónimo' }}
                                </h1>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="toneBadge[status.tone]"
                                    >{{ reservation.status_label }}</span
                                >
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="paymentTone"
                                    >{{
                                        reservation.payment_status_label
                                    }}</span
                                >
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        pending > 0
                                            ? toneBadge.danger
                                            : toneBadge.success
                                    "
                                    >{{
                                        pending > 0
                                            ? `Saldo ${money(pending)}`
                                            : 'Sin saldo'
                                    }}</span
                                >
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Reserva {{ reservation.code }} · Hab.
                                {{ reservation.room ?? 'por asignar' }} ·
                                {{ reservation.starts_at }} →
                                {{ reservation.ends_at }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <a
                                    v-if="reservation.guest_phone"
                                    :href="`tel:${reservation.guest_phone}`"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide icon="Phone" class="h-3 w-3" />
                                    {{ reservation.guest_phone }}
                                </a>
                                <a
                                    v-if="reservation.guest_email"
                                    :href="`mailto:${reservation.guest_email}`"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    <Lucide icon="Mail" class="h-3 w-3" />
                                    {{ reservation.guest_email }}
                                </a>
                            </div>
                        </div>
                    </div>

                    <div
                        class="grid w-full auto-rows-fr grid-cols-2 items-stretch gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:justify-end [&>*]:h-auto [&>*]:min-h-9 [&>*]:py-1.5 [&>*]:text-center [&>*]:leading-tight md:[&>*]:h-9 md:[&>*]:py-0"
                    >
                        <Link
                            href="/reservas"
                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                            Volver a reservas
                        </Link>
                        <Button
                            v-if="conversationId"
                            as="a"
                            :href="`/bandeja?conversation=${conversationId}`"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                        >
                            <Lucide
                                icon="MessagesSquare"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Conversación
                        </Button>
                        <Link
                            v-if="canManage && actionable"
                            :href="`/reservas/operacion?edit=${reservation.id}`"
                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-[0.5rem] border border-primary/40 bg-white px-3.5 text-xs font-medium text-primary transition hover:bg-primary/5 dark:bg-darkmode-600"
                        >
                            <Lucide icon="Pencil" class="h-3.5 w-3.5" />
                            Editar
                        </Link>
                        <Button
                            v-if="canManage && reservation.status === 'pending'"
                            variant="outline-primary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            :disabled="confirming"
                            @click="confirmReservation"
                        >
                            <Lucide
                                icon="CircleCheck"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            {{ confirming ? 'Confirmando…' : 'Confirmar' }}
                        </Button>
                        <Button
                            v-if="canManage && live && pending > 0"
                            variant="outline-primary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            @click="openPayment"
                        >
                            <Lucide
                                icon="Banknote"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Cobrar o registrar pago
                        </Button>
                        <!-- Sin saldo no hay nada que cobrar, pero los pagos
                             hechos (y su reembolso) deben seguir alcanzables:
                             antes solo se llegaba a ellos si algo se debía. -->
                        <Button
                            v-else-if="
                                canManage && reservation.payments?.length
                            "
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            @click="openPayment"
                        >
                            <Lucide
                                icon="Banknote"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ver pagos
                        </Button>
                        <Button
                            v-if="
                                canManage && actionable && manualCheckinAllowed
                            "
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs"
                            @click="openCheckIn"
                        >
                            <Lucide icon="LogIn" class="mr-1.5 h-3.5 w-3.5" />
                            Registrar llegada
                        </Button>
                        <Button
                            v-if="canManage && reopenable"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs"
                            @click="reopenTarget = reservation"
                        >
                            <Lucide
                                icon="RotateCcw"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Reabrir o reagendar
                        </Button>
                        <Button
                            v-if="canManage && actionable"
                            variant="outline-warning"
                            class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            @click="askCancel('no_show')"
                        >
                            <Lucide icon="UserX" class="mr-1.5 h-3.5 w-3.5" />
                            No llegó
                        </Button>
                        <Button
                            v-if="canManage && actionable"
                            variant="outline-danger"
                            class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            @click="askCancel('cancel')"
                        >
                            <Lucide icon="Ban" class="mr-1.5 h-3.5 w-3.5" />
                            Cancelar
                        </Button>
                    </div>
                </div>

                <!-- Avisos que cambian cómo se atiende -->
                <div
                    v-if="proofReceivedAt && live && pending > 0"
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-warning/5 px-5 py-3 text-xs text-slate-700 dark:border-darkmode-400 dark:text-slate-200"
                >
                    <Lucide
                        icon="Receipt"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                    />
                    <span>
                        El huésped mandó un archivo por la conversación el
                        {{ proofReceivedAt }}. Si es su comprobante y ya lo
                        viste en el banco, regístralo con "Cobrar o registrar
                        pago" como transferencia ya verificada, o apruébalo en
                        <a
                            href="/pagos"
                            class="font-medium text-primary underline"
                            >Pagos</a
                        >, donde está la foto.
                    </span>
                </div>
                <div
                    v-if="reservation.cancellation_reason"
                    class="flex items-start gap-2 border-t border-slate-200/60 bg-danger/5 px-5 py-3 text-xs text-danger dark:border-darkmode-400"
                >
                    <Lucide icon="Ban" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    Se canceló por: {{ reservation.cancellation_reason }}
                </div>
                <div
                    v-if="
                        reservation.hold_expires_at &&
                        reservation.status === 'pending'
                    "
                    class="flex items-start gap-2 border-t border-slate-200/60 px-5 py-3 text-xs text-slate-600 dark:border-darkmode-400 dark:text-slate-300"
                >
                    <Lucide
                        icon="Hourglass"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                    />
                    Apartada hasta el {{ reservation.hold_expires_at }}; si
                    nadie la confirma o paga, se libera sola.
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <div class="col-span-12 space-y-5 xl:col-span-7">
                    <!-- Detalle de la reservación -->
                    <section class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="CalendarRange" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Detalle de la reservación
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Qué se reservó, para cuándo y para quién.
                                </p>
                            </div>
                        </div>
                        <dl
                            class="grid grid-cols-2 gap-2 px-4 py-3 sm:grid-cols-3"
                        >
                            <div
                                v-for="item in details"
                                :key="item.label"
                                class="rounded-lg border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <dt
                                    class="flex items-center gap-1.5 text-[11px] text-slate-500"
                                >
                                    <Lucide :icon="item.icon" class="h-3 w-3" />
                                    {{ item.label }}
                                </dt>
                                <dd class="mt-0.5 truncate text-xs font-medium">
                                    {{ item.value }}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Dinero -->
                    <section class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Wallet" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-medium">Pagos</h2>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Lo que cuesta, lo que ya entró y lo que
                                    falta.
                                </p>
                            </div>
                            <Button
                                v-if="canManage && live && pending > 0"
                                variant="outline-secondary"
                                class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                @click="openPayment"
                            >
                                <Lucide
                                    icon="Plus"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Registrar pago
                            </Button>
                            <template v-else-if="pending <= 0">
                                <span
                                    class="ml-auto inline-flex shrink-0 items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >
                                    <Lucide
                                        icon="CircleCheck"
                                        class="h-3 w-3"
                                    />
                                    Pagada completa
                                </span>
                                <Button
                                    v-if="
                                        canManage &&
                                        reservation.payments?.length
                                    "
                                    variant="outline-secondary"
                                    class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                    @click="openPayment"
                                >
                                    <Lucide
                                        icon="Banknote"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Ver o reembolsar
                                </Button>
                            </template>
                        </div>

                        <div
                            class="grid grid-cols-2 gap-2 px-4 py-3 sm:grid-cols-4"
                        >
                            <div
                                class="rounded-lg border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <div class="text-[11px] text-slate-500">
                                    Total
                                </div>
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ money(reservation.total_amount) }}
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                            >
                                <div class="text-[11px] text-slate-500">
                                    Anticipo
                                </div>
                                <div class="mt-0.5 text-sm font-medium">
                                    {{ money(reservation.deposit_amount) }}
                                </div>
                            </div>
                            <div
                                class="rounded-lg border border-success/20 bg-success/5 px-3 py-2.5"
                            >
                                <div class="text-[11px] text-slate-500">
                                    Pagado
                                </div>
                                <div
                                    class="mt-0.5 text-sm font-medium text-success"
                                >
                                    {{ money(reservation.paid_total) }}
                                </div>
                                <!-- "Pagó la mitad" se ve de un vistazo. -->
                                <div class="text-[11px] text-slate-500">
                                    {{ paidPercent }}% del total
                                </div>
                            </div>
                            <div
                                class="rounded-lg px-3 py-2.5"
                                :class="
                                    pending > 0
                                        ? 'border border-danger/20 bg-danger/5'
                                        : 'border border-slate-200/70 dark:border-darkmode-400'
                                "
                            >
                                <div class="text-[11px] text-slate-500">
                                    Saldo
                                </div>
                                <div
                                    class="mt-0.5 text-sm font-medium"
                                    :class="pending > 0 ? 'text-danger' : ''"
                                >
                                    {{ money(pending) }}
                                </div>
                            </div>
                        </div>

                        <!-- Cupón (módulo cupones): lo que se le descontó, o
                             el campo para dárselo si nadie lo escribió al
                             reservar -->
                        <div
                            v-if="discount > 0 || canCoupon"
                            class="flex flex-wrap items-center gap-x-2 gap-y-2 border-t border-slate-200/60 px-4 py-3 text-xs dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="TicketPercent"
                                class="h-3.5 w-3.5 shrink-0 text-primary"
                            />
                            <template v-if="discount > 0">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-[11px] font-medium text-success"
                                >
                                    Cupón {{ reservation.coupon_code }}
                                    <span>−{{ money(discount) }}</span>
                                </span>
                                <span class="text-slate-500">
                                    Precio de lista {{ money(listPrice) }}
                                </span>
                                <button
                                    v-if="canCoupon"
                                    type="button"
                                    class="ml-auto font-medium text-slate-500 hover:text-danger disabled:opacity-50"
                                    :disabled="couponBusy"
                                    @click="removeCoupon"
                                >
                                    Quitar cupón
                                </button>
                            </template>
                            <template v-else-if="couponOpen">
                                <FormInput
                                    v-model="couponInput"
                                    type="text"
                                    placeholder="CODIGO"
                                    class="h-9 w-40 text-xs uppercase"
                                    :disabled="couponBusy"
                                    @keyup.enter="applyCoupon"
                                />
                                <Button
                                    variant="primary"
                                    class="h-9 rounded-[0.5rem] text-xs"
                                    :disabled="
                                        couponBusy || !couponInput.trim()
                                    "
                                    @click="applyCoupon"
                                >
                                    {{ couponBusy ? 'Aplicando…' : 'Aplicar' }}
                                </Button>
                                <button
                                    type="button"
                                    class="font-medium text-slate-500 hover:text-primary"
                                    @click="couponOpen = false"
                                >
                                    Cancelar
                                </button>
                            </template>
                            <template v-else>
                                <span class="text-slate-500">
                                    Sin cupón. Si le prometieron un descuento,
                                    aplícalo aquí: el total y el saldo se
                                    recalculan solos.
                                </span>
                                <Button
                                    variant="outline-secondary"
                                    class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                    @click="openCoupon"
                                >
                                    <Lucide
                                        icon="TicketPercent"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Aplicar cupón
                                </Button>
                            </template>
                        </div>

                        <!-- Cobro en curso (link o transferencia por verificar) -->
                        <div
                            v-if="reservation.payment_request"
                            class="mx-4 mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-3.5 py-2.5 text-xs"
                        >
                            <Lucide
                                icon="Send"
                                class="h-3.5 w-3.5 text-primary"
                            />
                            <span class="font-medium">
                                {{ reservation.payment_request.concept }}
                                {{ reservation.payment_request.amount_label }}
                            </span>
                            <span class="text-slate-500">
                                {{ reservation.payment_request.status_label }}
                                <template
                                    v-if="
                                        reservation.payment_request
                                            .expires_label
                                    "
                                >
                                    · vence
                                    {{
                                        reservation.payment_request
                                            .expires_label
                                    }}
                                </template>
                            </span>
                            <a
                                v-if="
                                    reservation.payment_request.method ===
                                    'transfer'
                                "
                                href="/pagos"
                                class="ml-auto font-medium text-primary hover:underline"
                                >Aprobar en Pagos</a
                            >
                        </div>

                        <div
                            v-if="reservation.payments?.length"
                            class="divide-y divide-slate-200/60 border-t border-slate-200/60 dark:divide-darkmode-400 dark:border-darkmode-400"
                        >
                            <div
                                v-for="payment in reservation.payments"
                                :key="payment.id"
                                class="flex items-center gap-3 px-4 py-2.5 text-xs"
                            >
                                <Lucide
                                    icon="CircleCheck"
                                    class="h-3.5 w-3.5 shrink-0 text-success"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium">
                                        {{
                                            methodLabels[payment.method] ??
                                            payment.method
                                        }}
                                        <span
                                            v-if="payment.reference"
                                            class="font-normal text-slate-500"
                                            >· {{ payment.reference }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-0.5 text-[11px] text-slate-500"
                                    >
                                        {{ payment.paid_at }}
                                        <template v-if="payment.received_by">
                                            · {{ payment.received_by }}
                                        </template>
                                        <template
                                            v-if="Number(payment.refunded) > 0"
                                        >
                                            · reembolsado
                                            {{ money(payment.refunded) }}
                                        </template>
                                    </div>
                                </div>
                                <span class="shrink-0 font-medium">{{
                                    money(payment.amount)
                                }}</span>
                            </div>
                        </div>
                        <p
                            v-else
                            class="border-t border-slate-200/60 px-4 py-3 text-xs text-slate-500 dark:border-darkmode-400"
                        >
                            Todavía no hay pagos registrados.
                        </p>
                    </section>

                    <!-- Cargos -->
                    <section
                        v-if="hasCharges"
                        class="box box--stacked overflow-hidden"
                    >
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-primary/10 bg-primary/10 text-primary"
                            >
                                <Lucide icon="ListPlus" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">
                                    Cargos incluidos
                                </h2>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Lo que suma al total además de la tarifa.
                                </p>
                            </div>
                        </div>
                        <div
                            class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="(line, i) in reservation.extra_charges"
                                :key="`c${i}`"
                                class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs"
                            >
                                <span>{{ line.concept }}</span>
                                <span class="font-medium">{{
                                    money(line.amount)
                                }}</span>
                            </div>
                            <div
                                v-for="(line, i) in [
                                    ...reservation.products,
                                    ...reservation.extras,
                                ]"
                                :key="`p${i}`"
                                class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs"
                            >
                                <span>{{ line.qty }}× {{ line.name }}</span>
                                <span class="font-medium">{{
                                    money(line.total)
                                }}</span>
                            </div>
                            <div
                                v-for="(line, i) in reservation.experiences"
                                :key="`e${i}`"
                                class="flex items-center justify-between gap-3 px-4 py-2.5 text-xs"
                            >
                                <span
                                    >{{ line.name }} · {{ line.starts_at }} ·
                                    {{ line.people }} pers.</span
                                >
                                <span class="font-medium">{{
                                    money(line.total)
                                }}</span>
                            </div>
                        </div>
                    </section>

                    <!-- Notas -->
                    <section
                        v-if="reservation.guest_notes || reservation.notes"
                        class="box box--stacked overflow-hidden"
                    >
                        <div :class="cardHeader">
                            <div
                                :class="sectionIcon"
                                class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                            >
                                <Lucide icon="StickyNote" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-sm font-medium">Notas</h2>
                            </div>
                        </div>
                        <div class="space-y-3 px-4 py-3 text-xs">
                            <div v-if="reservation.guest_notes">
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Peticiones del huésped
                                </div>
                                <p
                                    class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-300"
                                >
                                    {{ reservation.guest_notes }}
                                </p>
                            </div>
                            <div v-if="reservation.notes">
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Notas internas
                                </div>
                                <p
                                    class="mt-1 whitespace-pre-line text-slate-600 dark:text-slate-300"
                                >
                                    {{ reservation.notes }}
                                </p>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Historia -->
                <section
                    class="box box--stacked col-span-12 overflow-hidden xl:col-span-5"
                >
                    <div :class="cardHeader">
                        <div
                            :class="sectionIcon"
                            class="border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400"
                        >
                            <Lucide icon="History" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">Historia</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Todo lo que le ha pasado a esta reserva.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="reservation.timeline.length"
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="event in reservation.timeline"
                            :key="event.id"
                            class="px-4 py-2.5 text-xs"
                        >
                            <div class="font-medium">{{ event.message }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-500">
                                {{ event.by ?? 'Sistema' }} ·
                                {{ event.at ?? '—' }}
                            </div>
                        </div>
                    </div>
                    <p v-else class="px-4 py-3 text-xs text-slate-500">
                        Sin actividad registrada todavía.
                    </p>
                </section>
            </div>
        </div>

        <PaymentModal
            ref="paymentModal"
            :gateway-available="gatewayAvailable"
            :charge-options="chargeOptions"
            :chat-receipts="chatReceipts ?? []"
            @saved="reload"
        />

        <!-- Registrar llegada -->
        <Dialog size="lg" :open="checkInOpen" @close="checkInOpen = false">
            <Dialog.Panel class="sm:w-[94vw] lg:w-[560px]">
                <form
                    class="flex max-h-[calc(100dvh-6rem)] flex-col"
                    @submit.prevent="submitCheckIn"
                >
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="LogIn" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Registrar llegada
                            </h2>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ reservation.code }} ·
                                {{ reservation.guest_name ?? 'Anónimo' }} · Hab.
                                {{ reservation.room ?? '—' }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 text-xs"
                    >
                        <p
                            v-if="!reservation.starts_today"
                            class="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 px-3 py-2.5 text-slate-700 dark:text-slate-200"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-warning"
                            />
                            Esta reserva llega el {{ reservation.starts_at }}.
                            Registrar la llegada hoy la adelanta; la salida y el
                            cargo no cambian.
                        </p>
                        <p
                            v-if="pending > 0"
                            class="text-slate-600 dark:text-slate-300"
                        >
                            Tiene un saldo de
                            <span class="font-medium text-danger">{{
                                money(pending)
                            }}</span
                            >; se puede cobrar después desde esta ficha o al
                            registrar la salida.
                        </p>
                        <section v-if="guaranteeDue > 0">
                            <div
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Fianza: {{ money(guaranteeDue) }}
                            </div>
                            <p class="mt-1 text-slate-500">
                                Se cobra ahora y se devuelve al registrar la
                                salida. No cuenta como pago del hospedaje.
                            </p>
                            <div
                                class="mt-2 grid gap-2"
                                :class="
                                    counterMethods.length > 2
                                        ? 'grid-cols-3'
                                        : 'grid-cols-2'
                                "
                            >
                                <button
                                    v-for="m in counterMethods"
                                    :key="m.key"
                                    type="button"
                                    class="flex h-9 items-center justify-center gap-1.5 rounded-lg border text-xs font-medium transition"
                                    :class="
                                        guaranteeMethod === m.key
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'
                                    "
                                    @click="guaranteeMethod = m.key"
                                >
                                    <Lucide
                                        :icon="m.icon"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ m.short }}
                                </button>
                            </div>
                            <FormInput
                                v-if="guaranteeMethod === 'transfer'"
                                v-model="guaranteeReference"
                                type="text"
                                maxlength="100"
                                required
                                class="mt-2 h-9 text-xs"
                                placeholder="Folio de la transferencia de la fianza"
                            />
                        </section>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="checkInOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="checkInBusy"
                        >
                            {{
                                checkInBusy
                                    ? 'Registrando…'
                                    : 'Sí, registrar llegada'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- No llegó / Cancelar -->
        <Dialog
            size="lg"
            :open="cancelKind !== null"
            @close="cancelKind = null"
        >
            <Dialog.Panel class="sm:w-[94vw] lg:w-[520px]">
                <form
                    v-if="cancelKind"
                    class="flex flex-col"
                    @submit.prevent="submitCancel"
                >
                    <div class="flex items-start gap-3 px-5 pt-5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                            :class="
                                cancelKind === 'no_show'
                                    ? 'border-warning/10 bg-warning/10 text-warning'
                                    : 'border-danger/10 bg-danger/10 text-danger'
                            "
                        >
                            <Lucide
                                :icon="
                                    cancelKind === 'no_show' ? 'UserX' : 'Ban'
                                "
                                class="h-4 w-4"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    cancelKind === 'no_show'
                                        ? 'El huésped no llegó'
                                        : 'Cancelar reserva'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ reservation.code }} pasa al historial y la
                                habitación queda libre. Se puede reabrir después
                                desde esta misma ficha.
                            </p>
                        </div>
                    </div>
                    <div class="px-5 py-4">
                        <FormInput
                            v-model="cancelReason"
                            type="text"
                            maxlength="255"
                            class="h-9 text-xs"
                            placeholder="Motivo (queda en el historial)"
                        />
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="cancelKind = null"
                            >Volver</Button
                        >
                        <Button
                            type="submit"
                            :variant="
                                cancelKind === 'no_show' ? 'warning' : 'danger'
                            "
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="cancelBusy"
                        >
                            {{
                                cancelBusy
                                    ? 'Guardando…'
                                    : cancelKind === 'no_show'
                                      ? 'Confirmar que no llegó'
                                      : 'Cancelar reserva'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <ReopenDialog
            :reservation="reopenTarget"
            :hold-minutes="holdMinutes"
            @close="reopenTarget = null"
            @done="
                reopenTarget = null;
                reload();
            "
        />
    </RazeLayout>
</template>
