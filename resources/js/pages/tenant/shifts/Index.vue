<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ShiftRow {
    id: number;
    user_id: number;
    user: string;
    started_at: string;
    ended_at: string | null;
    started_at_input: string;
    ended_at_input: string | null;
    minutes: number;
    opening_cash: number;
    notes: string | null;
    opened_by: string | null;
    closed_by: string | null;
    has_cut: boolean;
}
interface ShiftTypeRow {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string;
    time: string;
    color: string;
    active: boolean;
}
interface AssignmentChip {
    id: number;
    shift_type_id: number;
    name: string;
    time: string;
    color: string;
}
interface ScheduledToday {
    id: number;
    user_id: number;
    user: string;
    type: string;
    time: string;
    color: string;
}

const props = defineProps<{
    property: { id: number; name: string };
    staff: { id: number; name: string }[];
    shiftTypes: ShiftTypeRow[];
    week: {
        start: string;
        label: string;
        prev: string;
        next: string;
        is_current: boolean;
    };
    days: { date: string; label: string; is_today: boolean }[];
    schedule: Record<string, AssignmentChip[]>;
    worked: string[];
    scheduledToday: ScheduledToday[];
    activeShifts: ShiftRow[];
    history: ShiftRow[];
    canSchedule: boolean;
}>();

const toast = useToasts();
const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n || 0);
const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';
const duration = (minutes: number) => {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h > 0 ? `${h} h ${m} min` : `${m} min`;
};

const chipColor: Record<string, string> = {
    primary: 'border-primary/20 bg-primary/10 text-primary',
    info: 'border-info/20 bg-info/10 text-info',
    success: 'border-success/20 bg-success/10 text-success',
    warning: 'border-warning/20 bg-warning/10 text-warning',
    pending: 'border-pending/20 bg-pending/10 text-pending',
    dark: 'border-dark/20 bg-dark/10 text-dark',
};
const dotColor: Record<string, string> = {
    primary: 'bg-primary',
    info: 'bg-info',
    success: 'bg-success',
    warning: 'bg-warning',
    pending: 'bg-pending',
    dark: 'bg-dark',
};
const colorOptions = [
    { key: 'warning', label: 'Amarillo' },
    { key: 'primary', label: 'Azul marino' },
    { key: 'info', label: 'Azul' },
    { key: 'success', label: 'Verde' },
    { key: 'pending', label: 'Naranja' },
    { key: 'dark', label: 'Oscuro' },
];

const tab = ref<'hoy' | 'rol' | 'historial'>('hoy');
const tabs = computed(() => [
    {
        key: 'hoy',
        label: 'Hoy',
        icon: 'Clock' as Icon,
        count: props.activeShifts.length,
    },
    {
        key: 'rol',
        label: 'Rol semanal',
        icon: 'CalendarDays' as Icon,
        count: Object.values(props.schedule).reduce((s, a) => s + a.length, 0),
    },
    {
        key: 'historial',
        label: 'Historial',
        icon: 'History' as Icon,
        count: props.history.length,
    },
]);

const cutUrl = (s: ShiftRow) =>
    route('tenant.cashcuts', {
        user: s.user_id,
        from: s.started_at_input,
        to: s.ended_at_input ?? undefined,
    });

const workedSet = computed(() => new Set(props.worked));
const activeUserIds = computed(
    () => new Set(props.activeShifts.map((s) => s.user_id)),
);

function goWeek(start: string) {
    router.get(
        route('tenant.shifts'),
        { week: start },
        { preserveScroll: true, preserveState: false },
    );
}

// ── Abrir turno ──
const showOpen = ref(false);
const saving = ref(false);
const openForm = reactive({
    user_id: '' as string | number,
    opening_cash: '' as string | number,
    notes: '',
});
const openError = ref<string | null>(null);

function askOpen(userId: number | null = null) {
    openForm.user_id = userId ?? props.staff[0]?.id ?? '';
    openForm.opening_cash = '';
    openForm.notes = '';
    openError.value = null;
    showOpen.value = true;
}

async function submitOpen() {
    saving.value = true;
    openError.value = null;
    try {
        await axios.post(route('tenant.shifts.store'), {
            user_id: openForm.user_id,
            opening_cash:
                openForm.opening_cash === '' ? 0 : openForm.opening_cash,
            notes: openForm.notes || null,
        });
        showOpen.value = false;
        toast.success(
            'Turno abierto',
            'El encargado quedó registrado en turno.',
        );
        router.reload();
    } catch (e: any) {
        openError.value =
            e.response?.data?.message ?? 'No se pudo abrir el turno.';
    } finally {
        saving.value = false;
    }
}

// ── Cerrar turno ──
const closing = ref<ShiftRow | null>(null);
const closeNotes = ref('');
const closeError = ref<string | null>(null);
const autoCut = ref(false);

function askClose(s: ShiftRow) {
    closing.value = s;
    closeNotes.value = '';
    closeError.value = null;
    autoCut.value = false;
}

async function submitClose(goToCut: boolean) {
    if (!closing.value) return;
    saving.value = true;
    closeError.value = null;
    try {
        const { data } = await axios.patch(
            route('tenant.shifts.close', closing.value.id),
            {
                notes: closeNotes.value || null,
                auto_cut: autoCut.value && !goToCut,
            },
        );
        const shift = closing.value;
        closing.value = null;
        toast.success('Turno cerrado', `${shift.user} terminó su turno.`);
        if (goToCut) {
            router.visit(
                route('tenant.cashcuts', {
                    user: shift.user_id,
                    from: shift.started_at_input,
                    to: String(data.ended_at).slice(0, 16),
                }),
            );
        } else {
            router.reload();
        }
    } catch (e: any) {
        closeError.value =
            e.response?.data?.message ?? 'No se pudo cerrar el turno.';
    } finally {
        saving.value = false;
    }
}

// ── Asignar turnos (celda del rol) ──
const assigning = ref<{
    userId: number;
    userName: string;
    date: string;
    dayLabel: string;
} | null>(null);
const assignSelection = ref<number[]>([]);

function openAssign(
    userId: number,
    userName: string,
    date: string,
    dayLabel: string,
) {
    if (!props.canSchedule) return;
    assigning.value = { userId, userName, date, dayLabel };
    assignSelection.value = (props.schedule[`${userId}|${date}`] ?? []).map(
        (a) => a.shift_type_id,
    );
}

function toggleType(typeId: number) {
    const idx = assignSelection.value.indexOf(typeId);
    if (idx >= 0) assignSelection.value.splice(idx, 1);
    else assignSelection.value.push(typeId);
}

async function submitAssign() {
    if (!assigning.value) return;
    saving.value = true;
    try {
        await axios.post(route('tenant.shift-assignments.sync'), {
            user_id: assigning.value.userId,
            date: assigning.value.date,
            shift_type_ids: assignSelection.value,
        });
        assigning.value = null;
        router.reload({ only: ['schedule', 'scheduledToday'] });
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

async function copyPreviousWeek() {
    saving.value = true;
    try {
        const { data } = await axios.post(
            route('tenant.shift-assignments.copy-week'),
            { week_start: props.week.start },
        );
        toast.success(
            'Semana copiada',
            `${data.copied} asignación(es) copiadas de la semana anterior.`,
        );
        router.reload({ only: ['schedule', 'scheduledToday'] });
    } catch (e: any) {
        toast.error(
            'No se pudo copiar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}

// ── Tipos de turno ──
const showTypes = ref(false);
const typeForm = reactive({
    id: null as number | null,
    name: '',
    starts_at: '07:00',
    ends_at: '15:00',
    color: 'warning',
});
const typeError = ref<string | null>(null);

function resetTypeForm() {
    typeForm.id = null;
    typeForm.name = '';
    typeForm.starts_at = '07:00';
    typeForm.ends_at = '15:00';
    typeForm.color = 'warning';
    typeError.value = null;
}

function editType(t: ShiftTypeRow) {
    typeForm.id = t.id;
    typeForm.name = t.name;
    typeForm.starts_at = t.starts_at;
    typeForm.ends_at = t.ends_at;
    typeForm.color = t.color;
    typeError.value = null;
}

async function submitType() {
    saving.value = true;
    typeError.value = null;
    try {
        const payload = {
            name: typeForm.name,
            starts_at: typeForm.starts_at,
            ends_at: typeForm.ends_at,
            color: typeForm.color,
        };
        if (typeForm.id)
            await axios.patch(
                route('tenant.shift-types.update', typeForm.id),
                payload,
            );
        else await axios.post(route('tenant.shift-types.store'), payload);
        resetTypeForm();
        router.reload({ only: ['shiftTypes', 'schedule', 'scheduledToday'] });
    } catch (e: any) {
        const firstError = (
            Object.values(e.response?.data?.errors ?? {})[0] as
                string[] | undefined
        )?.[0];
        typeError.value =
            e.response?.data?.message ?? firstError ?? 'No se pudo guardar.';
    } finally {
        saving.value = false;
    }
}

async function deleteType(t: ShiftTypeRow) {
    saving.value = true;
    typeError.value = null;
    try {
        await axios.delete(route('tenant.shift-types.destroy', t.id));
        router.reload({ only: ['shiftTypes', 'schedule', 'scheduledToday'] });
    } catch (e: any) {
        typeError.value = e.response?.data?.message ?? 'No se pudo eliminar.';
    } finally {
        saving.value = false;
    }
}

async function createSuggested() {
    saving.value = true;
    typeError.value = null;
    try {
        const suggestions = [
            {
                name: 'Matutino',
                starts_at: '07:00',
                ends_at: '15:00',
                color: 'warning',
            },
            {
                name: 'Vespertino',
                starts_at: '15:00',
                ends_at: '23:00',
                color: 'info',
            },
            {
                name: 'Nocturno',
                starts_at: '23:00',
                ends_at: '07:00',
                color: 'dark',
            },
        ];
        await Promise.all(
            suggestions.map((s) =>
                axios.post(route('tenant.shift-types.store'), s),
            ),
        );
        toast.success(
            'Tipos creados',
            'Matutino, Vespertino y Nocturno listos; ajústalos a tu operación.',
        );
        router.reload({ only: ['shiftTypes'] });
    } catch (e: any) {
        typeError.value = e.response?.data?.message ?? 'No se pudieron crear.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Turnos">
        <div class="mt-2">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Turnos</h1>
                    <p class="text-sm text-slate-500">
                        {{ property.name }} · rol semanal, quién está a cargo y
                        su corte
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canSchedule"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                        @click="
                            resetTypeForm();
                            showTypes = true;
                        "
                    >
                        <Lucide
                            icon="Settings2"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Tipos de turno
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.cashcuts')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="Calculator"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Cortes
                    </Button>
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        @click="askOpen()"
                    >
                        <Lucide
                            icon="AlarmClockPlus"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Abrir turno
                    </Button>
                </div>
            </div>

            <!-- Tabs -->
            <div
                class="mt-5 inline-flex flex-wrap gap-1 rounded-[0.7rem] border border-slate-200/80 bg-slate-100/70 p-1 dark:border-darkmode-400 dark:bg-darkmode-700"
            >
                <button
                    v-for="t in tabs"
                    :key="t.key"
                    class="flex items-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm font-medium transition"
                    :class="
                        tab === t.key
                            ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                            : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                    "
                    @click="tab = t.key as typeof tab"
                >
                    <Lucide :icon="t.icon" class="h-4 w-4" /> {{ t.label }}
                    <span
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none"
                        :class="
                            tab === t.key
                                ? 'bg-primary/10 text-primary'
                                : 'bg-slate-200/80 text-slate-500 dark:bg-darkmode-400'
                        "
                        >{{ t.count }}</span
                    >
                </button>
            </div>

            <!-- ============ TAB HOY ============ -->
            <div v-show="tab === 'hoy'" class="mt-5 grid grid-cols-12 gap-6">
                <!-- Programados hoy -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="flex items-center md:h-10">
                        <div class="text-base font-medium">Programados hoy</div>
                    </div>
                    <div class="box box--stacked mt-2 p-5">
                        <div v-if="scheduledToday.length" class="space-y-3">
                            <div
                                v-for="a in scheduledToday"
                                :key="a.id"
                                class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                                >
                                    {{ initials(a.user) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ a.user }}
                                    </div>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-[11px] font-medium"
                                        :class="chipColor[a.color]"
                                    >
                                        {{ a.type }} · {{ a.time }}
                                    </span>
                                </div>
                                <span
                                    v-if="activeUserIds.has(a.user_id)"
                                    class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full bg-success"
                                    />
                                    En turno
                                </span>
                                <button
                                    v-else
                                    type="button"
                                    title="Abrir su turno"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                    @click="askOpen(a.user_id)"
                                >
                                    <Lucide
                                        icon="AlarmClockPlus"
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-8 text-center text-slate-400"
                        >
                            <Lucide icon="CalendarDays" class="h-7 w-7" />
                            <p class="text-xs">
                                Nadie programado hoy. Arma el rol en la pestaña
                                "Rol semanal".
                            </p>
                        </div>
                    </div>
                </div>

                <!-- En turno ahora -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="flex items-center md:h-10">
                        <div
                            class="flex items-center gap-2 text-base font-medium"
                        >
                            <span class="relative flex h-2.5 w-2.5">
                                <span
                                    v-if="activeShifts.length"
                                    class="absolute inline-flex h-full w-full animate-ping rounded-full bg-success opacity-75"
                                />
                                <span
                                    class="relative inline-flex h-2.5 w-2.5 rounded-full"
                                    :class="
                                        activeShifts.length
                                            ? 'bg-success'
                                            : 'bg-slate-300'
                                    "
                                />
                            </span>
                            En turno ahora
                        </div>
                    </div>
                    <div
                        v-if="activeShifts.length"
                        class="mt-2 grid grid-cols-12 gap-5"
                    >
                        <div
                            v-for="s in activeShifts"
                            :key="s.id"
                            class="box box--stacked col-span-12 p-5 sm:col-span-6"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-sm font-semibold text-white"
                                >
                                    {{ initials(s.user) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium">
                                        {{ s.user }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Desde {{ s.started_at }}
                                    </div>
                                </div>
                            </div>
                            <div
                                class="mt-4 grid grid-cols-2 gap-3 border-t border-dashed border-slate-300/70 pt-4 text-sm dark:border-darkmode-400"
                            >
                                <div>
                                    <div
                                        class="flex items-center gap-1.5 text-xs text-slate-400"
                                    >
                                        <Lucide
                                            icon="Timer"
                                            class="h-3.5 w-3.5"
                                        />
                                        Duración
                                    </div>
                                    <div class="mt-0.5 font-medium">
                                        {{ duration(s.minutes) }}
                                    </div>
                                </div>
                                <div>
                                    <div
                                        class="flex items-center gap-1.5 text-xs text-slate-400"
                                    >
                                        <Lucide
                                            icon="Banknote"
                                            class="h-3.5 w-3.5"
                                        />
                                        Fondo inicial
                                    </div>
                                    <div class="mt-0.5 font-medium">
                                        {{ money(s.opening_cash) }}
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 flex gap-2">
                                <Button
                                    as="a"
                                    :href="cutUrl(s)"
                                    variant="outline-secondary"
                                    size="sm"
                                    class="flex-1 rounded-[0.5rem] bg-white"
                                >
                                    <Lucide
                                        icon="Calculator"
                                        class="mr-1.5 h-4 w-4"
                                    />
                                    Ver ventas
                                </Button>
                                <Button
                                    variant="outline-danger"
                                    size="sm"
                                    class="flex-1 rounded-[0.5rem]"
                                    @click="askClose(s)"
                                >
                                    <Lucide
                                        icon="AlarmClockOff"
                                        class="mr-1.5 h-4 w-4"
                                    />
                                    Cerrar turno
                                </Button>
                            </div>
                        </div>
                    </div>
                    <div
                        v-else
                        class="box box--stacked mt-2 flex flex-col items-center gap-3 py-12 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                        >
                            <Lucide icon="Clock" class="h-6 w-6" />
                        </div>
                        <p class="text-sm text-slate-500">
                            Nadie está en turno ahora mismo.
                        </p>
                        <Button
                            variant="outline-primary"
                            size="sm"
                            class="rounded-[0.5rem]"
                            @click="askOpen()"
                        >
                            <Lucide
                                icon="AlarmClockPlus"
                                class="mr-1.5 h-4 w-4"
                            />
                            Abrir turno
                        </Button>
                    </div>
                </div>
            </div>

            <!-- ============ TAB ROL SEMANAL ============ -->
            <div v-show="tab === 'rol'" class="mt-5">
                <!-- Barra de semana -->
                <div
                    class="box box--stacked flex flex-wrap items-center gap-3 p-3"
                >
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="goWeek(week.prev)"
                        >
                            <Lucide icon="ChevronLeft" class="h-4 w-4" />
                        </button>
                        <div
                            class="flex items-center gap-2 rounded-[0.5rem] border border-dashed border-slate-300/70 px-3 py-1.5 text-sm font-medium dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="CalendarRange"
                                class="h-4 w-4 text-primary"
                            />
                            {{ week.label }}
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="goWeek(week.next)"
                        >
                            <Lucide icon="ChevronRight" class="h-4 w-4" />
                        </button>
                        <Button
                            v-if="!week.is_current"
                            variant="outline-secondary"
                            size="sm"
                            class="ml-1 rounded-[0.5rem] bg-white"
                            @click="goWeek('')"
                            >Hoy</Button
                        >
                    </div>
                    <div class="ml-auto flex flex-wrap items-center gap-2">
                        <div class="mr-2 hidden items-center gap-3 lg:flex">
                            <span
                                v-for="t in shiftTypes"
                                :key="t.id"
                                class="flex items-center gap-1.5 text-xs text-slate-500"
                            >
                                <span
                                    class="h-2 w-2 rounded-full"
                                    :class="dotColor[t.color]"
                                />
                                {{ t.name }} {{ t.time }}
                            </span>
                        </div>
                        <Button
                            v-if="canSchedule"
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="saving"
                            @click="copyPreviousWeek"
                        >
                            <Lucide icon="Copy" class="mr-2 h-4 w-4" /> Copiar
                            semana anterior
                        </Button>
                    </div>
                </div>

                <!-- Sin tipos aún -->
                <div
                    v-if="!shiftTypes.length"
                    class="box box--stacked mt-4 flex flex-col items-center gap-3 py-12 text-center"
                >
                    <div
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="Settings2" class="h-6 w-6" />
                    </div>
                    <p class="text-sm text-slate-500">
                        Primero define tus tipos de turno (matutino, vespertino,
                        nocturno…).
                    </p>
                    <div class="flex gap-2">
                        <Button
                            v-if="canSchedule"
                            variant="primary"
                            size="sm"
                            class="rounded-[0.5rem]"
                            :disabled="saving"
                            @click="createSuggested"
                        >
                            <Lucide icon="Sparkles" class="mr-1.5 h-4 w-4" />
                            Crear los 3 clásicos
                        </Button>
                        <Button
                            v-if="canSchedule"
                            variant="outline-primary"
                            size="sm"
                            class="rounded-[0.5rem]"
                            @click="
                                resetTypeForm();
                                showTypes = true;
                            "
                        >
                            <Lucide icon="Plus" class="mr-1.5 h-4 w-4" /> Crear
                            los míos
                        </Button>
                    </div>
                </div>

                <!-- Grid semanal -->
                <div v-else class="box box--stacked mt-4 overflow-x-auto">
                    <table class="w-full min-w-[900px] border-collapse text-sm">
                        <thead>
                            <tr
                                class="border-b border-slate-200/70 dark:border-darkmode-400"
                            >
                                <th
                                    class="w-48 px-5 py-3 text-left font-medium text-slate-500"
                                >
                                    Encargado
                                </th>
                                <th
                                    v-for="d in days"
                                    :key="d.date"
                                    class="px-2 py-3 text-center font-medium"
                                    :class="
                                        d.is_today
                                            ? 'text-primary'
                                            : 'text-slate-500'
                                    "
                                >
                                    <span
                                        :class="
                                            d.is_today
                                                ? 'rounded-full bg-primary/10 px-2.5 py-1'
                                                : ''
                                        "
                                        >{{ d.label }}</span
                                    >
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="u in staff"
                                :key="u.id"
                                class="border-b border-slate-100 last:border-0 dark:border-darkmode-400/60"
                            >
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                                        >
                                            {{ initials(u.name) }}
                                        </div>
                                        <span class="truncate font-medium">{{
                                            u.name
                                        }}</span>
                                    </div>
                                </td>
                                <td
                                    v-for="d in days"
                                    :key="d.date"
                                    class="px-1.5 py-2 align-top"
                                    :class="
                                        d.is_today ? 'bg-primary/[0.03]' : ''
                                    "
                                >
                                    <button
                                        type="button"
                                        class="group flex min-h-[52px] w-full flex-col items-stretch gap-1 rounded-lg border border-transparent p-1 text-left transition"
                                        :class="
                                            canSchedule
                                                ? 'cursor-pointer hover:border-primary/30 hover:bg-primary/5'
                                                : 'cursor-default'
                                        "
                                        @click="
                                            openAssign(
                                                u.id,
                                                u.name,
                                                d.date,
                                                d.label,
                                            )
                                        "
                                    >
                                        <span
                                            v-for="a in schedule[
                                                `${u.id}|${d.date}`
                                            ] ?? []"
                                            :key="a.id"
                                            class="flex items-center justify-between gap-1 rounded-md border px-1.5 py-1 text-[11px] leading-tight font-medium"
                                            :class="chipColor[a.color]"
                                        >
                                            <span class="truncate">{{
                                                a.name
                                            }}</span>
                                            <Lucide
                                                v-if="
                                                    workedSet.has(
                                                        `${u.id}|${d.date}`,
                                                    )
                                                "
                                                icon="Check"
                                                class="h-3 w-3 shrink-0"
                                                title="Abrió turno ese día"
                                            />
                                        </span>
                                        <span
                                            v-if="
                                                !(
                                                    schedule[
                                                        `${u.id}|${d.date}`
                                                    ] ?? []
                                                ).length && canSchedule
                                            "
                                            class="mx-auto my-auto hidden text-slate-300 group-hover:block"
                                        >
                                            <Lucide
                                                icon="Plus"
                                                class="h-4 w-4"
                                            />
                                        </span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="shiftTypes.length"
                    class="mt-3 flex items-center gap-2 text-xs text-slate-400"
                >
                    <Lucide icon="Info" class="h-3.5 w-3.5" />
                    {{
                        canSchedule
                            ? 'Haz clic en una celda para asignar o quitar turnos.'
                            : 'Solo un gerente o el propietario pueden editar el rol.'
                    }}
                    La palomita ✓ marca los días en que la persona sí abrió
                    turno.
                </p>
            </div>

            <!-- ============ TAB HISTORIAL ============ -->
            <div v-show="tab === 'historial'" class="mt-5">
                <div
                    class="box box--stacked overflow-auto p-5 lg:overflow-visible"
                >
                    <Table v-if="history.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Encargado</Table.Th>
                                <Table.Th>Inicio → Fin</Table.Th>
                                <Table.Th class="text-right">Duración</Table.Th>
                                <Table.Th class="text-right">Fondo</Table.Th>
                                <Table.Th class="text-right">Corte</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="s in history" :key="s.id">
                                <Table.Td>
                                    <div class="flex items-center gap-2.5">
                                        <div
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                                        >
                                            {{ initials(s.user) }}
                                        </div>
                                        <div>
                                            <div class="font-medium">
                                                {{ s.user }}
                                            </div>
                                            <div
                                                v-if="s.notes"
                                                class="max-w-[220px] truncate text-xs text-slate-400"
                                                :title="s.notes"
                                            >
                                                {{ s.notes }}
                                            </div>
                                        </div>
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    class="text-sm whitespace-nowrap text-slate-500"
                                >
                                    {{ s.started_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ s.ended_at }}
                                </Table.Td>
                                <Table.Td
                                    class="text-right text-sm whitespace-nowrap"
                                >
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                                    >
                                        <Lucide icon="Timer" class="h-3 w-3" />
                                        {{ duration(s.minutes) }}
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="text-right text-sm text-slate-500"
                                    >{{ money(s.opening_cash) }}</Table.Td
                                >
                                <Table.Td class="text-right">
                                    <a
                                        v-if="s.has_cut"
                                        :href="cutUrl(s)"
                                        class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                                    >
                                        <Lucide
                                            icon="CircleCheck"
                                            class="h-3.5 w-3.5"
                                        />
                                        Con corte
                                    </a>
                                    <Button
                                        v-else
                                        as="a"
                                        :href="cutUrl(s)"
                                        variant="outline-primary"
                                        size="sm"
                                        class="rounded-[0.5rem] whitespace-nowrap"
                                    >
                                        <Lucide
                                            icon="Calculator"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Hacer corte
                                    </Button>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-2 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="History" class="h-8 w-8" />
                        <p class="text-sm">Sin turnos cerrados todavía.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal abrir turno -->
        <Dialog size="lg" :open="showOpen" @close="showOpen = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitOpen">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="AlarmClockPlus" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Abrir turno</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Registra quién queda a cargo desde este momento
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showOpen = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div>
                            <label class="mb-1 block text-sm">Encargado</label>
                            <div class="relative">
                                <Lucide
                                    icon="User"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormSelect
                                    v-model="openForm.user_id"
                                    class="pl-9"
                                >
                                    <option
                                        v-for="s in staff"
                                        :key="s.id"
                                        :value="s.id"
                                    >
                                        {{ s.name }}
                                    </option>
                                </FormSelect>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Fondo de caja inicial
                                <span class="text-slate-400"
                                    >(opcional)</span
                                ></label
                            >
                            <div class="relative">
                                <Lucide
                                    icon="Banknote"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    v-model="openForm.opening_cash"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="pl-9"
                                    placeholder="0.00"
                                />
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Dinero con el que arranca la caja (cambio,
                                morralla…).
                            </p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm"
                                >Notas
                                <span class="text-slate-400"
                                    >(opcional)</span
                                ></label
                            >
                            <FormInput
                                v-model="openForm.notes"
                                type="text"
                                placeholder="Turno matutino, cubre a…"
                            />
                        </div>
                        <p
                            v-if="openError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ openError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Abriendo…' : 'Abrir turno' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal cerrar turno -->
        <Dialog size="lg" :open="closing !== null" @close="closing = null">
            <Dialog.Panel>
                <div v-if="closing" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-pending/10 text-pending"
                        >
                            <Lucide icon="AlarmClockOff" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Cerrar turno de {{ closing.user }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Inició {{ closing.started_at }} · lleva
                                {{ duration(closing.minutes) }}
                            </p>
                        </div>
                    </div>
                    <div
                        class="mt-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 p-3.5 text-sm text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-700 dark:text-slate-300"
                    >
                        <p class="font-medium">¿Qué pasará?</p>
                        <ul
                            class="mt-1.5 list-inside list-disc space-y-1 text-xs"
                        >
                            <li>
                                El turno queda cerrado con la hora actual; deja
                                de aparecer en "En turno ahora".
                            </li>
                            <li>
                                Lo recomendable es hacer de una vez su
                                <span class="font-medium">corte de venta</span>
                                con el periodo exacto del turno.
                            </li>
                        </ul>
                    </div>
                    <div class="mt-4">
                        <label class="mb-1 block text-sm"
                            >Notas del turno
                            <span class="text-slate-400"
                                >(opcional)</span
                            ></label
                        >
                        <FormInput
                            v-model="closeNotes"
                            type="text"
                            placeholder="Todo en orden, pendientes…"
                        />
                    </div>

                    <label
                        class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 transition"
                        :class="
                            autoCut
                                ? 'border-primary/30 bg-primary/5'
                                : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400'
                        "
                    >
                        <input
                            v-model="autoCut"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/30"
                        />
                        <span class="text-sm">
                            <span class="flex items-center gap-1.5 font-medium"
                                ><Lucide
                                    icon="Zap"
                                    class="h-4 w-4 text-primary"
                                />
                                Generar el corte automáticamente</span
                            >
                            <span class="mt-0.5 block text-xs text-slate-500">
                                Se guarda el corte del periodo exacto del turno,
                                <span class="font-medium"
                                    >sin arqueo de efectivo</span
                                >. Si prefieres contar la caja, usa "Cerrar y
                                hacer arqueo".
                            </span>
                        </span>
                    </label>

                    <p
                        v-if="closeError"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ closeError }}
                    </p>
                    <div class="mt-6 flex flex-wrap justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="closing = null"
                            >Cancelar</Button
                        >
                        <Button
                            v-if="!autoCut"
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="saving"
                            @click="submitClose(false)"
                        >
                            <Lucide icon="AlarmClockOff" class="mr-2 h-4 w-4" />
                            Solo cerrar
                        </Button>
                        <Button
                            v-else
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="saving"
                            @click="submitClose(false)"
                        >
                            <Lucide icon="Zap" class="mr-2 h-4 w-4" /> Cerrar y
                            generar corte
                        </Button>
                        <Button
                            v-if="!autoCut"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="saving"
                            @click="submitClose(true)"
                        >
                            <Lucide icon="Calculator" class="mr-2 h-4 w-4" />
                            Cerrar y hacer arqueo
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal asignar turnos a un día -->
        <Dialog :open="assigning !== null" @close="assigning = null">
            <Dialog.Panel>
                <div v-if="assigning" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="CalendarDays" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                {{ assigning.userName }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500 capitalize">
                                {{ assigning.dayLabel }} · elige sus turnos
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 space-y-2">
                        <label
                            v-for="t in shiftTypes"
                            :key="t.id"
                            class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition"
                            :class="
                                assignSelection.includes(t.id)
                                    ? 'border-primary/40 bg-primary/5'
                                    : 'border-slate-200/70 hover:bg-slate-50 dark:border-darkmode-400'
                            "
                        >
                            <input
                                type="checkbox"
                                :checked="assignSelection.includes(t.id)"
                                class="h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary/30"
                                @change="toggleType(t.id)"
                            />
                            <span
                                class="h-2.5 w-2.5 rounded-full"
                                :class="dotColor[t.color]"
                            />
                            <span class="flex-1 text-sm font-medium">{{
                                t.name
                            }}</span>
                            <span class="text-xs text-slate-500">{{
                                t.time
                            }}</span>
                        </label>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="assigning = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving"
                            @click="submitAssign"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Guardando…' : 'Guardar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal tipos de turno -->
        <Dialog size="lg" :open="showTypes" @close="showTypes = false">
            <Dialog.Panel>
                <div class="flex max-h-[85vh] flex-col">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="Settings2" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Tipos de turno
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Define los horarios de tu operación
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showTypes = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-5">
                        <!-- Existentes -->
                        <div v-if="shiftTypes.length" class="space-y-2">
                            <div
                                v-for="t in shiftTypes"
                                :key="t.id"
                                class="flex items-center gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                            >
                                <span
                                    class="h-3 w-3 shrink-0 rounded-full"
                                    :class="dotColor[t.color]"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        {{ t.name }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ t.time }}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    title="Editar"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                                    @click="editType(t)"
                                >
                                    <Lucide icon="Pencil" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    title="Eliminar"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-500 transition hover:bg-danger/10 hover:text-danger"
                                    :disabled="saving"
                                    @click="deleteType(t)"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-slate-300/70 py-8 text-center dark:border-darkmode-400"
                        >
                            <p class="text-sm text-slate-500">Sin tipos aún.</p>
                            <Button
                                variant="outline-primary"
                                size="sm"
                                class="rounded-[0.5rem]"
                                :disabled="saving"
                                @click="createSuggested"
                            >
                                <Lucide
                                    icon="Sparkles"
                                    class="mr-1.5 h-4 w-4"
                                />
                                Crear Matutino / Vespertino / Nocturno
                            </Button>
                        </div>

                        <!-- Formulario -->
                        <div
                            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    :icon="typeForm.id ? 'Pencil' : 'Plus'"
                                    class="h-3.5 w-3.5"
                                />
                                {{ typeForm.id ? 'Editar tipo' : 'Nuevo tipo' }}
                            </div>
                            <div
                                class="grid grid-cols-1 gap-x-4 gap-y-3 sm:grid-cols-3"
                            >
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Nombre</label
                                    >
                                    <FormInput
                                        v-model="typeForm.name"
                                        type="text"
                                        placeholder="Matutino"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Entra</label
                                    >
                                    <FormInput
                                        v-model="typeForm.starts_at"
                                        type="time"
                                    />
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Sale</label
                                    >
                                    <FormInput
                                        v-model="typeForm.ends_at"
                                        type="time"
                                    />
                                </div>
                            </div>
                            <div class="mt-3">
                                <label class="mb-1.5 block text-sm"
                                    >Color</label
                                >
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="c in colorOptions"
                                        :key="c.key"
                                        type="button"
                                        :title="c.label"
                                        class="flex h-8 w-8 items-center justify-center rounded-full border-2 transition"
                                        :class="
                                            typeForm.color === c.key
                                                ? 'border-slate-600 dark:border-white'
                                                : 'border-transparent'
                                        "
                                        @click="typeForm.color = c.key"
                                    >
                                        <span
                                            class="h-5 w-5 rounded-full"
                                            :class="dotColor[c.key]"
                                        />
                                    </button>
                                </div>
                            </div>
                            <p
                                v-if="typeError"
                                class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                            >
                                {{ typeError }}
                            </p>
                            <div class="mt-4 flex justify-end gap-2">
                                <Button
                                    v-if="typeForm.id"
                                    variant="outline-secondary"
                                    size="sm"
                                    @click="resetTypeForm"
                                    >Cancelar edición</Button
                                >
                                <Button
                                    variant="primary"
                                    size="sm"
                                    class="rounded-[0.5rem]"
                                    :disabled="saving || !typeForm.name"
                                    @click="submitType"
                                >
                                    <Lucide
                                        icon="Check"
                                        class="mr-1.5 h-4 w-4"
                                    />
                                    {{
                                        typeForm.id
                                            ? 'Guardar cambios'
                                            : 'Agregar tipo'
                                    }}
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
