<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Historial completo de la campana: la campana solo enseña los últimos 15;
 * aquí se ve todo, se filtra y se borra (individual o en masa).
 */

interface NoticeRow {
    id: number;
    type: string;
    title: string;
    body: string | null;
    url: string | null;
    read: boolean;
    at: string | null;
    at_exact: string | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    notifications: {
        data: NoticeRow[];
        links: PaginationLink[];
        total: number;
    };
    unread: number;
    filters: { q: string; type: string; unread: boolean };
}>();

const toast = useToasts();

// Mismo lenguaje visual que la campana.
const meta: Record<string, { icon: Icon; tone: string; label: string }> = {
    message: {
        icon: 'MessageCircle',
        tone: 'bg-primary/10 text-primary',
        label: 'Mensaje',
    },
    reservation: {
        icon: 'CalendarCheck',
        tone: 'bg-info/10 text-info',
        label: 'Reserva',
    },
    payment: {
        icon: 'BadgeDollarSign',
        tone: 'bg-success/10 text-success',
        label: 'Pago',
    },
    social: {
        icon: 'Share2',
        tone: 'bg-warning/10 text-warning',
        label: 'Redes sociales',
    },
    incident: {
        icon: 'Wrench',
        tone: 'bg-danger/10 text-danger',
        label: 'Mantenimiento',
    },
};

const metaFor = (type: string) =>
    meta[type] ?? {
        icon: 'Bell' as Icon,
        tone: 'bg-slate-100 text-slate-500',
        label: 'Aviso',
    };

// ── Filtros (reactivos, con debounce) ──
const q = ref(props.filters.q);
const type = ref(props.filters.type);
const state = ref(props.filters.unread ? 'unread' : '');

let timer: ReturnType<typeof setTimeout> | null = null;
watch([q, type, state], () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.staff-notifications'),
            {
                q: q.value || undefined,
                type: type.value || undefined,
                unread: state.value === 'unread' ? 1 : undefined,
            },
            {
                preserveState: true,
                replace: true,
                only: ['notifications', 'unread', 'filters'],
            },
        );
    }, 350);
});

// ── Abrir y marcar como leído (igual que la campana) ──
function go(notice: NoticeRow) {
    if (!notice.read) {
        notice.read = true;
        axios.post(`/api/staff-notifications/${notice.id}/read`).catch(() => {
            /* si falla, se marcará al volver a entrar */
        });
    }

    if (notice.url) router.visit(notice.url);
}

const readingAll = ref(false);

async function readAll() {
    if (readingAll.value) return;
    readingAll.value = true;
    try {
        await axios.post('/api/staff-notifications/read-all');
        router.reload({ only: ['notifications', 'unread'] });
    } catch {
        toast.error('No se pudo marcar', 'Intenta de nuevo.');
    } finally {
        readingAll.value = false;
    }
}

// ── Eliminar (individual y en masa) ──
const selectedIds = ref<number[]>([]);
const deleteIds = ref<number[]>([]);
const deleteOpen = ref(false);
const deleteBusy = ref(false);

const allSelected = computed(
    () =>
        props.notifications.data.length > 0 &&
        props.notifications.data.every((n) => selectedIds.value.includes(n.id)),
);
const deleteRows = computed(() =>
    props.notifications.data.filter((n) => deleteIds.value.includes(n.id)),
);

function toggleRow(id: number) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}
function toggleAll() {
    selectedIds.value = allSelected.value
        ? []
        : props.notifications.data.map((n) => n.id);
}

function askDelete(ids: number[]) {
    deleteIds.value = ids;
    deleteOpen.value = true;
}

async function submitDelete() {
    if (deleteBusy.value || !deleteIds.value.length) return;
    deleteBusy.value = true;
    try {
        const { data } = await axios.delete('/api/staff-notifications', {
            data: { ids: deleteIds.value },
        });
        deleteOpen.value = false;
        selectedIds.value = selectedIds.value.filter(
            (id) => !deleteIds.value.includes(id),
        );
        deleteIds.value = [];
        toast.success(
            'Avisos eliminados',
            `${data.deleted} aviso(s) eliminado(s).`,
        );
        router.reload({ only: ['notifications', 'unread'] });
    } catch (error: any) {
        toast.error(
            'No se pudo eliminar',
            error.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Avisos">
        <div class="mt-2">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Avisos</h1>
                    <p class="text-sm text-slate-500">
                        {{ notifications.total }} en total ·
                        {{ unread > 0 ? `${unread} sin leer` : 'todo leído' }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="unread > 0"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                        :disabled="readingAll"
                        @click="readAll"
                    >
                        <Lucide
                            icon="CheckCheck"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        {{
                            readingAll
                                ? 'Un momento…'
                                : 'Marcar todo como leído'
                        }}
                    </Button>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <!-- Filtros -->
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="relative w-full sm:w-72">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="text"
                            placeholder="Buscar en los avisos…"
                            class="pl-9"
                        />
                    </div>
                    <FormSelect v-model="type" class="w-full sm:w-44">
                        <option value="">Todos los tipos</option>
                        <option value="message">Mensajes</option>
                        <option value="reservation">Reservas</option>
                        <option value="payment">Pagos</option>
                        <option value="social">Redes sociales</option>
                        <option value="incident">Mantenimiento</option>
                    </FormSelect>
                    <FormSelect v-model="state" class="w-full sm:w-40">
                        <option value="">Todos</option>
                        <option value="unread">Sin leer</option>
                    </FormSelect>
                    <template v-if="selectedIds.length">
                        <span class="ml-auto text-xs text-slate-500"
                            >{{ selectedIds.length }} seleccionado(s)</span
                        >
                        <button
                            type="button"
                            class="text-xs font-medium text-primary hover:underline"
                            @click="selectedIds = []"
                        >
                            Quitar selección
                        </button>
                        <Button
                            variant="danger"
                            class="rounded-[0.5rem] !px-3 !py-1.5 text-xs"
                            @click="askDelete(selectedIds)"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar seleccionados
                        </Button>
                    </template>
                </div>

                <!-- Lista -->
                <div v-if="notifications.data.length">
                    <div
                        class="flex items-center gap-3 border-b border-slate-200/60 px-5 py-2.5 dark:border-darkmode-400"
                    >
                        <FormCheck.Input
                            type="checkbox"
                            :checked="allSelected"
                            title="Seleccionar esta página"
                            @change="toggleAll"
                        />
                        <span class="text-xs text-slate-400"
                            >Seleccionar esta página</span
                        >
                    </div>
                    <div
                        class="divide-y divide-slate-100 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="n in notifications.data"
                            :key="n.id"
                            class="flex items-start gap-3 px-5 py-3.5 transition hover:bg-slate-50 dark:hover:bg-darkmode-600"
                            :class="n.read ? 'opacity-60' : ''"
                        >
                            <FormCheck.Input
                                type="checkbox"
                                class="mt-2.5"
                                :checked="selectedIds.includes(n.id)"
                                @change="toggleRow(n.id)"
                            />
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 items-start gap-3 text-left"
                                :class="n.url ? '' : 'cursor-default'"
                                @click="go(n)"
                            >
                                <span
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                    :class="metaFor(n.type).tone"
                                >
                                    <Lucide
                                        :icon="metaFor(n.type).icon"
                                        class="h-4 w-4"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{ n.title }}</span
                                        >
                                        <span
                                            v-if="!n.read"
                                            class="h-2 w-2 shrink-0 rounded-full bg-primary"
                                        />
                                    </span>
                                    <span
                                        v-if="n.body"
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >{{ n.body }}</span
                                    >
                                    <span
                                        class="mt-1 block text-[11px] text-slate-400"
                                        >{{ n.at_exact }}
                                        <template v-if="n.at">
                                            · {{ n.at }}</template
                                        ></span
                                    >
                                </span>
                            </button>
                            <button
                                type="button"
                                title="Eliminar este aviso"
                                class="mt-1.5 shrink-0 rounded-md p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                @click="askDelete([n.id])"
                            >
                                <Lucide icon="Trash2" class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-else
                    class="flex flex-col items-center gap-3 px-5 py-16 text-center"
                >
                    <span
                        class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Bell" class="h-6 w-6" />
                    </span>
                    <p class="text-sm text-slate-500">
                        {{
                            filters.q || filters.type || filters.unread
                                ? 'Nada coincide con la búsqueda.'
                                : 'Sin avisos por ahora. Aquí llegan los mensajes nuevos, las reservas que entran solas y los comprobantes por verificar.'
                        }}
                    </p>
                </div>

                <!-- Paginación -->
                <div
                    v-if="notifications.links.length > 3"
                    class="flex flex-wrap justify-center gap-1 border-t border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <template v-for="(link, i) in notifications.links" :key="i">
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
            </div>
        </div>

        <!-- Confirmación de borrado -->
        <Dialog :open="deleteOpen" @close="deleteOpen = false">
            <Dialog.Panel>
                <div class="flex max-h-[85vh] flex-col">
                    <div class="flex items-start gap-3.5 p-6 pb-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                ¿Eliminar {{ deleteRows.length }} aviso(s)?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Los avisos que son para todo el staff
                                desaparecen para todos. Esta acción no se puede
                                deshacer.
                            </p>
                        </div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto px-6">
                        <div
                            class="rounded-lg border border-dashed border-slate-300/70 dark:border-darkmode-400"
                        >
                            <div
                                v-for="n in deleteRows"
                                :key="n.id"
                                class="flex items-center justify-between gap-3 border-b border-dashed border-slate-200/80 px-3.5 py-2.5 text-sm last:border-0 dark:border-darkmode-400"
                            >
                                <span class="min-w-0 truncate font-medium">{{
                                    n.title
                                }}</span>
                                <span class="shrink-0 text-xs text-slate-500">{{
                                    metaFor(n.type).label
                                }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 p-5">
                        <Button
                            variant="outline-secondary"
                            class="rounded-[0.5rem]"
                            :disabled="deleteBusy"
                            @click="deleteOpen = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            variant="danger"
                            class="rounded-[0.5rem]"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ deleteBusy ? 'Eliminando…' : 'Eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
