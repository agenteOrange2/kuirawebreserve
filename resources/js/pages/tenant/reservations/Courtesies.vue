<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormDate, FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import CourtesyDialog from '@/components/courtesies/CourtesyDialog.vue';
import { courtesyMoney as money } from '@/components/courtesies/types';
import type { CourtesyPayload } from '@/components/courtesies/types';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import ReservationsNav from './ReservationsNav.vue';

/**
 * Cortesías (/reservas/cortesias): la bandeja del propietario para decidir
 * lo que pide el personal, el historial de lo regalado y el reporte del mes
 * contra el tope. Quien no es propietario ve solo lo que pidió.
 */
interface CourtesyRow extends CourtesyPayload {
    guest_name: string | null;
    reservation_id: number | null;
    stay_id: number | null;
    target_label: string;
    room_number: string | null;
    dates: string | null;
    is_mine: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    courtesies: {
        data: CourtesyRow[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        estado: string;
        categoria: string;
        desde: string;
        hasta: string;
        q: string;
        persona: number | null;
    };
    summary: {
        month_label: string;
        month_amount: number;
        month_count: number;
        pending: number;
        cap: number | null;
        by_category: {
            category: string;
            label: string;
            count: number;
            amount: number;
        }[];
    };
    categories: Record<string, string>;
    people: { id: number; name: string }[];
    isOwner: boolean;
    focusId: number | null;
}>();

const toast = useToasts();

// ── Filtros: viajan en la URL para que el reporte se pueda compartir ──
const estado = ref(props.filters.estado);
const categoria = ref(props.filters.categoria);
const desde = ref(props.filters.desde);
const hasta = ref(props.filters.hasta);
const q = ref(props.filters.q);
const persona = ref<string>(
    props.filters.persona ? String(props.filters.persona) : '',
);

const query = computed(() => ({
    estado: estado.value || 'todas',
    categoria: categoria.value || undefined,
    desde: desde.value || undefined,
    hasta: hasta.value || undefined,
    q: q.value.trim() || undefined,
    persona: persona.value || undefined,
}));

function reload() {
    router.get(route('tenant.reservations.courtesies'), query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['courtesies', 'filters', 'summary'],
    });
}

let timer: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(reload, 350);
});
watch([estado, categoria, desde, hasta, persona], reload);

const exportUrl = computed(() => {
    const params = new URLSearchParams(
        Object.entries(query.value).filter(([, v]) => v !== undefined) as [
            string,
            string,
        ][],
    );
    const qs = params.toString();

    return (
        route('tenant.reservations.courtesies.export') + (qs ? `?${qs}` : '')
    );
});

const hasFilters = computed(
    () =>
        !!(
            !['todas', 'pending'].includes(estado.value) ||
            categoria.value ||
            desde.value ||
            hasta.value ||
            q.value.trim() ||
            persona.value
        ),
);

function clearFilters() {
    estado.value = estado.value === 'pending' ? 'pending' : 'todas';
    categoria.value = '';
    desde.value = '';
    hasta.value = '';
    q.value = '';
    persona.value = '';
}

// ── Cifras del mes ──
const capPercent = computed(() =>
    props.summary.cap
        ? Math.min(
              100,
              Math.round(
                  (props.summary.month_amount / props.summary.cap) * 100,
              ),
          )
        : 0,
);

const tiles = computed(() => [
    {
        key: 'amount',
        icon: 'HandHeart' as Icon,
        tone: 'border-pending/10 bg-pending/10 text-pending',
        value: money(props.summary.month_amount),
        label: props.isOwner ? 'Regalado este mes' : 'Lo que pediste este mes',
        detail: props.summary.month_label,
    },
    {
        key: 'count',
        icon: 'ListChecks' as Icon,
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: String(props.summary.month_count),
        label: 'Cortesías aprobadas',
        detail: props.summary.month_label,
    },
    {
        key: 'pending',
        icon: 'Clock' as Icon,
        tone: 'border-warning/10 bg-warning/10 text-warning',
        value: String(props.summary.pending),
        label: props.isOwner ? 'Por aprobar' : 'Esperando respuesta',
        detail: props.summary.pending
            ? 'La cuenta sigue con su precio'
            : 'Nada pendiente',
    },
    {
        key: 'cap',
        icon: 'Gauge' as Icon,
        tone:
            props.summary.cap && capPercent.value >= 100
                ? 'border-danger/10 bg-danger/10 text-danger'
                : 'border-success/10 bg-success/10 text-success',
        value: props.summary.cap ? `${capPercent.value}%` : 'Sin tope',
        label: props.summary.cap
            ? `del tope de ${money(props.summary.cap)}`
            : 'Tope mensual',
        detail: props.summary.cap
            ? 'Pasarlo pide confirmar dos veces'
            : props.isOwner
              ? 'Ponlo en Ajustes'
              : 'El propietario no puso tope',
    },
]);

// ── Renglones ──
const statusStyle: Record<
    string,
    { icon: Icon; circle: string; badge: string }
> = {
    pending: {
        icon: 'Clock',
        circle: 'border-warning/10 bg-warning/10 text-warning',
        badge: 'bg-warning/10 text-warning',
    },
    approved: {
        icon: 'HandHeart',
        circle: 'border-pending/10 bg-pending/10 text-pending',
        badge: 'bg-success/10 text-success',
    },
    rejected: {
        icon: 'Ban',
        circle: 'border-danger/10 bg-danger/10 text-danger',
        badge: 'bg-danger/10 text-danger',
    },
    canceled: {
        icon: 'CircleSlash',
        circle: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
        badge: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    },
    revoked: {
        icon: 'Undo2',
        circle: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400',
        badge: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    },
};
const styleFor = (status: string) =>
    statusStyle[status] ?? statusStyle.canceled;

const dateLabel = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('es-MX', {
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';

// ── Decidir ──
const busyId = ref<number | null>(null);
/** Renglón que espera el segundo clic y para qué. */
const confirming = ref<{
    id: number;
    action: 'reject' | 'revoke' | 'cancel' | 'cap';
    message?: string;
} | null>(null);
const approveTarget = ref<CourtesyRow | null>(null);

async function act(
    row: CourtesyRow,
    action: 'approve' | 'reject' | 'revoke' | 'cancel',
    body: Record<string, unknown> = {},
) {
    if (busyId.value) return;

    busyId.value = row.id;
    try {
        if (action === 'cancel') {
            await axios.delete(`/api/courtesies/${row.id}`);
        } else {
            await axios.post(`/api/courtesies/${row.id}/${action}`, body);
        }

        const titles = {
            approve: 'Cortesía aprobada',
            reject: 'Cortesía rechazada',
            revoke: 'Cortesía retirada',
            cancel: 'Solicitud cancelada',
        };
        toast.success(
            titles[action],
            `${row.target_label} · ${row.guest_name ?? 'Sin nombre'}`,
        );
        confirming.value = null;
        router.reload({ only: ['courtesies', 'summary'] });
    } catch (error: any) {
        if (error.response?.status === 409) {
            confirming.value = {
                id: row.id,
                action: 'cap',
                message: error.response.data.message,
            };
        } else {
            toast.error(
                'No se pudo completar',
                error.response?.data?.message ?? 'Ocurrió un error.',
            );
        }
    } finally {
        busyId.value = null;
    }
}

const isConfirming = (row: CourtesyRow, action: string) =>
    confirming.value?.id === row.id && confirming.value.action === action;

// Llegó desde la campana (?ver=ID): se resalta y se lleva a la vista.
onMounted(async () => {
    if (!props.focusId) return;
    await nextTick();
    document
        .getElementById(`courtesy-${props.focusId}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
});

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const sectionLabel =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const ghostButton =
    'inline-flex h-8 items-center rounded-[0.5rem] px-2.5 text-xs font-medium text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400';
</script>

<template>
    <RazeLayout title="Cortesías">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="HandHeart" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Cortesías</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{
                                isOwner
                                    ? 'Hospedaje gratis o con descuento: lo que pide el personal y lo que ya se regaló.'
                                    : 'Las cortesías que pediste y lo que decidió el propietario.'
                            }}
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.reservations')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a reservas
                    </Link>
                    <Button
                        as="a"
                        :href="exportUrl"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                    >
                        <Lucide icon="Download" class="mr-1.5 h-3.5 w-3.5" />
                        Exportar CSV
                    </Button>
                    <Button
                        v-if="isOwner"
                        :as="Link"
                        :href="route('tenant.courtesy-settings')"
                        variant="outline-secondary"
                        class="col-span-2 h-9 rounded-[0.5rem] bg-white text-xs md:col-span-1 dark:bg-darkmode-600"
                    >
                        <Lucide icon="Settings2" class="mr-1.5 h-3.5 w-3.5" />
                        Tope mensual
                    </Button>
                </div>
            </div>

            <ReservationsNav current="courtesies" />

            <!-- Cifras del mes -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
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

            <!-- Por categoría: de qué tipo fue lo regalado este mes -->
            <div
                v-if="summary.by_category.length"
                class="box box--stacked mt-4 flex flex-wrap items-center gap-2 px-4 py-3"
            >
                <span :class="sectionLabel" class="mr-1">Por categoría</span>
                <button
                    v-for="row in summary.by_category"
                    :key="row.category"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-medium transition"
                    :class="
                        categoria === row.category
                            ? 'border-primary/40 bg-primary/10 text-primary'
                            : 'border-slate-200 text-slate-600 hover:border-primary/30 dark:border-darkmode-400 dark:text-slate-300'
                    "
                    @click="
                        categoria =
                            categoria === row.category ? '' : row.category
                    "
                >
                    {{ row.label }}
                    <span class="text-slate-400">{{ row.count }}</span>
                    <span class="text-pending">{{ money(row.amount) }}</span>
                </button>
            </div>

            <div class="box box--stacked mt-4">
                <!-- Pestañas: lo que espera decisión y todo lo demás -->
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
                                estado === 'pending'
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="estado = 'pending'"
                        >
                            <Lucide icon="Clock" class="h-3.5 w-3.5" />
                            {{ isOwner ? 'Por aprobar' : 'Esperando' }}
                            <span
                                class="rounded-full bg-warning/10 px-1.5 text-[11px] font-normal text-warning"
                                >{{ summary.pending }}</span
                            >
                        </button>
                        <button
                            type="button"
                            class="flex h-8 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium transition"
                            :class="
                                estado !== 'pending'
                                    ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                    : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                            "
                            @click="estado = 'todas'"
                        >
                            <Lucide icon="History" class="h-3.5 w-3.5" />
                            Historial
                        </button>
                    </div>
                    <span class="ml-auto text-[11px] text-slate-400">
                        {{ courtesies.total }}
                        {{ courtesies.total === 1 ? 'cortesía' : 'cortesías' }}
                    </span>
                </div>

                <!-- Filtros, en franja gris pegada a la lista -->
                <div
                    class="grid grid-cols-2 gap-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex sm:flex-wrap sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative col-span-2 min-w-0 sm:w-56">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="text"
                            class="h-9 pl-9 text-xs"
                            placeholder="Huésped, folio o motivo"
                        />
                    </div>
                    <FormSelect
                        v-if="estado !== 'pending'"
                        v-model="estado"
                        class="h-9 text-xs sm:w-36"
                    >
                        <option value="todas">Todos los estados</option>
                        <option value="approved">Aprobadas</option>
                        <option value="rejected">Rechazadas</option>
                        <option value="revoked">Retiradas</option>
                        <option value="canceled">Canceladas</option>
                    </FormSelect>
                    <FormSelect v-model="categoria" class="h-9 text-xs sm:w-44">
                        <option value="">Todas las categorías</option>
                        <option
                            v-for="(label, key) in categories"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </FormSelect>
                    <FormSelect
                        v-if="isOwner && people.length"
                        v-model="persona"
                        class="h-9 text-xs sm:w-40"
                    >
                        <option value="">Quien la pidió</option>
                        <option
                            v-for="person in people"
                            :key="person.id"
                            :value="String(person.id)"
                        >
                            {{ person.name }}
                        </option>
                    </FormSelect>
                    <FormDate
                        v-model="desde"
                        class="sm:w-32"
                        input-class="h-9 text-xs"
                        placeholder="Desde"
                        clearable
                    />
                    <FormDate
                        v-model="hasta"
                        class="sm:w-32"
                        input-class="h-9 text-xs"
                        placeholder="Hasta"
                        clearable
                    />
                    <button
                        v-if="hasFilters"
                        type="button"
                        class="text-xs font-medium text-primary sm:ml-auto"
                        @click="clearFilters"
                    >
                        Limpiar filtros
                    </button>
                </div>

                <!-- Lista -->
                <div
                    v-if="courtesies.data.length"
                    class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                >
                    <div
                        v-for="row in courtesies.data"
                        :id="`courtesy-${row.id}`"
                        :key="row.id"
                        class="flex flex-col gap-3 px-4 py-3 sm:px-5 lg:flex-row lg:items-center"
                        :class="
                            focusId === row.id
                                ? 'bg-primary/5 ring-1 ring-primary/20 ring-inset'
                                : ''
                        "
                    >
                        <div class="flex min-w-0 flex-1 items-start gap-3">
                            <div
                                :class="[
                                    sectionIcon,
                                    styleFor(row.status).circle,
                                ]"
                            >
                                <Lucide
                                    :icon="styleFor(row.status).icon"
                                    class="h-4 w-4"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <span class="text-sm font-medium">{{
                                        row.guest_name || 'Sin nombre'
                                    }}</span>
                                    <Link
                                        v-if="row.reservation_id"
                                        :href="`/reservas/${row.reservation_id}`"
                                        class="text-xs text-primary hover:underline"
                                        >{{ row.target_label }}</Link
                                    >
                                    <span
                                        v-else
                                        class="text-xs text-slate-500"
                                        >{{ row.target_label }}</span
                                    >
                                    <span
                                        v-if="row.room_number"
                                        class="text-xs text-slate-500"
                                        >· Hab. {{ row.room_number }}</span
                                    >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="styleFor(row.status).badge"
                                        >{{ row.status_label }}</span
                                    >
                                    <span
                                        v-if="row.over_cap"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                        >Pasó el tope</span
                                    >
                                </div>
                                <div class="mt-0.5 text-xs">
                                    <span class="font-medium text-pending"
                                        >−{{ money(row.amount) }}</span
                                    >
                                    <span class="text-slate-500">
                                        · {{ row.kind_label }} de
                                        {{ money(row.lodging_base) }} ·
                                        {{ row.category_label }}</span
                                    >
                                </div>
                                <div
                                    class="mt-0.5 text-xs text-slate-600 dark:text-slate-300"
                                >
                                    {{ row.reason }}
                                </div>
                                <div class="mt-0.5 text-[11px] text-slate-400">
                                    <template v-if="row.requested_by_name"
                                        >La pidió {{ row.requested_by_name }} ·
                                        {{
                                            dateLabel(row.created_at)
                                        }}</template
                                    >
                                    <template
                                        v-if="
                                            row.decided_by_name &&
                                            row.decided_by_name !==
                                                row.requested_by_name
                                        "
                                    >
                                        · decidió {{ row.decided_by_name }}
                                    </template>
                                    <template v-if="row.decision_note">
                                        · {{ row.decision_note }}</template
                                    >
                                    <template v-if="row.dates">
                                        · {{ row.dates }}</template
                                    >
                                </div>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div
                            class="flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end"
                        >
                            <template
                                v-if="
                                    confirming &&
                                    confirming.id === row.id &&
                                    confirming.action === 'cap'
                                "
                            >
                                <span class="max-w-xs text-xs text-danger">{{
                                    confirming.message
                                }}</span>
                                <button
                                    type="button"
                                    :class="ghostButton"
                                    @click="confirming = null"
                                >
                                    Cancelar
                                </button>
                                <Button
                                    variant="danger"
                                    class="h-8 rounded-[0.5rem] text-xs"
                                    :disabled="busyId === row.id"
                                    @click="
                                        act(row, 'approve', {
                                            confirm_over_cap: true,
                                        })
                                    "
                                >
                                    Aprobar de todos modos
                                </Button>
                            </template>

                            <template
                                v-else-if="row.status === 'pending' && isOwner"
                            >
                                <template v-if="isConfirming(row, 'reject')">
                                    <span class="text-xs text-slate-500"
                                        >¿Rechazarla?</span
                                    >
                                    <button
                                        type="button"
                                        :class="ghostButton"
                                        @click="confirming = null"
                                    >
                                        No
                                    </button>
                                    <Button
                                        variant="danger"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busyId === row.id"
                                        @click="act(row, 'reject')"
                                    >
                                        Sí, rechazar
                                    </Button>
                                </template>
                                <template v-else>
                                    <button
                                        type="button"
                                        :class="ghostButton"
                                        class="hover:text-danger"
                                        @click="
                                            confirming = {
                                                id: row.id,
                                                action: 'reject',
                                            }
                                        "
                                    >
                                        Rechazar
                                    </button>
                                    <Button
                                        variant="outline-secondary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                                        :disabled="busyId === row.id"
                                        @click="approveTarget = row"
                                    >
                                        Otro monto
                                    </Button>
                                    <Button
                                        variant="primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busyId === row.id"
                                        @click="act(row, 'approve')"
                                    >
                                        <Lucide
                                            icon="Check"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Aprobar
                                    </Button>
                                </template>
                            </template>

                            <template
                                v-else-if="
                                    row.status === 'pending' && row.is_mine
                                "
                            >
                                <template v-if="isConfirming(row, 'cancel')">
                                    <button
                                        type="button"
                                        :class="ghostButton"
                                        @click="confirming = null"
                                    >
                                        No
                                    </button>
                                    <Button
                                        variant="danger"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busyId === row.id"
                                        @click="act(row, 'cancel')"
                                    >
                                        Sí, cancelar
                                    </Button>
                                </template>
                                <button
                                    v-else
                                    type="button"
                                    :class="ghostButton"
                                    class="hover:text-danger"
                                    @click="
                                        confirming = {
                                            id: row.id,
                                            action: 'cancel',
                                        }
                                    "
                                >
                                    Cancelar solicitud
                                </button>
                            </template>

                            <template
                                v-else-if="row.status === 'approved' && isOwner"
                            >
                                <template v-if="isConfirming(row, 'revoke')">
                                    <span class="text-xs text-slate-500"
                                        >¿Cobrar completo?</span
                                    >
                                    <button
                                        type="button"
                                        :class="ghostButton"
                                        @click="confirming = null"
                                    >
                                        No
                                    </button>
                                    <Button
                                        variant="danger"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        :disabled="busyId === row.id"
                                        @click="act(row, 'revoke')"
                                    >
                                        Sí, retirar
                                    </Button>
                                </template>
                                <button
                                    v-else
                                    type="button"
                                    :class="ghostButton"
                                    class="hover:text-danger"
                                    @click="
                                        confirming = {
                                            id: row.id,
                                            action: 'revoke',
                                        }
                                    "
                                >
                                    Retirar
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-4 py-10 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="HandHeart" class="h-4 w-4" />
                    </div>
                    <div class="text-sm font-medium">
                        {{
                            estado === 'pending'
                                ? 'Nada esperando decisión'
                                : 'Sin cortesías con estos filtros'
                        }}
                    </div>
                    <p class="max-w-md text-xs text-slate-500">
                        Las cortesías se dan o se piden desde la ficha de la
                        reserva (tarjeta Pagos) o desde el plano, en la
                        habitación ocupada.
                    </p>
                </div>

                <div
                    v-if="courtesies.links.length > 3"
                    class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-500">
                        {{ courtesies.from }}–{{ courtesies.to }} de
                        {{ courtesies.total }}
                    </span>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <component
                            :is="link.url ? Link : 'span'"
                            v-for="(link, i) in courtesies.links"
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
            </div>
        </div>

        <CourtesyDialog
            :open="approveTarget !== null"
            :target="{
                reservation_id: approveTarget?.reservation_id,
                stay_id: approveTarget?.stay_id,
            }"
            :is-owner="isOwner"
            :categories="categories"
            :subtitle="
                approveTarget
                    ? `${approveTarget.target_label} · ${approveTarget.guest_name ?? 'Sin nombre'}`
                    : null
            "
            :courtesy="approveTarget"
            @close="approveTarget = null"
            @done="router.reload({ only: ['courtesies', 'summary'] })"
        />
    </RazeLayout>
</template>
