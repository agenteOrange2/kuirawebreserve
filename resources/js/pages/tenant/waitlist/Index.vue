<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface EntryRow {
    id: number;
    guest_name: string;
    guest_phone: string | null;
    guest_email: string | null;
    room_type: string | null;
    starts_at: string;
    ends_at: string;
    status: string;
    status_label: string;
    notified_at: string | null;
    notified_channel: string | null;
    notify_attempts: number;
    notify_failed_at: string | null;
    notify_error: string | null;
    inbox_url: string | null;
    reservation_code: string | null;
    reservation_id: number | null;
    converted_at: string | null;
    created_at: string;
    wa_phone: string | null;
    wa_text: string | null;
}

interface CandidateRow {
    id: number;
    code: string | null;
    guest_name: string | null;
    room: string | null;
    room_type: string | null;
    dates: string;
    status_label: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    entries: {
        data: EntryRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { q: string; status: string };
    stats: {
        waiting: number;
        notified: number;
        converted: number;
        expired: number;
        failed: number;
    };
    canManage: boolean;
}>();

const toast = useToasts();

// Copia local: las acciones reemplazan el renglón sin recargar la página.
const rows = ref<EntryRow[]>([...props.entries.data]);
watch(
    () => props.entries.data,
    (fresh) => {
        rows.value = [...fresh];
    },
);

const replaceRow = (entry: EntryRow) => {
    rows.value = rows.value.map((r) => (r.id === entry.id ? entry : r));
};

// ── Buscador y filtro (reactivos, con debounce) ──
const q = ref(props.filters.q);
const status = ref(props.filters.status);

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, status], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.waitlist'),
            {
                q: q.value || undefined,
                status: status.value || undefined,
            },
            {
                preserveState: true,
                replace: true,
                only: ['entries', 'filters', 'stats'],
            },
        );
    }, 350);
});

const statusClass: Record<string, string> = {
    waiting: 'bg-warning/10 text-warning',
    notified: 'bg-primary/10 text-primary',
    converted: 'bg-success/10 text-success',
    expired: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400 dark:text-slate-300',
};

// Los contadores no solo cuentan: cada uno filtra la lista por su estado
// (clic otra vez para quitar el filtro).
const tiles = computed<
    { key: string; label: string; value: number; icon: Icon; tone: string }[]
>(() => [
    {
        key: 'waiting',
        label: 'En espera',
        value: props.stats.waiting,
        icon: 'Clock',
        tone: 'border-warning/10 bg-warning/10 text-warning',
    },
    {
        key: 'notified',
        label: 'Avisados',
        value: props.stats.notified,
        icon: 'BellRing',
        tone: 'border-primary/10 bg-primary/10 text-primary',
    },
    {
        key: 'converted',
        label: 'Convertidas',
        value: props.stats.converted,
        icon: 'CircleCheckBig',
        tone: 'border-success/10 bg-success/10 text-success',
    },
    {
        key: 'expired',
        label: 'Expiradas',
        value: props.stats.expired,
        icon: 'CircleSlash',
        tone: 'border-slate-200 bg-slate-100 text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-400 dark:text-slate-300',
    },
]);

const toggleStatus = (key: string) => {
    status.value = status.value === key ? '' : key;
};

const waLink = (entry: EntryRow) =>
    `https://wa.me/${entry.wa_phone}?text=${encodeURIComponent(entry.wa_text ?? '')}`;

// ── Avisar a mano (reintento o segundo toque) ──
const notifying = ref<number | null>(null);

async function notifyNow(entry: EntryRow) {
    notifying.value = entry.id;
    try {
        const { data } = await axios.post(
            `/api/waitlist-entries/${entry.id}/notify`,
        );
        replaceRow(data.entry);
        toast.success('Aviso enviado', data.message);
    } catch (e: any) {
        if (e.response?.data?.entry) replaceRow(e.response.data.entry);
        toast.error(
            'El aviso no salió',
            e.response?.data?.message ??
                'No se pudo enviar por ningún canal. Escríbele por WhatsApp a mano.',
        );
    } finally {
        notifying.value = null;
    }
}

// ── Convertir: ligar la reserva que salió de la espera ──
const converting = ref<EntryRow | null>(null);
const candidates = ref<CandidateRow[]>([]);
const loadingCandidates = ref(false);
const chosenReservation = ref<number | null>(null);
const savingConvert = ref(false);

async function openConvert(entry: EntryRow) {
    converting.value = entry;
    candidates.value = [];
    chosenReservation.value = entry.reservation_id;
    loadingCandidates.value = true;
    try {
        const { data } = await axios.get(
            `/api/waitlist-entries/${entry.id}/candidates`,
        );
        candidates.value = data.reservations;
    } catch {
        candidates.value = [];
    } finally {
        loadingCandidates.value = false;
    }
}

async function confirmConvert() {
    if (!converting.value) return;
    savingConvert.value = true;
    try {
        const { data } = await axios.patch(
            `/api/waitlist-entries/${converting.value.id}/convert`,
            { reservation_id: chosenReservation.value },
        );
        replaceRow(data.entry);
        toast.success(
            'Marcada como convertida',
            data.entry.reservation_code
                ? `Ligada a la reserva ${data.entry.reservation_code}.`
                : `${data.entry.guest_name} ya tiene su reserva.`,
        );
        converting.value = null;
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    } finally {
        savingConvert.value = false;
    }
}

// ── Eliminar ──
const deleting = ref<EntryRow | null>(null);

async function destroy() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/waitlist-entries/${deleting.value.id}`);
        rows.value = rows.value.filter((e) => e.id !== deleting.value!.id);
        toast.success('Entrada eliminada');
        deleting.value = null;
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    }
}
</script>

<template>
    <RazeLayout title="Lista de espera">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="BellRing" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Lista de espera</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Interesados que no encontraron disponibilidad en el
                            wizard y dejaron su contacto. Cuando una cancelación
                            libera sus fechas se les avisa solo, por WhatsApp o
                            correo; aquí queda constancia de por dónde salió.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Contadores: cada uno filtra la lista por su estado -->
            <div class="mt-5 grid grid-cols-12 gap-5">
                <button
                    v-for="tile in tiles"
                    :key="tile.key"
                    type="button"
                    class="col-span-6 text-left xl:col-span-3"
                    :title="`Ver solo ${tile.label.toLowerCase()}`"
                    @click="toggleStatus(tile.key)"
                >
                    <div
                        class="box box--stacked flex h-full items-center gap-3.5 p-4 sm:p-5"
                        :class="
                            status === tile.key
                                ? 'border border-primary/60'
                                : 'border border-transparent'
                        "
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border"
                            :class="tile.tone"
                        >
                            <Lucide :icon="tile.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-xs text-slate-500">
                                {{ tile.label }}
                            </div>
                            <div class="mt-0.5 text-lg font-medium">
                                {{ tile.value }}
                            </div>
                        </div>
                    </div>
                </button>
            </div>

            <!-- Avisos que no salieron: lo único que exige acción hoy. -->
            <div
                v-if="stats.failed"
                class="box box--stacked mt-5 flex items-start gap-3 border-l-4 border-l-pending p-4"
            >
                <Lucide
                    icon="TriangleAlert"
                    class="mt-0.5 h-5 w-5 shrink-0 text-pending"
                />
                <div class="text-xs">
                    <p class="text-sm font-medium">
                        {{ stats.failed }} aviso(s) no salieron por ningún canal
                    </p>
                    <p class="mt-0.5 text-slate-500">
                        Esas personas siguen en espera y no se enteraron. Revisa
                        el canal de WhatsApp en Ajustes / Métodos de pago y el
                        correo del hotel, o escríbeles a mano con el botón de
                        WhatsApp de cada renglón.
                    </p>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <!-- Filtros -->
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div class="relative w-full sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="text"
                            placeholder="Buscar por nombre, teléfono o correo…"
                            class="pl-9"
                        />
                    </div>
                    <button
                        v-if="status"
                        type="button"
                        class="flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                        @click="status = ''"
                    >
                        <Lucide icon="X" class="h-3.5 w-3.5" />
                        Quitar filtro
                    </button>
                    <span class="ml-auto text-xs text-slate-500">
                        {{ entries.total }} en total
                    </span>
                </div>

                <template v-if="rows.length">
                    <!-- Móvil: tarjetas apiladas (patrón rooms/Index.vue) -->
                    <div class="space-y-2.5 p-4 sm:hidden">
                        <div
                            v-for="entry in rows"
                            :key="`card-${entry.id}`"
                            class="rounded-lg border border-slate-200/70 bg-white p-3.5 dark:border-darkmode-400 dark:bg-darkmode-600"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <div class="min-w-0 truncate text-sm font-medium">
                                    {{ entry.guest_name }}
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        statusClass[entry.status] ??
                                        'bg-slate-100 text-slate-500'
                                    "
                                >
                                    {{ entry.status_label }}
                                </span>
                            </div>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                            >
                                <span
                                    >{{ entry.starts_at }} →
                                    {{ entry.ends_at }}</span
                                >
                                <span>{{
                                    entry.room_type ?? 'Cualquier tipo'
                                }}</span>
                            </div>
                            <div
                                class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                            >
                                <span v-if="entry.guest_phone">{{
                                    entry.guest_phone
                                }}</span>
                                <span v-if="entry.guest_email">{{
                                    entry.guest_email
                                }}</span>
                            </div>

                            <!-- Bitácora del aviso -->
                            <div
                                class="mt-2.5 space-y-1 border-t border-dashed border-slate-200/70 pt-2.5 text-xs dark:border-darkmode-400"
                            >
                                <p
                                    v-if="entry.notified_at"
                                    class="text-slate-500"
                                >
                                    Avisado {{ entry.notified_at }}
                                    <template v-if="entry.notified_channel"
                                        >por
                                        {{ entry.notified_channel }}</template
                                    >
                                </p>
                                <a
                                    v-if="entry.inbox_url"
                                    :href="entry.inbox_url"
                                    class="flex items-center gap-1 text-primary"
                                >
                                    <Lucide
                                        icon="MessagesSquare"
                                        class="h-3.5 w-3.5"
                                    />
                                    Ver la conversación
                                </a>
                                <p
                                    v-else-if="!entry.notified_at && !entry.notify_failed_at"
                                    class="text-slate-400"
                                >
                                    Todavía no se le avisa
                                </p>
                                <p v-if="entry.notify_failed_at" class="text-pending">
                                    No salió el {{ entry.notify_failed_at }}.
                                    {{ entry.notify_error }}
                                </p>
                                <p
                                    v-if="entry.notify_attempts > 1"
                                    class="text-slate-400"
                                >
                                    {{ entry.notify_attempts }} intentos
                                </p>
                                <p
                                    v-if="entry.reservation_code"
                                    class="text-success"
                                >
                                    Reserva {{ entry.reservation_code }}
                                </p>
                            </div>

                            <div
                                v-if="canManage"
                                class="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-slate-200/70 pt-2.5 dark:border-darkmode-400"
                            >
                                <Button
                                    v-if="entry.status !== 'converted'"
                                    variant="outline-primary"
                                    class="h-8 gap-1.5 px-2.5 text-xs"
                                    :disabled="notifying === entry.id"
                                    @click="notifyNow(entry)"
                                >
                                    <Lucide
                                        :icon="
                                            entry.notified_at
                                                ? 'RotateCw'
                                                : 'Send'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        notifying === entry.id
                                            ? 'Enviando…'
                                            : entry.notified_at
                                              ? 'Volver a avisar'
                                              : 'Avisar ahora'
                                    }}
                                </Button>
                                <a
                                    v-if="entry.wa_phone"
                                    :href="waLink(entry)"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex h-8 items-center gap-1.5 rounded-md border border-success/30 px-2.5 text-xs text-success"
                                >
                                    <Lucide
                                        icon="MessageCircle"
                                        class="h-3.5 w-3.5"
                                    />
                                    WhatsApp
                                </a>
                                <button
                                    v-if="entry.status !== 'converted'"
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-success dark:border-darkmode-400"
                                    title="Marcar convertida (ya reservó)"
                                    @click="openConvert(entry)"
                                >
                                    <Lucide icon="UserCheck" class="h-4 w-4" />
                                </button>
                                <button
                                    type="button"
                                    class="ml-auto flex h-8 w-8 items-center justify-center rounded-full border border-slate-200/70 text-danger dark:border-darkmode-400"
                                    title="Eliminar"
                                    @click="deleting = entry"
                                >
                                    <Lucide icon="Trash2" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Escritorio: tabla -->
                    <div
                        class="hidden overflow-auto p-4 sm:block lg:overflow-visible"
                    >
                        <Table>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th class="whitespace-nowrap"
                                        >Interesado</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Fechas</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Tipo</Table.Th
                                    >
                                    <Table.Th class="whitespace-nowrap"
                                        >Aviso</Table.Th
                                    >
                                    <Table.Th
                                        class="text-right whitespace-nowrap"
                                        >Acciones</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="entry in rows" :key="entry.id">
                                    <Table.Td>
                                        <div class="text-sm font-medium">
                                            {{ entry.guest_name }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            <span v-if="entry.guest_phone">{{
                                                entry.guest_phone
                                            }}</span>
                                            <span
                                                v-if="
                                                    entry.guest_phone &&
                                                    entry.guest_email
                                                "
                                            >
                                                ·
                                            </span>
                                            <span v-if="entry.guest_email">{{
                                                entry.guest_email
                                            }}</span>
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            Se anotó {{ entry.created_at }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td class="whitespace-nowrap">
                                        {{ entry.starts_at }} →
                                        {{ entry.ends_at }}
                                    </Table.Td>
                                    <Table.Td>{{
                                        entry.room_type ?? 'Cualquiera'
                                    }}</Table.Td>
                                    <Table.Td>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="
                                                statusClass[entry.status] ??
                                                'bg-slate-100 text-slate-500'
                                            "
                                        >
                                            {{ entry.status_label }}
                                        </span>
                                        <div
                                            v-if="entry.notified_at"
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            Avisado {{ entry.notified_at }}
                                            <template
                                                v-if="entry.notified_channel"
                                            >
                                                por
                                                {{ entry.notified_channel }}
                                            </template>
                                        </div>
                                        <a
                                            v-if="entry.inbox_url"
                                            :href="entry.inbox_url"
                                            class="mt-0.5 flex items-center gap-1 text-xs text-primary hover:underline"
                                        >
                                            <Lucide
                                                icon="MessagesSquare"
                                                class="h-3.5 w-3.5"
                                            />
                                            Ver la conversación
                                        </a>
                                        <div
                                            v-else-if="!entry.notified_at && !entry.notify_failed_at"
                                            class="mt-1 text-xs text-slate-400"
                                        >
                                            Todavía no se le avisa
                                        </div>
                                        <div
                                            v-if="entry.notify_failed_at"
                                            class="mt-1 flex items-start gap-1 text-xs text-pending"
                                        >
                                            <Lucide
                                                icon="TriangleAlert"
                                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                            />
                                            <span>
                                                No salió el
                                                {{ entry.notify_failed_at }}.
                                                {{ entry.notify_error }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="entry.notify_attempts > 1"
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{ entry.notify_attempts }} intentos
                                        </div>
                                        <div
                                            v-if="entry.reservation_code"
                                            class="mt-1 flex items-center gap-1 text-xs text-success"
                                        >
                                            <Lucide
                                                icon="Link2"
                                                class="h-3.5 w-3.5"
                                            />
                                            Reserva
                                            {{ entry.reservation_code }}
                                        </div>
                                    </Table.Td>
                                    <Table.Td>
                                        <div
                                            v-if="canManage"
                                            class="flex items-center justify-end gap-3"
                                        >
                                            <a
                                                v-if="
                                                    entry.status !== 'converted'
                                                "
                                                href="#"
                                                class="flex items-center text-primary"
                                                :title="
                                                    entry.notified_at
                                                        ? 'Volver a avisar por WhatsApp y correo'
                                                        : 'Avisar ahora por WhatsApp y correo'
                                                "
                                                @click.prevent="
                                                    notifying !== entry.id &&
                                                    notifyNow(entry)
                                                "
                                            >
                                                <Lucide
                                                    :icon="
                                                        notifying === entry.id
                                                            ? 'Clock'
                                                            : entry.notified_at
                                                              ? 'RotateCw'
                                                              : 'Send'
                                                    "
                                                    class="h-4 w-4"
                                                />
                                            </a>
                                            <a
                                                v-if="entry.wa_phone"
                                                :href="waLink(entry)"
                                                target="_blank"
                                                rel="noopener"
                                                class="flex items-center text-success"
                                                title="Escribirle a mano por WhatsApp (mismo texto)"
                                            >
                                                <Lucide
                                                    icon="MessageCircle"
                                                    class="h-4 w-4"
                                                />
                                            </a>
                                            <a
                                                v-if="
                                                    entry.status !== 'converted'
                                                "
                                                href="#"
                                                class="flex items-center text-success"
                                                title="Marcar convertida (ya reservó)"
                                                @click.prevent="
                                                    openConvert(entry)
                                                "
                                            >
                                                <Lucide
                                                    icon="UserCheck"
                                                    class="h-4 w-4"
                                                />
                                            </a>
                                            <a
                                                href="#"
                                                class="flex items-center text-danger"
                                                title="Eliminar"
                                                @click.prevent="deleting = entry"
                                            >
                                                <Lucide
                                                    icon="Trash2"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </a>
                                        </div>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                    </div>

                    <!-- Paginación -->
                    <div
                        v-if="entries.links.length > 3"
                        class="flex flex-wrap justify-center gap-1 px-4 pb-4"
                    >
                        <template v-for="(link, i) in entries.links" :key="i">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-state
                                class="rounded-md px-3 py-1.5 text-sm"
                                :class="
                                    link.active
                                        ? 'bg-primary text-white'
                                        : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400'
                                "
                            >
                                <span v-html="link.label" />
                            </Link>
                            <span
                                v-else
                                class="px-3 py-1.5 text-sm text-slate-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </template>
                <div
                    v-else
                    class="flex flex-col items-center gap-2.5 px-5 py-10 text-center"
                >
                    <Lucide icon="BellRing" class="h-8 w-8 text-slate-300" />
                    <div>
                        <p class="text-sm font-medium text-slate-600">
                            {{
                                filters.q || filters.status
                                    ? 'Nada coincide con la búsqueda'
                                    : 'Nadie está en espera por ahora'
                            }}
                        </p>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{
                                filters.q || filters.status
                                    ? 'Prueba con otro nombre o quita el filtro de arriba.'
                                    : 'Cuando el wizard no tenga disponibilidad, ofrecerá al huésped dejar su contacto y aparecerá aquí.'
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Marcar convertida: ligar la reserva que salió de la espera -->
        <Dialog :open="converting !== null" @close="converting = null">
            <Dialog.Panel v-if="converting">
                <div class="flex max-h-[85vh] flex-col">
                    <div class="flex items-start gap-3.5 p-5 pb-3">
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="UserCheck" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Marcar convertida
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Liga la reserva que salió de esta espera para
                                que quede comprobado cuánto recuperó el módulo.
                                {{ converting.guest_name }},
                                {{ converting.starts_at }} →
                                {{ converting.ends_at }}.
                            </p>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto px-5 pb-3">
                        <p
                            v-if="loadingCandidates"
                            class="py-6 text-center text-sm text-slate-500"
                        >
                            Buscando reservas que puedan ser de esta persona…
                        </p>
                        <template v-else>
                            <p
                                v-if="!candidates.length"
                                class="rounded-lg border border-dashed border-slate-200/70 p-4 text-center text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                No hay reservas que coincidan con su contacto ni
                                con sus fechas. Puedes marcarla convertida sin
                                ligar reserva.
                            </p>
                            <div v-else class="space-y-2">
                                <button
                                    v-for="candidate in candidates"
                                    :key="candidate.id"
                                    type="button"
                                    class="flex w-full items-start gap-3 rounded-lg border p-3 text-left"
                                    :class="
                                        chosenReservation === candidate.id
                                            ? 'border-success bg-success/5'
                                            : 'border-slate-200/70 dark:border-darkmode-400'
                                    "
                                    @click="chosenReservation = candidate.id"
                                >
                                    <Lucide
                                        :icon="
                                            chosenReservation === candidate.id
                                                ? 'CircleCheckBig'
                                                : 'Circle'
                                        "
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                        :class="
                                            chosenReservation === candidate.id
                                                ? 'text-success'
                                                : 'text-slate-300'
                                        "
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{ candidate.guest_name ?? 'Sin nombre' }}
                                            <span
                                                v-if="candidate.code"
                                                class="text-xs font-normal text-slate-500"
                                                >· {{ candidate.code }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ candidate.dates }} ·
                                            {{
                                                candidate.room_type ??
                                                'Sin tipo'
                                            }}
                                            <template v-if="candidate.room"
                                                >· Habitación
                                                {{ candidate.room }}</template
                                            >
                                            · {{ candidate.status_label }}
                                        </div>
                                    </div>
                                </button>
                            </div>
                            <button
                                type="button"
                                class="mt-2 w-full rounded-lg border border-dashed p-2.5 text-center text-xs"
                                :class="
                                    chosenReservation === null
                                        ? 'border-primary text-primary'
                                        : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                                "
                                @click="chosenReservation = null"
                            >
                                Marcar convertida sin ligar reserva
                            </button>
                        </template>
                    </div>
                    <div
                        class="flex justify-end gap-2 border-t border-slate-200/60 p-5 dark:border-darkmode-400"
                    >
                        <Button
                            variant="outline-secondary"
                            class="h-10 text-xs"
                            @click="converting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="h-10 text-xs"
                            :disabled="savingConvert"
                            @click="confirmConvert"
                            >{{
                                savingConvert ? 'Guardando…' : 'Marcar convertida'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar eliminación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="TriangleAlert"
                        class="mx-auto mb-3 h-10 w-10 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar a "{{ deleting?.guest_name }}"?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Se borra su entrada de la lista de espera; ya no
                        recibirá avisos.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-10 text-xs"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-10 text-xs"
                            @click="destroy"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
