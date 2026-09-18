<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { FormDateTime } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import ReservationsNav from './ReservationsNav.vue';

/**
 * Cuentas por cerrar.
 *
 * La salida manual exige cobrar el saldo o forzarla a propósito; la del
 * reloj (stays:auto-checkout) no tiene a quién preguntarle y cerraba en
 * silencio. Después ya no había nada que hacer: los cargos se rechazaban
 * por "estancia no activa" y no existía dónde registrar un cobro tarde, así
 * que el dinero desaparecía del panel.
 *
 * Esta bandeja es ese "después". No inventa cobros: cerrar con motivo deja
 * de mostrar la cuenta, pero el dinero nunca entra al corte.
 */
interface SettlementRow {
    id: number;
    room: string | null;
    guest_name: string;
    guest_phone: string | null;
    reservation_id: number | null;
    reservation_code: string | null;
    check_in_at: string;
    check_out_at: string | null;
    check_out_at_input: string | null;
    planned_end_at: string;
    amount: number;
    pending: number;
    auto_closed: boolean;
    settlement_closed_at: string | null;
    settlement_note: string | null;
    extra_charges: { concept: string; amount: number }[];
}

/**
 * Las cuentas sin estancia: la reserva terminó (casi siempre porque el
 * cierre de día la completó) y le quedó dinero sin registrar. No tienen
 * folio ni hora real de salida — el cobro vive en la ficha de la reserva —,
 * así que solo se resuelven cobrando allá o cerrándolas con motivo.
 */
interface ReservationRow {
    id: number;
    code: string;
    room: string | null;
    guest_name: string;
    guest_phone: string | null;
    starts_at: string;
    ends_at: string;
    amount: number;
    paid: number;
    pending: number;
    // Ni un abono: en un hotel que cobra al llegar casi siempre es alguien
    // que no llegó, no una deuda.
    unpaid: boolean;
    auto_closed: boolean;
    settlement_closed_at: string | null;
    settlement_note: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    stays: {
        data: SettlementRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    reservations: {
        data: ReservationRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { q: string; cerradas: boolean };
    pendingCount: number;
    reservationsPendingCount: number;
    summary: {
        count: number;
        total: number;
        total_label: string;
        stays_total_label: string;
        reservations_total_label: string;
        average_label: string;
        biggest_label: string;
        oldest_label: string | null;
        oldest_days: number;
    };
    canManage: boolean;
}>();

const toast = useToasts();
const { methods, first, labelFor } = useCounterMethods();

const q = ref(props.filters.q);
const showClosed = ref(props.filters.cerradas);

// Las dos listas venían apiladas en la misma caja: con 86 cuentas había que
// bajar media pantalla para descubrir que abajo había otra tabla distinta.
// En pestañas cada trabajo se ve entero y conserva su propia paginación.
const tab = ref<'stays' | 'reservations'>(
    props.stays.total === 0 && props.reservations.total > 0
        ? 'reservations'
        : 'stays',
);

/**
 * El vacío tiene que decir de QUÉ pestaña habla: con las dos listas juntas
 * bastaba un "no hay cuentas pendientes", pero ahora una pestaña vacía con la
 * otra llena se leería como que ya no se debe nada.
 */
const emptyMessage = computed(() => {
    if (props.filters.q) return 'Nada coincide con la búsqueda.';
    if (showClosed.value) return 'Ninguna cuenta se ha cerrado con motivo.';

    const otra =
        tab.value === 'stays' ? props.reservations.total : props.stays.total;

    if (otra > 0) {
        return tab.value === 'stays'
            ? 'Ninguna estancia registrada quedó con saldo; lo pendiente está en la otra pestaña.'
            : 'Todas las cuentas con saldo tienen estancia registrada: están en la otra pestaña.';
    }

    return 'No hay cuentas pendientes: todo lo que terminó quedó cobrado.';
});

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const tableHead = sectionLabel;
const rowAction =
    'flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition';

interface Tile {
    key: string;
    icon: Icon;
    tone: string;
    value: string;
    label: string;
    detail: string;
}

const tiles = computed<Tile[]>(() => [
    {
        key: 'total',
        icon: 'Wallet' as Icon,
        tone: 'border-pending/10 bg-pending/10 text-pending',
        value: props.summary.total_label,
        label: 'Sin cobrar en total',
        detail: `${props.summary.count} ${props.summary.count === 1 ? 'cuenta abierta' : 'cuentas abiertas'}`,
    },
    {
        key: 'split',
        icon: 'DoorOpen' as Icon,
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: props.summary.stays_total_label,
        label: 'Con estancia registrada',
        detail: `Sin estancia ${props.summary.reservations_total_label}`,
    },
    {
        key: 'oldest',
        icon: 'Clock' as Icon,
        tone: 'border-warning/10 bg-warning/10 text-warning',
        value: props.summary.oldest_days
            ? `${props.summary.oldest_days} días`
            : '—',
        label: 'La más vieja',
        detail: props.summary.oldest_label
            ? `Salió el ${props.summary.oldest_label}`
            : 'Nada pendiente',
    },
    {
        key: 'average',
        icon: 'ChartColumn' as Icon,
        tone: 'border-info/10 bg-info/10 text-info',
        value: props.summary.average_label,
        label: 'Promedio por cuenta',
        detail: `La mayor: ${props.summary.biggest_label}`,
    },
]);

function reload() {
    router.get(
        route('tenant.reservations.settlements'),
        {
            q: q.value || undefined,
            cerradas: showClosed.value ? 1 : undefined,
        },
        {
            preserveState: true,
            replace: true,
            only: ['stays', 'reservations', 'filters'],
        },
    );
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(reload, 350);
});
watch(showClosed, reload);

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n ?? 0);

// Lo que falta cobrar en la página que se está viendo. El total de la
// bandeja completa lo manda el servidor (pendingCount): sumar solo lo
// visible daría una cifra que cambia al pasar de página.
//
// Suma SOLO la pestaña abierta: sumando las dos, el renglón decía
// "en esta página $27,305" con dos filas de $950 a la vista.
const pageTotal = computed(() =>
    (tab.value === 'stays' ? props.stays.data : props.reservations.data).reduce(
        (sum, row) => sum + (row.pending ?? 0),
        0,
    ),
);

// El trabajo que queda son las dos listas juntas: el hotel no distingue
// entre "tenía estancia" y "no la tuvo", solo sabe que hay dinero suelto.
const totalPendiente = computed(
    () => props.pendingCount + props.reservationsPendingCount,
);

/* --- Acciones sobre una cuenta ------------------------------------- */

type ActionKind = 'pay' | 'charge' | 'checkout' | 'close' | 'close-reservation';

const action = ref<{
    kind: ActionKind;
    stay?: SettlementRow;
    reservation?: ReservationRow;
} | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);

const form = reactive({
    method: 'cash',
    reference: '',
    concept: '',
    amount: '' as string | number,
    check_out_at: '',
    note: '',
});

const meta: Record<
    ActionKind,
    { title: string; icon: string; cta: string; hint: string }
> = {
    pay: {
        title: 'Registrar el cobro',
        icon: 'Banknote',
        cta: 'Registrar cobro',
        hint: 'Entra al corte de tu turno de hoy, no al del día de la estancia: el dinero se recibe ahora.',
    },
    charge: {
        title: 'Agregar lo que faltó',
        icon: 'ReceiptText',
        cta: 'Agregar al saldo',
        hint: 'Consumos, daños o cargos que no se capturaron a tiempo. Suben el saldo de esta cuenta.',
    },
    checkout: {
        title: 'Corregir la hora de salida',
        icon: 'Clock',
        cta: 'Guardar la hora',
        hint: 'El cierre automático la pone a los 15 minutos de la salida prevista. Si el huésped se fue a otra hora, aquí se endereza.',
    },
    close: {
        title: 'Cerrar sin cobrar',
        icon: 'Archive',
        cta: 'Cerrar la cuenta',
        hint: 'Cortesía, incobrable o error de captura. El motivo queda escrito: el dinero NO entra al corte.',
    },
    'close-reservation': {
        title: 'Cerrar sin cobrar',
        icon: 'Archive',
        cta: 'Cerrar la cuenta',
        hint: 'No llegó, cortesía, incobrable o error de captura. El motivo queda escrito: el dinero NO entra al corte. Si sí se va a cobrar, hazlo desde la ficha de la reserva.',
    },
};

// El modal es el mismo para los dos orígenes: lo que necesita saber es de
// quién es la cuenta y cuánto falta, no si hubo estancia.
const target = computed(() => {
    const current = action.value;
    if (!current) return null;

    return current.reservation
        ? {
              room: current.reservation.room,
              guest_name: current.reservation.guest_name,
              pending: current.reservation.pending,
          }
        : {
              room: current.stay!.room,
              guest_name: current.stay!.guest_name,
              pending: current.stay!.pending,
          };
});

function open(kind: ActionKind, stay: SettlementRow) {
    error.value = null;
    form.method = first.value;
    form.reference = '';
    form.concept = '';
    form.amount = '';
    form.check_out_at = stay.check_out_at_input ?? '';
    form.note = '';
    action.value = { kind, stay };
}

function openReservationClose(reservation: ReservationRow) {
    error.value = null;
    form.note = '';
    action.value = { kind: 'close-reservation', reservation };
}

const blocked = computed(() => {
    const current = action.value;
    if (!current) return true;
    if (current.kind === 'charge') {
        return form.concept.trim() === '' || Number(form.amount || 0) <= 0;
    }
    if (current.kind === 'close' || current.kind === 'close-reservation') {
        return form.note.trim().length < 4;
    }
    if (current.kind === 'checkout') return form.check_out_at === '';

    return false;
});

async function submit() {
    const current = action.value;
    if (!current || blocked.value) return;

    busy.value = true;
    error.value = null;

    try {
        // Cuenta sin estancia: solo se cierra con motivo desde aquí. El
        // cobro vive en la ficha de la reserva y no se duplica.
        if (current.kind === 'close-reservation') {
            await axios.patch(
                `/api/reservations/${current.reservation!.id}/settlement/close`,
                { note: form.note.trim() },
            );
            toast.success(
                'Cuenta cerrada',
                'Sale de la bandeja con su motivo anotado; el dinero no entró al corte.',
            );
            action.value = null;
            router.reload({
                only: ['reservations', 'reservationsPendingCount'],
            });

            return;
        }

        const base = `/api/stays/${current.stay!.id}/settlement`;

        if (current.kind === 'pay') {
            const { data } = await axios.post(`${base}/payment`, {
                method: form.method,
                reference: form.reference.trim() || undefined,
            });
            toast.success(
                'Cobro registrado',
                `${current.stay!.guest_name} · ${labelFor(form.method)}${
                    data.pending > 0
                        ? `. Todavía debe ${money(data.pending)}.`
                        : '. Cuenta liquidada.'
                }`,
            );
        } else if (current.kind === 'charge') {
            await axios.post(`${base}/charge`, {
                concept: form.concept.trim(),
                amount: Number(form.amount),
            });
            toast.success(
                'Cargo agregado',
                `${form.concept.trim()} · ${money(Number(form.amount))}`,
            );
        } else if (current.kind === 'checkout') {
            await axios.patch(`${base}/checked-out-at`, {
                check_out_at: form.check_out_at,
            });
            toast.success(
                'Hora corregida',
                'La salida quedó con su hora real.',
            );
        } else {
            await axios.patch(`${base}/close`, { note: form.note.trim() });
            toast.success(
                'Cuenta cerrada',
                'Sale de la bandeja con su motivo anotado; el dinero no entró al corte.',
            );
        }

        action.value = null;
        router.reload({ only: ['stays', 'pendingCount'] });
    } catch (e: any) {
        const data = e.response?.data;
        const firstError = data?.errors
            ? (Object.values(data.errors as Record<string, string[]>)[0] ??
                  [])[0]
            : null;
        error.value =
            data?.message ?? firstError ?? 'No se pudo completar la acción.';
    } finally {
        busy.value = false;
    }
}

async function reopenReservation(reservation: ReservationRow) {
    try {
        await axios.patch(
            `/api/reservations/${reservation.id}/settlement/reopen`,
        );
        toast.success('Cuenta reabierta', 'Vuelve a la bandeja para cobrarla.');
        router.reload({ only: ['reservations', 'reservationsPendingCount'] });
    } catch {
        toast.error('No se pudo reabrir', 'Intenta de nuevo.');
    }
}

async function reopen(stay: SettlementRow) {
    try {
        await axios.patch(`/api/stays/${stay.id}/settlement/reopen`);
        toast.success('Cuenta reabierta', 'Vuelve a la bandeja para cobrarla.');
        router.reload({ only: ['stays', 'pendingCount'] });
    } catch {
        toast.error('No se pudo reabrir', 'Intenta de nuevo.');
    }
}
</script>

<template>
    <RazeLayout title="Cuentas por cerrar">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="ReceiptText" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Cuentas por cerrar
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Estancias y reservas que ya terminaron y a las que
                            les quedó dinero sin registrar.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Volver a reservas
                    </Button>
                </div>
            </div>

            <ReservationsNav current="settlements" />

            <!-- Cuánto dinero hay ahí afuera, antes de la lista larga -->
            <div v-if="!showClosed" class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">El saldo que queda</span>
            </div>
            <div
                v-if="!showClosed"
                class="mt-2 grid auto-rows-fr grid-cols-12 gap-4"
            >
                <div
                    v-for="tile in tiles"
                    :key="tile.key"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div :class="[sectionIcon, tile.tone]">
                        <Lucide :icon="tile.icon" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ tile.value }}</div>
                        <div class="truncate text-xs text-slate-500">
                            {{ tile.label }}
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            {{ tile.detail }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-4">
                <!-- Cabecera: las dos listas son el mismo trabajo visto de
                     dos maneras, así que comparten caja y pestañas. -->
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="inline-flex gap-1 rounded-[0.7rem] border border-slate-200/80 bg-slate-100/70 p-1 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <button
                            type="button"
                            class="flex h-8 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium transition"
                            :class="
                                tab === 'stays'
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="tab = 'stays'"
                        >
                            <Lucide icon="DoorOpen" class="h-3.5 w-3.5" />
                            Con estancia
                            <span
                                class="rounded-full px-1.5 text-[11px] font-normal"
                                :class="
                                    tab === 'stays'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-slate-200/70 text-slate-500 dark:bg-darkmode-400'
                                "
                                >{{ stays.total }}</span
                            >
                        </button>
                        <button
                            type="button"
                            class="flex h-8 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium transition"
                            :class="
                                tab === 'reservations'
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="tab = 'reservations'"
                        >
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                            Sin registro de llegada
                            <span
                                class="rounded-full px-1.5 text-[11px] font-normal"
                                :class="
                                    tab === 'reservations'
                                        ? 'bg-primary/10 text-primary'
                                        : 'bg-slate-200/70 text-slate-500 dark:bg-darkmode-400'
                                "
                                >{{ reservations.total }}</span
                            >
                        </button>
                    </div>
                    <span
                        v-if="!showClosed && pageTotal > 0"
                        class="ml-auto hidden text-[11px] text-slate-400 lg:block"
                    >
                        En esta página: {{ money(pageTotal) }} sin cobrar
                    </span>
                </div>

                <!-- Buscador y estado, en franja gris pegada a la lista -->
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative w-full min-w-0 sm:w-80">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="search"
                            placeholder="Huésped o habitación"
                            class="h-9 pl-9 text-xs"
                        />
                    </div>
                    <div
                        class="inline-flex gap-1 rounded-[0.5rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                    >
                        <button
                            type="button"
                            class="h-7 rounded-[0.4rem] px-2.5 text-xs font-medium transition"
                            :class="
                                !showClosed
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="showClosed = false"
                        >
                            Con saldo ({{ totalPendiente }})
                        </button>
                        <button
                            type="button"
                            class="h-7 rounded-[0.4rem] px-2.5 text-xs font-medium transition"
                            :class="
                                showClosed
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="showClosed = true"
                        >
                            Cerradas con motivo
                        </button>
                    </div>
                    <span
                        class="ml-auto hidden text-[11px] text-slate-400 lg:block"
                    >
                        Primero la más vieja: es la que más cuesta cobrar
                    </span>
                </div>

                <!-- ── Con estancia ── -->
                <template v-if="tab === 'stays'">
                    <div
                        v-if="stays.data.length"
                        class="hidden overflow-auto lg:block lg:overflow-visible"
                    >
                        <Table hover>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th :class="tableHead"
                                        >Habitación</Table.Th
                                    >
                                    <Table.Th :class="tableHead"
                                        >Huésped</Table.Th
                                    >
                                    <Table.Th :class="tableHead"
                                        >Estancia</Table.Th
                                    >
                                    <Table.Th :class="[tableHead, 'text-right']"
                                        >Saldo</Table.Th
                                    >
                                    <Table.Th
                                        v-if="canManage"
                                        :class="[tableHead, 'text-right']"
                                    >
                                        Acciones
                                    </Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="s in stays.data"
                                    :key="s.id"
                                    class="align-top"
                                >
                                    <Table.Td class="whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                                            >
                                                <Lucide
                                                    icon="BedDouble"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </div>
                                            <div>
                                                <div
                                                    class="text-sm font-medium"
                                                >
                                                    {{
                                                        s.room ?? 'Sin asignar'
                                                    }}
                                                </div>
                                                <div
                                                    v-if="s.auto_closed"
                                                    class="text-[11px] text-slate-500"
                                                    title="Nadie registró la salida: la cerró el reloj"
                                                >
                                                    la cerró el reloj
                                                </div>
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="max-w-[20rem]">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ s.guest_name }}
                                        </div>
                                        <div
                                            class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                        >
                                            <span v-if="s.reservation_code">{{
                                                s.reservation_code
                                            }}</span>
                                            <span
                                                v-if="
                                                    s.reservation_code &&
                                                    s.guest_phone
                                                "
                                                class="text-slate-300 dark:text-darkmode-400"
                                                >·</span
                                            >
                                            <a
                                                v-if="s.guest_phone"
                                                :href="`tel:${s.guest_phone}`"
                                                class="transition hover:text-primary"
                                                >{{ s.guest_phone }}</a
                                            >
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="whitespace-nowrap">
                                        <div class="text-xs tabular-nums">
                                            {{ s.check_in_at }}
                                        </div>
                                        <div
                                            class="text-xs text-slate-500 tabular-nums"
                                        >
                                            salió
                                            {{
                                                s.check_out_at ??
                                                'sin registrar'
                                            }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td
                                        class="text-right whitespace-nowrap"
                                    >
                                        <div
                                            class="text-sm font-medium tabular-nums"
                                            :class="
                                                s.settlement_closed_at
                                                    ? 'text-slate-500'
                                                    : 'text-pending'
                                            "
                                        >
                                            {{ money(s.pending) }}
                                        </div>
                                        <div
                                            v-if="s.pending !== s.amount"
                                            class="text-[11px] text-slate-400 tabular-nums"
                                        >
                                            de {{ money(s.amount) }}
                                        </div>
                                        <div
                                            v-if="s.settlement_note"
                                            class="mt-0.5 max-w-[14rem] truncate text-[11px] text-slate-400"
                                            :title="s.settlement_note"
                                        >
                                            {{ s.settlement_note }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td v-if="canManage">
                                        <div
                                            class="flex flex-wrap justify-end gap-1.5"
                                        >
                                            <template
                                                v-if="!s.settlement_closed_at"
                                            >
                                                <Button
                                                    variant="primary"
                                                    class="h-8 rounded-[0.5rem] text-xs whitespace-nowrap"
                                                    @click="open('pay', s)"
                                                >
                                                    <Lucide
                                                        icon="Banknote"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Cobrar
                                                </Button>
                                                <button
                                                    type="button"
                                                    :class="rowAction"
                                                    class="hover:bg-primary/10 hover:text-primary"
                                                    title="Agregar lo que faltó"
                                                    @click="open('charge', s)"
                                                >
                                                    <Lucide
                                                        icon="ReceiptText"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    :class="rowAction"
                                                    class="hover:bg-primary/10 hover:text-primary"
                                                    title="Corregir la hora de salida"
                                                    @click="open('checkout', s)"
                                                >
                                                    <Lucide
                                                        icon="Clock"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                                <button
                                                    type="button"
                                                    :class="rowAction"
                                                    class="hover:bg-warning/10 hover:text-warning"
                                                    title="Cerrar sin cobrar"
                                                    @click="open('close', s)"
                                                >
                                                    <Lucide
                                                        icon="Archive"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                            </template>
                                            <Button
                                                v-else
                                                variant="outline-secondary"
                                                class="h-8 rounded-[0.5rem] bg-white text-xs whitespace-nowrap"
                                                @click="reopen(s)"
                                            >
                                                <Lucide
                                                    icon="RotateCcw"
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                Reabrir
                                            </Button>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                    </div>

                    <!-- Móvil y tablet: la tabla de cinco columnas no cabe -->
                    <div
                        v-if="stays.data.length"
                        class="divide-y divide-slate-200/60 lg:hidden dark:divide-darkmode-400"
                    >
                        <div
                            v-for="s in stays.data"
                            :key="`m-${s.id}`"
                            class="px-4 py-3.5"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >
                                            {{ s.room ?? 'Sin asignar' }}
                                        </span>
                                        <span
                                            v-if="s.auto_closed"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >
                                            La cerró el reloj
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1.5 truncate text-sm font-medium"
                                    >
                                        {{ s.guest_name }}
                                    </div>
                                    <a
                                        v-if="s.guest_phone"
                                        :href="`tel:${s.guest_phone}`"
                                        class="text-xs text-slate-500 transition hover:text-primary"
                                        >{{ s.guest_phone }}</a
                                    >
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        class="text-sm font-medium tabular-nums"
                                        :class="
                                            s.settlement_closed_at
                                                ? 'text-slate-500'
                                                : 'text-pending'
                                        "
                                    >
                                        {{ money(s.pending) }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        sin cobrar
                                    </div>
                                </div>
                            </div>

                            <div
                                class="mt-2 flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 tabular-nums"
                                >
                                    <Lucide
                                        icon="LogIn"
                                        class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                    />
                                    entró {{ s.check_in_at }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 tabular-nums"
                                >
                                    <Lucide
                                        icon="LogOut"
                                        class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                    />
                                    salió
                                    {{ s.check_out_at ?? 'sin registrar' }}
                                </span>
                            </div>
                            <p
                                v-if="s.settlement_note"
                                class="mt-1 text-[11px] text-slate-400"
                            >
                                {{ s.settlement_note }}
                            </p>

                            <div
                                v-if="canManage"
                                class="mt-2.5 flex flex-wrap items-center gap-1.5"
                            >
                                <template v-if="!s.settlement_closed_at">
                                    <Button
                                        variant="primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        @click="open('pay', s)"
                                    >
                                        <Lucide
                                            icon="Banknote"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Cobrar
                                    </Button>
                                    <div
                                        class="ml-auto flex items-center gap-1.5"
                                    >
                                        <button
                                            type="button"
                                            :class="rowAction"
                                            class="hover:bg-primary/10 hover:text-primary"
                                            title="Agregar lo que faltó"
                                            @click="open('charge', s)"
                                        >
                                            <Lucide
                                                icon="ReceiptText"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <button
                                            type="button"
                                            :class="rowAction"
                                            class="hover:bg-primary/10 hover:text-primary"
                                            title="Corregir la hora de salida"
                                            @click="open('checkout', s)"
                                        >
                                            <Lucide
                                                icon="Clock"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                        <button
                                            type="button"
                                            :class="rowAction"
                                            class="hover:bg-warning/10 hover:text-warning"
                                            title="Cerrar sin cobrar"
                                            @click="open('close', s)"
                                        >
                                            <Lucide
                                                icon="Archive"
                                                class="h-4 w-4"
                                            />
                                        </button>
                                    </div>
                                </template>
                                <Button
                                    v-else
                                    variant="outline-secondary"
                                    class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs"
                                    @click="reopen(s)"
                                >
                                    <Lucide
                                        icon="RotateCcw"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Reabrir
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="stays.links.length > 3"
                        class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <span class="text-xs text-slate-500">
                            {{ stays.from }}–{{ stays.to }} de {{ stays.total }}
                        </span>
                        <div class="ml-auto flex flex-wrap gap-1">
                            <component
                                :is="link.url ? Link : 'span'"
                                v-for="(link, i) in stays.links"
                                :key="i"
                                :href="link.url ?? undefined"
                                preserve-state
                                class="rounded-md px-2.5 py-1 text-xs"
                                :class="
                                    link.active
                                        ? 'bg-primary text-white'
                                        : link.url
                                          ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                          : 'text-slate-300'
                                "
                            >
                                <!-- El rótulo trae las flechas « » de Laravel. -->
                                <span v-html="link.label" />
                            </component>
                        </div>
                    </div>
                </template>

                <!-- ── Sin registro de llegada ── -->
                <template v-else>
                    <div
                        v-if="reservations.data.length"
                        class="hidden overflow-auto lg:block lg:overflow-visible"
                    >
                        <Table hover>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th :class="tableHead"
                                        >Habitación</Table.Th
                                    >
                                    <Table.Th :class="tableHead"
                                        >Huésped</Table.Th
                                    >
                                    <Table.Th :class="tableHead"
                                        >Fechas</Table.Th
                                    >
                                    <Table.Th :class="[tableHead, 'text-right']"
                                        >Saldo</Table.Th
                                    >
                                    <Table.Th
                                        v-if="canManage"
                                        :class="[tableHead, 'text-right']"
                                    >
                                        Acciones
                                    </Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="r in reservations.data"
                                    :key="r.id"
                                    class="align-top"
                                >
                                    <Table.Td class="whitespace-nowrap">
                                        <div class="flex items-center gap-2">
                                            <div
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                                            >
                                                <Lucide
                                                    icon="CalendarDays"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </div>
                                            <div>
                                                <div
                                                    class="text-sm font-medium"
                                                >
                                                    {{
                                                        r.room ?? 'Sin asignar'
                                                    }}
                                                </div>
                                                <div
                                                    v-if="r.auto_closed"
                                                    class="text-[11px] text-slate-500"
                                                    title="Nadie registró la llegada: la cerró el cierre de día"
                                                >
                                                    la cerró el día
                                                </div>
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="max-w-[20rem]">
                                        <div
                                            class="truncate text-sm font-medium"
                                        >
                                            {{ r.guest_name }}
                                        </div>
                                        <div
                                            class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                        >
                                            <span>{{ r.code }}</span>
                                            <template v-if="r.guest_phone">
                                                <span
                                                    class="text-slate-300 dark:text-darkmode-400"
                                                    >·</span
                                                >
                                                <a
                                                    :href="`tel:${r.guest_phone}`"
                                                    class="transition hover:text-primary"
                                                    >{{ r.guest_phone }}</a
                                                >
                                            </template>
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="whitespace-nowrap">
                                        <div class="text-xs tabular-nums">
                                            {{ r.starts_at }}
                                        </div>
                                        <div
                                            class="text-xs text-slate-500 tabular-nums"
                                        >
                                            sale {{ r.ends_at }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td
                                        class="text-right whitespace-nowrap"
                                    >
                                        <div
                                            class="text-sm font-medium tabular-nums"
                                            :class="
                                                r.settlement_closed_at
                                                    ? 'text-slate-500'
                                                    : 'text-pending'
                                            "
                                        >
                                            {{ money(r.pending) }}
                                        </div>
                                        <div
                                            v-if="
                                                r.unpaid &&
                                                !r.settlement_closed_at
                                            "
                                            class="mt-0.5 text-[11px] text-warning"
                                            title="El cierre de día la asumió ocupada, pero no tiene ningún abono"
                                        >
                                            Sin ningún pago: confirma si llegó
                                        </div>
                                        <div
                                            v-else
                                            class="text-[11px] text-slate-400 tabular-nums"
                                        >
                                            {{ money(r.paid) }} de
                                            {{ money(r.amount) }}
                                        </div>
                                        <div
                                            v-if="r.settlement_note"
                                            class="mt-0.5 max-w-[14rem] truncate text-[11px] text-slate-400"
                                            :title="r.settlement_note"
                                        >
                                            {{ r.settlement_note }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td v-if="canManage">
                                        <div
                                            class="flex flex-wrap justify-end gap-1.5"
                                        >
                                            <template
                                                v-if="!r.settlement_closed_at"
                                            >
                                                <!-- El cobro vive en la ficha de la
                                                     reserva: ahí están los abonos, el
                                                     cupón y el comprobante. -->
                                                <Button
                                                    :as="Link"
                                                    :href="
                                                        route(
                                                            'tenant.reservations.detail',
                                                            r.id,
                                                        )
                                                    "
                                                    variant="primary"
                                                    class="h-8 rounded-[0.5rem] text-xs whitespace-nowrap"
                                                >
                                                    <Lucide
                                                        icon="Banknote"
                                                        class="mr-1.5 h-3.5 w-3.5"
                                                    />
                                                    Cobrar
                                                </Button>
                                                <button
                                                    type="button"
                                                    :class="rowAction"
                                                    class="hover:bg-warning/10 hover:text-warning"
                                                    title="Cerrar sin cobrar"
                                                    @click="
                                                        openReservationClose(r)
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Archive"
                                                        class="h-4 w-4"
                                                    />
                                                </button>
                                            </template>
                                            <Button
                                                v-else
                                                variant="outline-secondary"
                                                class="h-8 rounded-[0.5rem] bg-white text-xs whitespace-nowrap"
                                                @click="reopenReservation(r)"
                                            >
                                                <Lucide
                                                    icon="RotateCcw"
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                Reabrir
                                            </Button>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                    </div>

                    <!-- Móvil y tablet -->
                    <div
                        v-if="reservations.data.length"
                        class="divide-y divide-slate-200/60 lg:hidden dark:divide-darkmode-400"
                    >
                        <div
                            v-for="r in reservations.data"
                            :key="`m-${r.id}`"
                            class="px-4 py-3.5"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >
                                            {{ r.room ?? 'Sin asignar' }}
                                        </span>
                                        <span
                                            v-if="r.auto_closed"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >
                                            La cerró el día
                                        </span>
                                    </div>
                                    <div
                                        class="mt-1.5 truncate text-sm font-medium"
                                    >
                                        {{ r.guest_name }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ r.code }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        class="text-sm font-medium tabular-nums"
                                        :class="
                                            r.settlement_closed_at
                                                ? 'text-slate-500'
                                                : 'text-pending'
                                        "
                                    >
                                        {{ money(r.pending) }}
                                    </div>
                                    <div class="text-[11px] text-slate-400">
                                        sin cobrar
                                    </div>
                                </div>
                            </div>

                            <div
                                class="mt-2 flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3"
                            >
                                <span
                                    class="inline-flex items-center gap-1.5 tabular-nums"
                                >
                                    <Lucide
                                        icon="CalendarDays"
                                        class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                    />
                                    {{ r.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ r.ends_at }}
                                </span>
                                <a
                                    v-if="r.guest_phone"
                                    :href="`tel:${r.guest_phone}`"
                                    class="inline-flex items-center gap-1.5 transition hover:text-primary"
                                >
                                    <Lucide
                                        icon="Phone"
                                        class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                    />
                                    {{ r.guest_phone }}
                                </a>
                            </div>
                            <p
                                v-if="r.unpaid && !r.settlement_closed_at"
                                class="mt-1 text-[11px] text-warning"
                            >
                                Sin ningún pago: confirma si llegó
                            </p>

                            <div
                                v-if="canManage"
                                class="mt-2.5 flex flex-wrap items-center gap-1.5"
                            >
                                <template v-if="!r.settlement_closed_at">
                                    <Button
                                        :as="Link"
                                        :href="
                                            route(
                                                'tenant.reservations.detail',
                                                r.id,
                                            )
                                        "
                                        variant="primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                    >
                                        <Lucide
                                            icon="Banknote"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Cobrar
                                    </Button>
                                    <button
                                        type="button"
                                        :class="rowAction"
                                        class="ml-auto hover:bg-warning/10 hover:text-warning"
                                        title="Cerrar sin cobrar"
                                        @click="openReservationClose(r)"
                                    >
                                        <Lucide
                                            icon="Archive"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </template>
                                <Button
                                    v-else
                                    variant="outline-secondary"
                                    class="ml-auto h-8 rounded-[0.5rem] bg-white text-xs"
                                    @click="reopenReservation(r)"
                                >
                                    <Lucide
                                        icon="RotateCcw"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Reabrir
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="reservations.links.length > 3"
                        class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <span class="text-xs text-slate-500">
                            {{ reservations.from }}–{{ reservations.to }} de
                            {{ reservations.total }}
                        </span>
                        <div class="ml-auto flex flex-wrap gap-1">
                            <component
                                :is="link.url ? Link : 'span'"
                                v-for="(link, i) in reservations.links"
                                :key="i"
                                :href="link.url ?? undefined"
                                preserve-state
                                class="rounded-md px-2.5 py-1 text-xs"
                                :class="
                                    link.active
                                        ? 'bg-primary text-white'
                                        : link.url
                                          ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                          : 'text-slate-300'
                                "
                            >
                                <!-- El rótulo trae las flechas « » de Laravel. -->
                                <span v-html="link.label" />
                            </component>
                        </div>
                    </div>
                </template>

                <!-- El vacío dice de qué pestaña habla -->
                <div
                    v-if="
                        (tab === 'stays' && !stays.data.length) ||
                        (tab === 'reservations' && !reservations.data.length)
                    "
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide
                        :icon="filters.q ? 'SearchX' : 'CircleCheck'"
                        class="h-8 w-8 text-slate-300"
                    />
                    <p class="text-sm font-medium text-slate-600">
                        {{ emptyMessage }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Una sola superficie para las cuatro acciones: son el mismo
             renglón visto de cuatro maneras, y cuatro modales distintos
             obligarían a aprenderse cuatro pantallas. -->
        <Dialog :open="action !== null" @close="action = null">
            <Dialog.Panel v-if="action" class="w-full max-w-md">
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide
                            :icon="meta[action.kind].icon as any"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-medium">
                            {{ meta[action.kind].title }}
                        </h3>
                        <p v-if="target" class="mt-0.5 text-xs text-slate-500">
                            Hab. {{ target.room ?? '—' }} ·
                            {{ target.guest_name }} ·
                            {{ money(target.pending) }} sin cobrar
                        </p>
                    </div>
                </div>

                <div class="space-y-4 px-5 py-4">
                    <p class="text-xs text-slate-500">
                        {{ meta[action.kind].hint }}
                    </p>

                    <template v-if="action.kind === 'pay'">
                        <div>
                            <FormLabel class="text-xs"
                                >¿Cómo lo recibiste?</FormLabel
                            >
                            <FormSelect
                                v-model="form.method"
                                class="h-9 text-xs"
                            >
                                <option
                                    v-for="m in methods"
                                    :key="m.key"
                                    :value="m.key"
                                >
                                    {{ m.label }}
                                </option>
                            </FormSelect>
                        </div>
                        <div v-if="form.method !== 'cash'">
                            <FormLabel class="text-xs"
                                >Referencia (opcional)</FormLabel
                            >
                            <FormInput
                                v-model="form.reference"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Folio del voucher o de la transferencia"
                                maxlength="100"
                            />
                        </div>
                    </template>

                    <template v-else-if="action.kind === 'charge'">
                        <div>
                            <FormLabel class="text-xs">Concepto</FormLabel>
                            <FormInput
                                v-model="form.concept"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Consumo del frigobar, daño en la lámpara…"
                                maxlength="100"
                            />
                        </div>
                        <div>
                            <FormLabel class="text-xs">Monto</FormLabel>
                            <FormInput
                                v-model="form.amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                class="h-9 text-xs"
                            />
                        </div>
                    </template>

                    <template
                        v-else-if="action.kind === 'checkout' && action.stay"
                    >
                        <div>
                            <FormLabel class="text-xs"
                                >Salida real del huésped</FormLabel
                            >
                            <FormDateTime
                                v-model="form.check_out_at"
                                input-class="h-9 text-xs"
                            />
                            <p class="mt-1 text-[11px] text-slate-500">
                                Entrada: {{ action.stay.check_in_at }} ·
                                registrada hoy como
                                {{ action.stay.check_out_at ?? '—' }}
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <div>
                            <FormLabel class="text-xs"
                                >¿Por qué no se cobra?</FormLabel
                            >
                            <FormInput
                                v-model="form.note"
                                type="text"
                                class="h-9 text-xs"
                                placeholder="Cortesía, incobrable, se capturó dos veces…"
                                maxlength="255"
                            />
                        </div>
                    </template>

                    <p
                        v-if="error"
                        class="rounded-lg bg-danger/10 px-3.5 py-3 text-xs text-danger"
                    >
                        {{ error }}
                    </p>
                </div>

                <div
                    class="flex justify-end gap-2 border-t border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                        @click="action = null"
                    >
                        Volver
                    </Button>
                    <Button
                        :variant="
                            action.kind === 'close' ||
                            action.kind === 'close-reservation'
                                ? 'warning'
                                : 'primary'
                        "
                        class="h-9 rounded-[0.5rem] text-xs"
                        :disabled="busy || blocked"
                        @click="submit"
                    >
                        <Lucide
                            :icon="meta[action.kind].icon as any"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{ busy ? 'Guardando…' : meta[action.kind].cta }}
                    </Button>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
