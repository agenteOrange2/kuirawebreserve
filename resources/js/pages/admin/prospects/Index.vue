<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import ProspectManageModal from './ProspectManageModal.vue';
import RegisterQrModal from './RegisterQrModal.vue';
import type { ProspectRow, ProspectStatus } from './types';
import { initials, statusMeta, statusOptions, whatsappHref } from './types';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Filters {
    search: string;
    status: string;
    plan: string;
    source: string;
    docs: string;
}

const props = defineProps<{
    prospects: {
        data: ProspectRow[];
        links: PaginationLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Filters;
    stats: {
        total: number;
        new: number;
        contacted: number;
        qualified: number;
        won: number;
        lost: number;
        conversion: number;
        docs_pending: number;
        this_week: number;
    };
    plans: { key: string; label: string }[];
    sources: { key: string; label: string }[];
    registerUrl: string;
    documentsCount: number;
    mailConfigured: boolean;
    autoEmailEnabled: boolean;
    emailSettingsUrl: string;
}>();

const page = usePage();
const toasts = useToasts();

const rowAction =
    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition';
const headerButton =
    'h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600';

// ── Avisos ──
function toastFlash(): void {
    const flash = page.props.flash as
        | { success?: string | null; error?: string | null }
        | undefined;
    if (flash?.success) {
        toasts.success(flash.success);
    } else if (flash?.error) {
        toasts.error(flash.error);
    }
}

function toastErrors(errors: Record<string, string>): void {
    const first = Object.values(errors)[0];
    toasts.error(first ?? 'No se pudo guardar.');
}

// ── Filtros (en servidor) ──
const filters = ref<Filters>({ ...props.filters });

const filtersActive = computed(
    () =>
        !!filters.value.search ||
        filters.value.status !== 'all' ||
        !!filters.value.plan ||
        !!filters.value.source ||
        !!filters.value.docs,
);

function queryParams(): Record<string, string> {
    const f = filters.value;
    const params: Record<string, string> = {};
    if (f.search.trim()) params.search = f.search.trim();
    if (f.status && f.status !== 'all') params.status = f.status;
    if (f.plan) params.plan = f.plan;
    if (f.source) params.source = f.source;
    if (f.docs) params.docs = f.docs;
    return params;
}

// Lo último que se pidió al servidor: evita que el buscador repita la
// consulta cuando "Limpiar" o un atajo ya la mandaron.
let appliedSearch = props.filters.search;

function applyFilters(): void {
    appliedSearch = filters.value.search.trim();
    router.get(route('admin.prospects'), queryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => filters.value.search,
    (value) => {
        clearTimeout(searchTimer);
        if (value.trim() === appliedSearch) {
            return;
        }
        searchTimer = setTimeout(applyFilters, 350);
    },
);
onBeforeUnmount(() => clearTimeout(searchTimer));

function clearFilters(): void {
    clearTimeout(searchTimer);
    filters.value = {
        search: '',
        status: 'all',
        plan: '',
        source: '',
        docs: '',
    };
    applyFilters();
}

// Las cifras de arriba son atajos: un clic filtra la bandeja.
type Shortcut = 'new' | 'open' | 'won' | 'docs';

function isShortcutActive(key: Shortcut): boolean {
    const f = filters.value;
    return key === 'docs'
        ? f.docs === 'pending' && f.status === 'all'
        : f.status === key && !f.docs;
}

function applyShortcut(key: Shortcut): void {
    clearTimeout(searchTimer);
    const active = isShortcutActive(key);
    filters.value = {
        ...filters.value,
        status: active || key === 'docs' ? 'all' : key,
        docs: !active && key === 'docs' ? 'pending' : '',
    };
    applyFilters();
}

const exportUrl = computed(() =>
    route('admin.prospects.export', queryParams()),
);

// ── Selección múltiple ──
const selectedIds = ref<number[]>([]);

const allSelected = computed(
    () =>
        props.prospects.data.length > 0 &&
        props.prospects.data.every((p) => selectedIds.value.includes(p.id)),
);

// Al cambiar de página o filtros la selección se recorta a lo visible:
// nunca se borra algo que ya no está en pantalla.
watch(
    () => props.prospects.data,
    (rows) => {
        const visible = new Set(rows.map((row) => row.id));
        selectedIds.value = selectedIds.value.filter((id) => visible.has(id));
    },
);

function toggleRow(id: number): void {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((x) => x !== id)
        : [...selectedIds.value, id];
}

function toggleAll(): void {
    selectedIds.value = allSelected.value
        ? []
        : props.prospects.data.map((p) => p.id);
}

// ── Borrado (uno o varios, mismo diálogo) ──
const deleteTargets = ref<ProspectRow[]>([]);
const deleting = ref(false);

function askDelete(rows: ProspectRow[]): void {
    editingId.value = null;
    deleteTargets.value = rows;
}

function confirmDelete(): void {
    deleting.value = true;
    const ids = deleteTargets.value.map((row) => row.id);
    router.delete(route('admin.prospects.destroyBulk'), {
        data: { ids },
        preserveScroll: true,
        onSuccess: () => {
            toastFlash();
            selectedIds.value = selectedIds.value.filter(
                (id) => !ids.includes(id),
            );
            deleteTargets.value = [];
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}

// ── Gestionar ──
// Se guarda el id y no la fila: así el modal refleja lo que traiga cada
// recarga (envío de documentos, WhatsApp) en vez de una copia vieja.
const editingId = ref<number | null>(null);
const editing = computed(
    () => props.prospects.data.find((p) => p.id === editingId.value) ?? null,
);

function onSaved(): void {
    toastFlash();
    editingId.value = null;
}

// ── Acciones de renglón ──
const sendingId = ref<number | null>(null);
const qrOpen = ref(false);

function sendDocuments(prospect: ProspectRow): void {
    sendingId.value = prospect.id;
    router.post(
        route('admin.prospects.sendDocuments', prospect.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toastFlash(),
            onFinish: () => {
                sendingId.value = null;
            },
        },
    );
}

function markWhatsappSent(prospect: ProspectRow): void {
    // El envío real ocurre en la pestaña de WhatsApp; aquí solo se sella.
    router.patch(
        route('admin.prospects.markWhatsapp', prospect.id),
        {},
        { preserveScroll: true, onSuccess: () => toastFlash() },
    );
}

function changeStatus(prospect: ProspectRow, status: ProspectStatus): void {
    if (prospect.status === status) {
        return;
    }
    router.patch(
        route('admin.prospects.update', prospect.id),
        { status },
        {
            preserveScroll: true,
            onSuccess: () => toastFlash(),
            onError: toastErrors,
        },
    );
}

function sendTitle(prospect: ProspectRow): string {
    if (!props.mailConfigured) {
        return 'Configura el correo de plataforma para enviar';
    }
    if (!prospect.docs_available) {
        return 'No hay documentos cargados para sus servicios';
    }
    return prospect.docs_email_sent_at
        ? `Reenviar documentos por correo (enviados ${prospect.docs_email_sent_at})`
        : 'Enviar documentos por correo';
}

function interestOf(prospect: ProspectRow): string[] {
    return prospect.plan_label
        ? [prospect.plan_label]
        : prospect.services_labels;
}

function pageLabel(label: string): 'prev' | 'next' | string {
    if (label.includes('Previous') || label.includes('&laquo;')) return 'prev';
    if (label.includes('Next') || label.includes('&raquo;')) return 'next';
    return label;
}

const statusCount = (status: ProspectStatus) => props.stats[status];
</script>

<template>
    <RazeLayout title="Prospectos">
        <div class="mt-2">
            <!-- Encabezado -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ContactRound" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Prospectos</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Solicitudes de la landing y del QR, con su
                            seguimiento.
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:items-center md:gap-2"
                >
                    <Button
                        as="a"
                        :href="route('home')"
                        target="_blank"
                        rel="noopener"
                        variant="outline-secondary"
                        :class="headerButton"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Ver landing
                    </Button>
                    <Button
                        :as="Link"
                        :href="route('admin.prospects.documents')"
                        variant="outline-secondary"
                        :class="headerButton"
                    >
                        <Lucide icon="FileText" class="mr-1.5 h-3.5 w-3.5" />
                        Documentos
                        <span
                            class="ml-1.5 rounded-full bg-primary/10 px-1.5 py-px text-[11px] font-medium text-primary"
                            >{{ documentsCount }}</span
                        >
                    </Button>
                    <Button
                        as="a"
                        :href="exportUrl"
                        variant="outline-secondary"
                        :class="headerButton"
                        :title="
                            filtersActive
                                ? 'Descarga lo que muestran los filtros actuales'
                                : 'Descarga todos los prospectos'
                        "
                    >
                        <Lucide icon="FileDown" class="mr-1.5 h-3.5 w-3.5" />
                        Exportar CSV
                    </Button>
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        @click="qrOpen = true"
                    >
                        <Lucide icon="QrCode" class="mr-1.5 h-3.5 w-3.5" />
                        QR de registro
                    </Button>
                </div>
            </div>

            <!-- Aviso de correo -->
            <div
                v-if="!mailConfigured || !autoEmailEnabled"
                class="box box--stacked mt-4 flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center"
            >
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                    :class="
                        !mailConfigured
                            ? 'border-warning/10 bg-warning/10 text-warning'
                            : 'border-pending/10 bg-pending/10 text-pending'
                    "
                >
                    <Lucide
                        :icon="!mailConfigured ? 'TriangleAlert' : 'Info'"
                        class="h-4 w-4"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium">
                        {{
                            !mailConfigured
                                ? 'El correo de la plataforma no está configurado'
                                : 'El envío automático está apagado'
                        }}
                    </div>
                    <p class="text-xs text-slate-500">
                        {{
                            !mailConfigured
                                ? 'Los documentos no llegarán a los prospectos hasta configurar el servidor SMTP y el remitente.'
                                : 'Quien se registra por QR no recibe correo; usa el botón de envío de cada renglón.'
                        }}
                    </p>
                </div>
                <Button
                    :as="Link"
                    :href="emailSettingsUrl"
                    variant="outline-secondary"
                    class="h-8 shrink-0 rounded-[0.5rem] text-xs"
                >
                    <Lucide icon="Mail" class="mr-1.5 h-3.5 w-3.5" />
                    {{
                        !mailConfigured
                            ? 'Configurar correo'
                            : 'Ajustes de correo'
                    }}
                </Button>
            </div>

            <!-- Cifras: cada una filtra la bandeja -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <button
                    v-for="card in [
                        {
                            key: 'new' as Shortcut,
                            value: stats.new,
                            label: 'Nuevos por atender',
                            hint: `${stats.this_week} llegaron esta semana`,
                            icon: 'BellRing',
                            tone: 'border-info/10 bg-info/10 text-info',
                            ring: 'ring-info/40',
                        },
                        {
                            key: 'open' as Shortcut,
                            value: stats.contacted + stats.qualified,
                            label: 'En seguimiento',
                            hint: `${stats.contacted} contactados · ${stats.qualified} calificados`,
                            icon: 'PhoneCall',
                            tone: 'border-warning/10 bg-warning/10 text-warning',
                            ring: 'ring-warning/40',
                        },
                        {
                            key: 'won' as Shortcut,
                            value: stats.won,
                            label: 'Ventas ganadas',
                            hint: `Conversión ${stats.conversion}% de ${stats.total}`,
                            icon: 'Trophy',
                            tone: 'border-success/10 bg-success/10 text-success',
                            ring: 'ring-success/40',
                        },
                        {
                            key: 'docs' as Shortcut,
                            value: stats.docs_pending,
                            label: 'Sin documentos',
                            hint: 'Activos sin correo ni WhatsApp',
                            icon: 'FileText',
                            tone: 'border-pending/10 bg-pending/10 text-pending',
                            ring: 'ring-pending/40',
                        },
                    ]"
                    :key="card.key"
                    type="button"
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 text-left transition hover:border-slate-300 xl:col-span-3"
                    :class="
                        isShortcutActive(card.key) ? ['ring-2', card.ring] : ''
                    "
                    :title="
                        isShortcutActive(card.key)
                            ? 'Quitar este filtro'
                            : 'Ver solo estos en la bandeja'
                    "
                    @click="applyShortcut(card.key)"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                        :class="card.tone"
                    >
                        <Lucide :icon="card.icon as any" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">{{ card.value }}</div>
                        <div
                            class="text-xs leading-tight text-slate-500 sm:truncate"
                        >
                            {{ card.label }}
                        </div>
                        <div
                            class="hidden truncate text-[11px] text-slate-400 sm:block"
                        >
                            {{ card.hint }}
                        </div>
                    </div>
                </button>
            </div>

            <!-- Bandeja -->
            <div class="box box--stacked mt-4 overflow-hidden">
                <div
                    class="flex flex-col gap-2 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 lg:flex-row lg:flex-wrap lg:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative lg:w-64">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                        />
                        <FormInput
                            v-model="filters.search"
                            type="search"
                            class="h-9 pl-9 text-xs"
                            placeholder="Hotel, persona, correo o teléfono"
                            @keydown.enter.prevent="applyFilters"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:flex">
                        <FormSelect
                            v-model="filters.status"
                            class="h-9 text-xs lg:w-52"
                            @change="applyFilters"
                        >
                            <option value="all">
                                Todos los estados ({{ stats.total }})
                            </option>
                            <option value="open">
                                En seguimiento ({{
                                    stats.contacted + stats.qualified
                                }})
                            </option>
                            <option
                                v-for="status in statusOptions"
                                :key="status.value"
                                :value="status.value"
                            >
                                {{ status.label }} ({{
                                    statusCount(status.value)
                                }})
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="filters.plan"
                            class="h-9 text-xs lg:w-40"
                            @change="applyFilters"
                        >
                            <option value="">Todos los planes</option>
                            <option
                                v-for="plan in plans"
                                :key="plan.key"
                                :value="plan.key"
                            >
                                {{ plan.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="filters.source"
                            class="h-9 text-xs lg:w-36"
                            @change="applyFilters"
                        >
                            <option value="">Todo origen</option>
                            <option
                                v-for="source in sources"
                                :key="source.key"
                                :value="source.key"
                            >
                                {{ source.label }}
                            </option>
                        </FormSelect>
                        <FormSelect
                            v-model="filters.docs"
                            class="h-9 text-xs lg:w-40"
                            @change="applyFilters"
                        >
                            <option value="">Documentos: todos</option>
                            <option value="pending">Sin documentos</option>
                            <option value="sent">Ya enviados</option>
                        </FormSelect>
                    </div>
                    <button
                        v-if="filtersActive"
                        type="button"
                        class="inline-flex h-9 items-center gap-1 self-start px-1 text-xs font-medium text-primary hover:underline lg:self-auto"
                        @click="clearFilters"
                    >
                        <Lucide icon="X" class="h-3.5 w-3.5" />
                        Limpiar
                    </button>

                    <div
                        v-if="selectedIds.length"
                        class="flex flex-wrap items-center gap-3 lg:ml-auto"
                    >
                        <span class="text-xs text-slate-500"
                            >{{ selectedIds.length }} seleccionado{{
                                selectedIds.length === 1 ? '' : 's'
                            }}</span
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
                            class="h-8 rounded-[0.5rem] text-xs"
                            @click="
                                askDelete(
                                    prospects.data.filter((p) =>
                                        selectedIds.includes(p.id),
                                    ),
                                )
                            "
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            Eliminar
                        </Button>
                    </div>
                    <span v-else class="text-xs text-slate-500 lg:ml-auto">
                        {{ prospects.total }}
                        {{ prospects.total === 1 ? 'prospecto' : 'prospectos' }}
                    </span>
                </div>

                <template v-if="prospects.data.length">
                    <!-- Rótulos de columna (escritorio) -->
                    <div
                        class="hidden items-center gap-4 border-b border-slate-200/60 px-5 py-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase lg:flex dark:border-darkmode-400"
                    >
                        <FormCheck.Input
                            type="checkbox"
                            :checked="allSelected"
                            title="Seleccionar la página"
                            @change="toggleAll"
                        />
                        <span class="min-w-0 flex-1">Prospecto</span>
                        <span class="w-52 shrink-0">Contacto</span>
                        <span class="w-52 shrink-0">Interés</span>
                        <span class="w-20 shrink-0">Docs</span>
                        <span class="w-[7.5rem] shrink-0 text-right"
                            >Acciones</span
                        >
                    </div>

                    <div
                        class="divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="prospect in prospects.data"
                            :key="prospect.id"
                            class="flex flex-col gap-3 px-4 py-3 transition hover:bg-slate-50/70 sm:px-5 lg:flex-row lg:items-center lg:gap-4 dark:hover:bg-darkmode-400/30"
                            :class="
                                selectedIds.includes(prospect.id)
                                    ? 'bg-primary/[0.03]'
                                    : ''
                            "
                        >
                            <!-- Identidad -->
                            <div class="flex min-w-0 flex-1 items-center gap-3">
                                <FormCheck.Input
                                    type="checkbox"
                                    :checked="selectedIds.includes(prospect.id)"
                                    @change="toggleRow(prospect.id)"
                                />
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary"
                                >
                                    {{ initials(prospect.name) }}
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="truncate text-left text-sm font-medium hover:text-primary"
                                            @click="editingId = prospect.id"
                                        >
                                            {{ prospect.hotel_name }}
                                        </button>
                                        <span
                                            class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="
                                                statusMeta[prospect.status]
                                                    .class
                                            "
                                        >
                                            <span
                                                class="h-1.5 w-1.5 rounded-full"
                                                :class="
                                                    statusMeta[prospect.status]
                                                        .dot
                                                "
                                            />
                                            {{
                                                statusMeta[prospect.status]
                                                    .label
                                            }}
                                        </span>
                                    </div>
                                    <div
                                        class="truncate text-xs leading-tight text-slate-500"
                                        :title="
                                            prospect.created_at ?? undefined
                                        "
                                    >
                                        {{ prospect.name }} ·
                                        {{ prospect.source_label }} ·
                                        {{ prospect.created_ago }}
                                    </div>
                                    <div
                                        v-if="prospect.notes"
                                        class="mt-0.5 flex items-center gap-1 truncate text-[11px] text-slate-400"
                                        :title="prospect.notes"
                                    >
                                        <Lucide
                                            icon="Pencil"
                                            class="h-3 w-3 shrink-0"
                                        />
                                        <span class="truncate">{{
                                            prospect.notes
                                        }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Contacto -->
                            <div
                                class="min-w-0 pl-[4.25rem] text-xs lg:w-52 lg:shrink-0 lg:pl-0"
                            >
                                <a
                                    :href="`mailto:${prospect.email}`"
                                    class="block truncate text-slate-600 hover:text-primary dark:text-slate-300"
                                    >{{ prospect.email }}</a
                                >
                                <div class="flex items-center gap-1.5">
                                    <a
                                        :href="`tel:${prospect.phone}`"
                                        class="text-slate-500 hover:text-primary"
                                        >{{ prospect.phone }}</a
                                    >
                                    <Lucide
                                        v-if="prospect.has_whatsapp"
                                        icon="MessageCircle"
                                        class="h-3.5 w-3.5 text-success"
                                        title="Tiene WhatsApp"
                                    />
                                </div>
                            </div>

                            <!-- Interés -->
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-1 pl-[4.25rem] lg:w-52 lg:shrink-0 lg:pl-0"
                            >
                                <!-- Un servicio a la vista y el resto en "+N":
                                     tres pastillas apiladas inflaban el renglón. -->
                                <span
                                    v-if="interestOf(prospect).length"
                                    class="max-w-[8.5rem] truncate rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    :title="interestOf(prospect).join(', ')"
                                    >{{ interestOf(prospect)[0] }}</span
                                >
                                <span
                                    v-if="interestOf(prospect).length > 1"
                                    class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    :title="interestOf(prospect).join(', ')"
                                    >+{{
                                        interestOf(prospect).length - 1
                                    }}</span
                                >
                                <span
                                    v-if="!interestOf(prospect).length"
                                    class="text-xs text-slate-400"
                                    >Sin especificar</span
                                >
                                <span
                                    v-if="prospect.rooms"
                                    class="text-[11px] text-slate-400"
                                    >· {{ prospect.rooms }} hab.</span
                                >
                            </div>

                            <!-- Docs + acciones -->
                            <div
                                class="flex items-center justify-between gap-3 pl-[4.25rem] lg:contents"
                            >
                                <div
                                    class="flex items-center gap-1.5 lg:w-20 lg:shrink-0"
                                >
                                    <span
                                        class="flex h-6 w-6 items-center justify-center rounded-full"
                                        :class="
                                            prospect.docs_email_sent_at
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                        "
                                        :title="
                                            prospect.docs_email_sent_at
                                                ? `Correo enviado ${prospect.docs_email_sent_at}`
                                                : 'Correo sin enviar'
                                        "
                                    >
                                        <Lucide icon="Mail" class="h-3 w-3" />
                                    </span>
                                    <span
                                        class="flex h-6 w-6 items-center justify-center rounded-full"
                                        :class="
                                            prospect.docs_whatsapp_sent_at
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                        "
                                        :title="
                                            prospect.docs_whatsapp_sent_at
                                                ? `WhatsApp enviado ${prospect.docs_whatsapp_sent_at}`
                                                : 'WhatsApp sin enviar'
                                        "
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="h-3 w-3"
                                        />
                                    </span>
                                </div>

                                <div
                                    class="flex items-center justify-end gap-1 lg:w-[7.5rem] lg:shrink-0"
                                >
                                    <button
                                        type="button"
                                        :class="rowAction"
                                        class="hover:bg-primary/10 hover:text-primary disabled:pointer-events-none disabled:opacity-40"
                                        :disabled="
                                            sendingId === prospect.id ||
                                            !mailConfigured ||
                                            !prospect.docs_available
                                        "
                                        :title="sendTitle(prospect)"
                                        @click="sendDocuments(prospect)"
                                    >
                                        <Lucide
                                            :icon="
                                                sendingId === prospect.id
                                                    ? 'LoaderCircle'
                                                    : 'Send'
                                            "
                                            class="h-4 w-4"
                                            :class="
                                                sendingId === prospect.id
                                                    ? 'animate-spin'
                                                    : ''
                                            "
                                        />
                                    </button>
                                    <a
                                        v-if="
                                            prospect.wa_phone &&
                                            prospect.wa_text
                                        "
                                        :href="
                                            whatsappHref(
                                                prospect.wa_phone,
                                                prospect.wa_text,
                                            )
                                        "
                                        target="_blank"
                                        rel="noopener"
                                        :class="rowAction"
                                        class="hover:bg-success/10 hover:text-success"
                                        title="Enviar documentos por WhatsApp"
                                        @click="markWhatsappSent(prospect)"
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="h-4 w-4"
                                        />
                                    </a>
                                    <a
                                        v-else-if="
                                            prospect.wa_phone &&
                                            prospect.wa_greeting
                                        "
                                        :href="
                                            whatsappHref(
                                                prospect.wa_phone,
                                                prospect.wa_greeting,
                                            )
                                        "
                                        target="_blank"
                                        rel="noopener"
                                        :class="rowAction"
                                        class="hover:bg-success/10 hover:text-success"
                                        title="Escribir por WhatsApp (sin documentos que mandar)"
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="h-4 w-4"
                                        />
                                    </a>
                                    <span
                                        v-else
                                        class="h-8 w-8"
                                        aria-hidden="true"
                                    />
                                    <Menu>
                                        <Menu.Button
                                            as="button"
                                            type="button"
                                            :class="rowAction"
                                            class="hover:bg-slate-100 dark:hover:bg-darkmode-400"
                                            title="Más acciones"
                                        >
                                            <Lucide
                                                icon="EllipsisVertical"
                                                class="h-4 w-4"
                                            />
                                        </Menu.Button>
                                        <Menu.Items class="w-56">
                                            <Menu.Item
                                                as="button"
                                                type="button"
                                                @click="editingId = prospect.id"
                                            >
                                                <Lucide
                                                    icon="Pencil"
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                Gestionar
                                            </Menu.Item>
                                            <Menu.Divider />
                                            <Menu.Header
                                                class="px-2 py-1 text-[11px] text-slate-400"
                                                >Cambiar estado</Menu.Header
                                            >
                                            <Menu.Item
                                                v-for="status in statusOptions"
                                                :key="status.value"
                                                as="button"
                                                type="button"
                                                :class="
                                                    prospect.status ===
                                                    status.value
                                                        ? 'font-medium text-primary'
                                                        : ''
                                                "
                                                @click="
                                                    changeStatus(
                                                        prospect,
                                                        status.value,
                                                    )
                                                "
                                            >
                                                <span
                                                    class="mr-2 h-1.5 w-1.5 rounded-full"
                                                    :class="
                                                        statusMeta[status.value]
                                                            .dot
                                                    "
                                                />
                                                {{ status.label }}
                                                <Lucide
                                                    v-if="
                                                        prospect.status ===
                                                        status.value
                                                    "
                                                    icon="Check"
                                                    class="ml-auto h-3.5 w-3.5"
                                                />
                                            </Menu.Item>
                                            <Menu.Divider />
                                            <Menu.Item
                                                as="button"
                                                type="button"
                                                class="text-danger"
                                                @click="askDelete([prospect])"
                                            >
                                                <Lucide
                                                    icon="Trash2"
                                                    class="mr-1.5 h-3.5 w-3.5"
                                                />
                                                Eliminar
                                            </Menu.Item>
                                        </Menu.Items>
                                    </Menu>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <div
                    v-else
                    class="flex flex-col items-center gap-2 px-6 py-12 text-center"
                >
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide
                            :icon="filtersActive ? 'SearchX' : 'ContactRound'"
                            class="h-4 w-4"
                        />
                    </div>
                    <p class="text-xs text-slate-500">
                        {{
                            filtersActive
                                ? 'Ningún prospecto coincide con los filtros.'
                                : 'Aún no llegan prospectos. Las solicitudes de la landing y del QR aparecerán aquí.'
                        }}
                    </p>
                    <Button
                        v-if="filtersActive"
                        variant="outline-secondary"
                        class="mt-1 h-9 rounded-[0.5rem] text-xs"
                        @click="clearFilters"
                        >Limpiar filtros</Button
                    >
                    <Button
                        v-else
                        variant="outline-primary"
                        class="mt-1 h-9 rounded-[0.5rem] text-xs"
                        @click="qrOpen = true"
                    >
                        <Lucide icon="QrCode" class="mr-1.5 h-3.5 w-3.5" />
                        Ver QR de registro
                    </Button>
                </div>

                <!-- Paginación -->
                <div
                    v-if="prospects.links.length > 3"
                    class="flex flex-wrap items-center gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <span class="text-xs text-slate-500">
                        {{ prospects.from }}–{{ prospects.to }} de
                        {{ prospects.total }}
                    </span>
                    <div class="ml-auto flex flex-wrap gap-1">
                        <component
                            :is="link.url ? Link : 'span'"
                            v-for="(link, i) in prospects.links"
                            :key="i"
                            :href="link.url ?? undefined"
                            preserve-state
                            preserve-scroll
                            class="flex items-center rounded-md px-2.5 py-1 text-xs"
                            :class="
                                link.active
                                    ? 'bg-primary text-white'
                                    : link.url
                                      ? 'text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400'
                                      : 'text-slate-300'
                            "
                        >
                            <Lucide
                                v-if="pageLabel(link.label) === 'prev'"
                                icon="ChevronLeft"
                                class="h-3.5 w-3.5"
                            />
                            <Lucide
                                v-else-if="pageLabel(link.label) === 'next'"
                                icon="ChevronRight"
                                class="h-3.5 w-3.5"
                            />
                            <span v-else v-html="link.label" />
                        </component>
                    </div>
                </div>
            </div>
        </div>

        <ProspectManageModal
            :prospect="editing"
            :mail-configured="mailConfigured"
            :email-settings-url="emailSettingsUrl"
            :sending="editing !== null && sendingId === editing.id"
            @close="editingId = null"
            @saved="onSaved"
            @send-documents="sendDocuments"
            @whatsapp-sent="markWhatsappSent"
            @delete="(p) => askDelete([p])"
        />

        <RegisterQrModal
            :open="qrOpen"
            :register-url="registerUrl"
            @close="qrOpen = false"
        />

        <!-- Confirmar borrado -->
        <Dialog :open="deleteTargets.length > 0" @close="deleteTargets = []">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <Dialog.Title
                                class="block border-0 p-0 text-base font-medium"
                            >
                                {{
                                    deleteTargets.length === 1
                                        ? `Eliminar a ${deleteTargets[0].hotel_name}`
                                        : `Eliminar ${deleteTargets.length} prospectos`
                                }}
                            </Dialog.Title>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se pierden sus notas y el registro de envíos. No
                                se puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="deleteTargets.length > 1"
                        class="mt-4 max-h-48 space-y-1 overflow-y-auto rounded-lg border border-dashed border-slate-300/70 p-2 dark:border-darkmode-400"
                    >
                        <div
                            v-for="row in deleteTargets"
                            :key="row.id"
                            class="flex items-center justify-between gap-2 px-1 text-xs"
                        >
                            <span class="min-w-0 truncate font-medium">{{
                                row.hotel_name
                            }}</span>
                            <span class="shrink-0 text-slate-500">{{
                                row.name
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="deleteTargets = []"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="deleting"
                            @click="confirmDelete"
                        >
                            <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                            {{ deleting ? 'Eliminando...' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
