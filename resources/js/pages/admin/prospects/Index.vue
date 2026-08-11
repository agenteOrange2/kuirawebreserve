<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormInput,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ProspectRow {
    id: number;
    name: string;
    hotel_name: string;
    email: string;
    phone: string;
    has_whatsapp: boolean;
    rooms: number | null;
    plan_key: string | null;
    plan_label: string | null;
    services_labels: string[];
    message: string | null;
    status: ProspectStatus;
    notes: string | null;
    source: string;
    contacted_at: string | null;
    created_at: string | null;
    docs_email_sent_at: string | null;
    docs_whatsapp_sent_at: string | null;
    wa_phone: string | null;
    wa_text: string | null;
}

type ProspectStatus = 'new' | 'contacted' | 'qualified' | 'won' | 'lost';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    prospects: {
        data: ProspectRow[];
        links: PaginationLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: { search: string; status: string; plan: string };
    stats: { total: number; new: number; qualified: number; won: number };
    plans: { key: string; label: string }[];
    registerUrl: string;
    documentsCount: number;
    mailConfigured: boolean;
    autoEmailEnabled: boolean;
    emailSettingsUrl: string;
}>();

const search = ref(props.filters.search);
const statusFilter = ref(props.filters.status);
const planFilter = ref(props.filters.plan);
const editing = ref<ProspectRow | null>(null);
const qrOpen = ref(false);
const qrDataUrl = ref('');
const sendingId = ref<number | null>(null);

const statusOptions: Array<{ value: ProspectStatus; label: string }> = [
    { value: 'new', label: 'Nuevo' },
    { value: 'contacted', label: 'Contactado' },
    { value: 'qualified', label: 'Calificado' },
    { value: 'won', label: 'Ganado' },
    { value: 'lost', label: 'Descartado' },
];

const statusMeta: Record<
    ProspectStatus,
    { label: string; class: string; dot: string }
> = {
    new: {
        label: 'Nuevo',
        class: 'bg-info/10 text-info',
        dot: 'bg-info',
    },
    contacted: {
        label: 'Contactado',
        class: 'bg-warning/10 text-warning',
        dot: 'bg-warning',
    },
    qualified: {
        label: 'Calificado',
        class: 'bg-primary/10 text-primary',
        dot: 'bg-primary',
    },
    won: {
        label: 'Ganado',
        class: 'bg-success/10 text-success',
        dot: 'bg-success',
    },
    lost: {
        label: 'Descartado',
        class: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
        dot: 'bg-slate-400',
    },
};

const form = useForm({
    status: 'new' as ProspectStatus,
    notes: '',
});

const cellClass =
    'box shadow-[5px_3px_5px_#00000005] first:border-l last:border-r first:rounded-l-[0.6rem] last:rounded-r-[0.6rem] rounded-l-none rounded-r-none border-x-0 dark:bg-darkmode-600';

// ── Selección múltiple para borrado en masa ──
const selectedIds = ref<number[]>([]);
const bulkDeleteOpen = ref(false);
const bulkDeleting = ref(false);

const allSelected = computed(
    () =>
        props.prospects.data.length > 0 &&
        props.prospects.data.every((p) => selectedIds.value.includes(p.id)),
);
const selectedRows = computed(() =>
    props.prospects.data.filter((p) => selectedIds.value.includes(p.id)),
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

function bulkDelete(): void {
    bulkDeleting.value = true;
    router.delete(route('admin.prospects.destroyBulk'), {
        data: { ids: selectedIds.value },
        preserveScroll: true,
        onSuccess: () => {
            toastFlash();
            selectedIds.value = [];
            bulkDeleteOpen.value = false;
        },
        onFinish: () => {
            bulkDeleting.value = false;
        },
    });
}

function applyFilters(): void {
    router.get(
        route('admin.prospects'),
        {
            search: search.value || undefined,
            status:
                statusFilter.value === 'all' ? undefined : statusFilter.value,
            plan: planFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function clearFilters(): void {
    search.value = '';
    statusFilter.value = 'all';
    planFilter.value = '';
    applyFilters();
}

function openProspect(prospect: ProspectRow): void {
    editing.value = prospect;
    form.clearErrors();
    form.status = prospect.status;
    form.notes = prospect.notes ?? '';
}

function saveProspect(): void {
    if (!editing.value) {
        return;
    }

    form.patch(route('admin.prospects.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

function initials(name: string): string {
    return (
        name
            .trim()
            .split(/\s+/)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join('') || '?'
    );
}

async function openQr(): Promise<void> {
    qrOpen.value = true;
    if (!qrDataUrl.value) {
        qrDataUrl.value = await QRCode.toDataURL(props.registerUrl, {
            width: 512,
            margin: 2,
        });
    }
}

function downloadQr(): void {
    if (!qrDataUrl.value) {
        return;
    }
    const link = document.createElement('a');
    link.href = qrDataUrl.value;
    link.download = 'qr-registro-prospectos.png';
    link.click();
}

const page = usePage();
const toasts = useToasts();

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

function whatsappHref(prospect: ProspectRow): string {
    return `https://wa.me/${prospect.wa_phone}?text=${encodeURIComponent(prospect.wa_text ?? '')}`;
}

function markWhatsappSent(prospect: ProspectRow): void {
    // El envío real ocurre en la pestaña de WhatsApp; aquí solo se sella.
    router.patch(
        route('admin.prospects.markWhatsapp', prospect.id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <RazeLayout title="Prospectos">
        <div
            class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
        >
            <div>
                <h1 class="text-lg font-medium group-[.mode--light]:text-white">
                    Prospectos
                </h1>
                <p class="text-sm text-slate-500">
                    Solicitudes recibidas desde la landing y el registro por QR
                </p>
            </div>
            <div class="flex flex-wrap gap-2 md:ml-auto">
                <Button
                    :as="Link"
                    :href="route('home')"
                    target="_blank"
                    variant="outline-secondary"
                    class="bg-white/80 dark:bg-darkmode-400/80"
                >
                    <Lucide icon="ExternalLink" class="mr-2 h-4 w-4" />
                    Ver landing
                </Button>
                <Button
                    :as="Link"
                    :href="route('admin.prospects.documents')"
                    variant="outline-secondary"
                    class="bg-white/80 dark:bg-darkmode-400/80"
                >
                    <Lucide icon="FileText" class="mr-2 h-4 w-4" />
                    Documentos
                    <span
                        class="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
                        >{{ documentsCount }}</span
                    >
                </Button>
                <Button variant="primary" @click="openQr">
                    <Lucide icon="QrCode" class="mr-2 h-4 w-4" />
                    QR de registro
                </Button>
            </div>
        </div>

        <div
            v-if="!mailConfigured"
            class="box box--stacked mt-5 flex flex-col gap-3 border-l-4 border-l-warning p-4 sm:flex-row sm:items-center"
        >
            <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                ><Lucide icon="TriangleAlert" class="h-5 w-5"
            /></span>
            <div class="flex-1">
                <div class="text-sm font-medium">
                    El correo de la plataforma no está configurado
                </div>
                <p class="mt-0.5 text-xs text-slate-500">
                    Los documentos no llegarán a los prospectos hasta configurar
                    el servidor SMTP y el remitente.
                </p>
            </div>
            <Button
                :as="Link"
                :href="emailSettingsUrl"
                variant="outline-secondary"
                class="shrink-0"
            >
                <Lucide icon="Mail" class="mr-2 h-4 w-4" />
                Configurar correo
            </Button>
        </div>
        <div
            v-else-if="!autoEmailEnabled"
            class="box box--stacked mt-5 flex flex-col gap-3 border-l-4 border-l-pending p-4 sm:flex-row sm:items-center"
        >
            <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                ><Lucide icon="Info" class="h-5 w-5"
            /></span>
            <div class="flex-1">
                <div class="text-sm font-medium">
                    El envío automático está apagado
                </div>
                <p class="mt-0.5 text-xs text-slate-500">
                    Los prospectos del registro por QR no reciben correo al
                    registrarse; usa el botón de envío de cada fila.
                </p>
            </div>
            <Button
                :as="Link"
                :href="emailSettingsUrl"
                variant="outline-secondary"
                class="shrink-0"
            >
                <Lucide icon="Mail" class="mr-2 h-4 w-4" />
                Ajustes de correo
            </Button>
        </div>

        <div class="mt-5 grid grid-cols-12 gap-5">
            <div
                v-for="card in [
                    {
                        label: 'Prospectos totales',
                        value: stats.total,
                        icon: 'ContactRound',
                        tone: 'primary',
                    },
                    {
                        label: 'Nuevos por atender',
                        value: stats.new,
                        icon: 'BellRing',
                        tone: 'info',
                    },
                    {
                        label: 'Calificados',
                        value: stats.qualified,
                        icon: 'BadgeCheck',
                        tone: 'warning',
                    },
                    {
                        label: 'Ventas ganadas',
                        value: stats.won,
                        icon: 'Trophy',
                        tone: 'success',
                    },
                ]"
                :key="card.label"
                class="col-span-12 sm:col-span-6 xl:col-span-3"
            >
                <div class="box box--stacked flex h-full items-center p-5">
                    <span
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border"
                        :class="{
                            'border-primary/10 bg-primary/10 text-primary':
                                card.tone === 'primary',
                            'border-info/10 bg-info/10 text-info':
                                card.tone === 'info',
                            'border-warning/10 bg-warning/10 text-warning':
                                card.tone === 'warning',
                            'border-success/10 bg-success/10 text-success':
                                card.tone === 'success',
                        }"
                    >
                        <Lucide :icon="card.icon as any" class="h-5 w-5" />
                    </span>
                    <div class="ml-4">
                        <div class="text-2xl font-medium">{{ card.value }}</div>
                        <div class="mt-0.5 text-xs text-slate-500">
                            {{ card.label }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bandeja: encabezado, filtros, tabla y paginación viven en la
             misma tarjeta para que se lean como un solo bloque. -->
        <div class="box box--stacked mt-5 p-5">
            <div class="flex flex-col gap-3 md:flex-row md:items-center">
                <div>
                    <h2 class="text-base font-medium">Bandeja de prospectos</h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        {{ prospects.total }} prospecto{{
                            prospects.total === 1 ? '' : 's'
                        }}
                        con los filtros actuales.
                    </p>
                </div>
                <div
                    v-if="selectedIds.length"
                    class="flex flex-wrap items-center gap-3 md:ml-auto"
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
                        class="rounded-[0.5rem] !px-3 !py-1.5 text-xs"
                        @click="bulkDeleteOpen = true"
                    >
                        <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                        Eliminar seleccionados
                    </Button>
                </div>
            </div>

            <form
                class="mt-4 grid gap-3 border-t border-dashed border-slate-200/80 pt-4 md:grid-cols-[1fr_190px_190px_auto] dark:border-darkmode-400"
                @submit.prevent="applyFilters"
            >
                <div class="relative">
                    <Lucide
                        icon="Search"
                        class="absolute inset-y-0 left-0 my-auto ml-3 h-4 w-4 text-slate-400"
                    />
                    <FormInput
                        v-model="search"
                        type="search"
                        class="pl-9"
                        placeholder="Buscar hotel, persona, correo o teléfono..."
                    />
                </div>
                <FormSelect v-model="statusFilter" @change="applyFilters">
                    <option value="all">Todos los estados</option>
                    <option
                        v-for="status in statusOptions"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </FormSelect>
                <FormSelect v-model="planFilter" @change="applyFilters">
                    <option value="">Todos los planes</option>
                    <option
                        v-for="plan in plans"
                        :key="plan.key"
                        :value="plan.key"
                    >
                        {{ plan.label }}
                    </option>
                </FormSelect>
                <div class="flex gap-2">
                    <Button type="submit" variant="primary">Buscar</Button>
                    <Button
                        v-if="search || statusFilter !== 'all' || planFilter"
                        type="button"
                        variant="outline-secondary"
                        @click="clearFilters"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </Button>
                </div>
            </form>

            <div
                v-if="prospects.data.length"
                class="mt-2 hidden overflow-auto md:block lg:overflow-visible"
            >
                <Table class="border-separate border-spacing-y-[10px]">
                    <Table.Thead>
                        <Table.Tr>
                            <Table.Th class="w-10 border-b-0 !bg-transparent">
                                <FormCheck.Input
                                    type="checkbox"
                                    :checked="allSelected"
                                    title="Seleccionar todos"
                                    @change="toggleAll"
                                />
                            </Table.Th>
                            <Table.Th class="border-b-0 !bg-transparent"
                                >Prospecto</Table.Th
                            >
                            <Table.Th class="border-b-0 !bg-transparent"
                                >Contacto</Table.Th
                            >
                            <Table.Th class="border-b-0 !bg-transparent"
                                >Interés</Table.Th
                            >
                            <Table.Th class="border-b-0 !bg-transparent"
                                >Estado</Table.Th
                            >
                            <Table.Th class="border-b-0 !bg-transparent"
                                >Documentos</Table.Th
                            >
                            <Table.Th class="border-b-0 !bg-transparent"
                                >Recibido</Table.Th
                            >
                            <Table.Th
                                class="border-b-0 !bg-transparent text-right"
                                >Acciones</Table.Th
                            >
                        </Table.Tr>
                    </Table.Thead>
                    <Table.Tbody>
                        <Table.Tr
                            v-for="prospect in prospects.data"
                            :key="prospect.id"
                        >
                            <Table.Td :class="cellClass" class="w-10">
                                <FormCheck.Input
                                    type="checkbox"
                                    :checked="selectedIds.includes(prospect.id)"
                                    @change="toggleRow(prospect.id)"
                                />
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                    >
                                        {{ initials(prospect.name) }}
                                    </span>
                                    <div class="min-w-0">
                                        <div
                                            class="font-medium text-slate-800 dark:text-slate-200"
                                        >
                                            {{ prospect.hotel_name }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ prospect.name }}
                                        </div>
                                    </div>
                                </div>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <a
                                    :href="`mailto:${prospect.email}`"
                                    class="block text-sm text-primary hover:underline"
                                    >{{ prospect.email }}</a
                                >
                                <div class="mt-1 flex items-center gap-1.5">
                                    <a
                                        :href="`tel:${prospect.phone}`"
                                        class="block text-xs text-slate-500 hover:text-primary"
                                        >{{ prospect.phone }}</a
                                    >
                                    <Lucide
                                        v-if="prospect.has_whatsapp"
                                        icon="MessageCircle"
                                        class="h-3.5 w-3.5 text-success"
                                        title="Cuenta con WhatsApp"
                                    />
                                </div>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <span
                                    v-if="prospect.plan_label"
                                    class="rounded-md bg-primary/5 px-2 py-1 text-xs font-medium text-primary"
                                    >{{ prospect.plan_label }}</span
                                >
                                <div
                                    v-else-if="prospect.services_labels.length"
                                    class="flex max-w-52 flex-wrap gap-1"
                                >
                                    <span
                                        v-for="label in prospect.services_labels"
                                        :key="label"
                                        class="rounded-md bg-primary/5 px-2 py-1 text-xs font-medium text-primary"
                                        >{{ label }}</span
                                    >
                                </div>
                                <span v-else class="text-xs text-slate-400"
                                    >Sin especificar</span
                                >
                                <div
                                    v-if="prospect.rooms"
                                    class="mt-1.5 text-xs text-slate-500"
                                >
                                    {{ prospect.rooms }} habitaciones
                                </div>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="statusMeta[prospect.status].class"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="statusMeta[prospect.status].dot"
                                    />
                                    {{ statusMeta[prospect.status].label }}
                                </span>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <div
                                    class="flex items-center gap-1.5 text-xs"
                                    :class="
                                        prospect.docs_email_sent_at
                                            ? 'text-success'
                                            : 'text-slate-400'
                                    "
                                >
                                    <Lucide icon="Mail" class="h-3.5 w-3.5" />
                                    {{
                                        prospect.docs_email_sent_at ??
                                        'Sin enviar'
                                    }}
                                </div>
                                <div
                                    class="mt-1 flex items-center gap-1.5 text-xs"
                                    :class="
                                        prospect.docs_whatsapp_sent_at
                                            ? 'text-success'
                                            : 'text-slate-400'
                                    "
                                >
                                    <Lucide
                                        icon="MessageCircle"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{
                                        prospect.docs_whatsapp_sent_at ??
                                        'Sin enviar'
                                    }}
                                </div>
                            </Table.Td>
                            <Table.Td :class="cellClass">
                                <div class="text-sm">
                                    {{ prospect.created_at }}
                                </div>
                                <div
                                    class="mt-1 text-[11px] text-slate-400 capitalize"
                                >
                                    {{ prospect.source }}
                                </div>
                            </Table.Td>
                            <Table.Td :class="`${cellClass} text-right`">
                                <div class="flex justify-end gap-1.5">
                                    <Button
                                        variant="outline-secondary"
                                        size="sm"
                                        :disabled="
                                            sendingId === prospect.id ||
                                            !mailConfigured
                                        "
                                        :title="
                                            !mailConfigured
                                                ? 'Configura el correo de plataforma para enviar'
                                                : prospect.docs_email_sent_at
                                                  ? 'Reenviar documentos por correo'
                                                  : 'Enviar documentos por correo'
                                        "
                                        @click="sendDocuments(prospect)"
                                    >
                                        <Lucide
                                            icon="Send"
                                            class="h-3.5 w-3.5"
                                        />
                                    </Button>
                                    <Button
                                        v-if="
                                            prospect.wa_phone &&
                                            prospect.wa_text
                                        "
                                        :as="'a'"
                                        :href="whatsappHref(prospect)"
                                        target="_blank"
                                        variant="outline-secondary"
                                        size="sm"
                                        title="Enviar documentos por WhatsApp"
                                        @click="markWhatsappSent(prospect)"
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="h-3.5 w-3.5"
                                        />
                                    </Button>
                                    <Button
                                        variant="outline-secondary"
                                        size="sm"
                                        @click="openProspect(prospect)"
                                    >
                                        Gestionar
                                        <Lucide
                                            icon="ArrowRight"
                                            class="ml-2 h-3.5 w-3.5"
                                        />
                                    </Button>
                                </div>
                            </Table.Td>
                        </Table.Tr>
                    </Table.Tbody>
                </Table>
            </div>

            <div v-if="prospects.data.length" class="mt-4 grid gap-3 md:hidden">
                <button
                    v-for="prospect in prospects.data"
                    :key="prospect.id"
                    class="rounded-xl border border-slate-200/80 p-4 text-left dark:border-darkmode-400"
                    @click="openProspect(prospect)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium">
                                {{ prospect.hotel_name }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ prospect.name }}
                            </div>
                        </div>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs font-medium"
                            :class="statusMeta[prospect.status].class"
                            >{{ statusMeta[prospect.status].label }}</span
                        >
                    </div>
                    <div
                        class="mt-4 flex items-center justify-between gap-3 border-t border-dashed border-slate-200 pt-3 text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        <span class="min-w-0 truncate">{{
                            prospect.plan_label ??
                            (prospect.services_labels.length
                                ? prospect.services_labels.join(', ')
                                : 'Sin especificar')
                        }}</span>
                        <span class="shrink-0">{{ prospect.created_at }}</span>
                    </div>
                </button>
            </div>

            <div
                v-else
                class="flex min-h-64 flex-col items-center justify-center py-10 text-center"
            >
                <span
                    class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/5"
                    ><Lucide icon="ContactRound" class="h-7 w-7 text-primary"
                /></span>
                <h3 class="mt-4 text-base font-medium">
                    No hay prospectos con estos filtros
                </h3>
                <p class="mt-1 max-w-sm text-sm text-slate-500">
                    Las solicitudes enviadas desde la landing aparecerán aquí
                    para darles seguimiento.
                </p>
                <Button
                    v-if="search || statusFilter !== 'all' || planFilter"
                    class="mt-5"
                    variant="outline-secondary"
                    @click="clearFilters"
                    >Limpiar filtros</Button
                >
            </div>

            <div
                v-if="prospects.data.length"
                class="mt-4 flex flex-col items-center justify-between gap-3 border-t border-dashed border-slate-200/80 pt-4 sm:flex-row dark:border-darkmode-400"
            >
                <p class="text-xs text-slate-500">
                    Mostrando {{ prospects.from }}–{{ prospects.to }} de
                    {{ prospects.total }}
                </p>
                <nav class="flex flex-wrap gap-1">
                    <template v-for="link in prospects.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="flex min-h-9 min-w-9 items-center justify-center rounded-lg border px-3 text-xs transition"
                            :class="
                                link.active
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-slate-200 bg-white text-slate-600 hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600'
                            "
                        >
                            <span v-html="link.label" />
                        </Link>
                    </template>
                </nav>
            </div>
        </div>

        <!-- Gestionar prospecto: header fijo, cuerpo scrolleable por
             secciones (contacto, interés, documentos, seguimiento) y
             footer fijo. -->
        <Dialog :open="editing !== null" size="lg" @close="editing = null">
            <Dialog.Panel>
                <div v-if="editing" class="flex max-h-[85vh] flex-col">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
                            >{{ initials(editing.name) }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <Dialog.Title
                                    class="truncate text-base font-medium"
                                    >{{ editing.hotel_name }}</Dialog.Title
                                >
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="statusMeta[editing.status].class"
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="statusMeta[editing.status].dot"
                                    />
                                    {{ statusMeta[editing.status].label }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-slate-500">
                                {{ editing.name }} · Recibido el
                                {{ editing.created_at }}
                                <span class="capitalize"
                                    >· {{ editing.source }}</span
                                >
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="editing = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="flex-1 space-y-6 overflow-y-auto px-6 py-5">
                        <section>
                            <h3
                                class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Contacto
                            </h3>
                            <div class="mt-2.5 grid gap-3 sm:grid-cols-3">
                                <a
                                    :href="`mailto:${editing.email}`"
                                    class="rounded-xl border border-slate-200/80 p-3 transition hover:border-primary/30 dark:border-darkmode-400"
                                    ><span
                                        class="flex items-center gap-2 text-[11px] text-slate-400"
                                        ><Lucide
                                            icon="Mail"
                                            class="h-3.5 w-3.5"
                                        />
                                        Correo</span
                                    ><span
                                        class="mt-1.5 block truncate text-sm text-primary"
                                        >{{ editing.email }}</span
                                    ></a
                                >
                                <a
                                    :href="`tel:${editing.phone}`"
                                    class="rounded-xl border border-slate-200/80 p-3 transition hover:border-primary/30 dark:border-darkmode-400"
                                    ><span
                                        class="flex items-center gap-2 text-[11px] text-slate-400"
                                        ><Lucide
                                            icon="Phone"
                                            class="h-3.5 w-3.5" />
                                        Teléfono
                                        <Lucide
                                            v-if="editing.has_whatsapp"
                                            icon="MessageCircle"
                                            class="h-3.5 w-3.5 text-success"
                                            title="Cuenta con WhatsApp"
                                    /></span>
                                    <span
                                        class="mt-1.5 block text-sm text-primary"
                                        >{{ editing.phone }}</span
                                    ></a
                                >
                                <div
                                    class="rounded-xl border border-slate-200/80 p-3 dark:border-darkmode-400"
                                >
                                    <span
                                        class="flex items-center gap-2 text-[11px] text-slate-400"
                                        ><Lucide
                                            icon="BedDouble"
                                            class="h-3.5 w-3.5"
                                        />
                                        Tamaño</span
                                    ><span class="mt-1.5 block text-sm">{{
                                        editing.rooms
                                            ? `${editing.rooms} habitaciones`
                                            : 'No indicado'
                                    }}</span>
                                </div>
                            </div>
                        </section>

                        <section>
                            <h3
                                class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Interés
                            </h3>
                            <div class="mt-2.5 flex flex-wrap gap-1.5">
                                <span
                                    v-if="editing.plan_label"
                                    class="rounded-md bg-primary/5 px-2 py-1 text-xs font-medium text-primary"
                                    >Plan {{ editing.plan_label }}</span
                                >
                                <span
                                    v-for="label in editing.services_labels"
                                    :key="label"
                                    class="rounded-md bg-primary/5 px-2 py-1 text-xs font-medium text-primary"
                                    >{{ label }}</span
                                >
                                <span
                                    v-if="
                                        !editing.plan_label &&
                                        !editing.services_labels.length
                                    "
                                    class="text-xs text-slate-400"
                                    >Sin especificar</span
                                >
                            </div>
                            <div
                                v-if="editing.message"
                                class="mt-3 rounded-xl bg-slate-50 p-4 dark:bg-darkmode-500"
                            >
                                <div
                                    class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Necesidad compartida
                                </div>
                                <p
                                    class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300"
                                >
                                    {{ editing.message }}
                                </p>
                            </div>
                        </section>

                        <section>
                            <h3
                                class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Documentos informativos
                            </h3>
                            <div class="mt-2.5 space-y-2">
                                <div
                                    class="flex flex-col gap-3 rounded-xl border border-slate-200/80 p-3.5 sm:flex-row sm:items-center dark:border-darkmode-400"
                                >
                                    <span
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                        :class="
                                            editing.docs_email_sent_at
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide icon="Mail" class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            Por correo
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs"
                                            :class="
                                                editing.docs_email_sent_at
                                                    ? 'text-success'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                editing.docs_email_sent_at
                                                    ? `Enviados el ${editing.docs_email_sent_at}`
                                                    : 'Sin enviar'
                                            }}
                                        </div>
                                    </div>
                                    <Link
                                        v-if="!mailConfigured"
                                        :href="emailSettingsUrl"
                                        class="text-xs font-medium text-primary hover:underline"
                                        >Configurar correo</Link
                                    >
                                    <Button
                                        v-else
                                        variant="outline-secondary"
                                        size="sm"
                                        :disabled="sendingId === editing.id"
                                        @click="sendDocuments(editing)"
                                    >
                                        <Lucide
                                            icon="Send"
                                            class="mr-2 h-3.5 w-3.5"
                                        />
                                        {{
                                            sendingId === editing.id
                                                ? 'Enviando...'
                                                : editing.docs_email_sent_at
                                                  ? 'Reenviar'
                                                  : 'Enviar'
                                        }}
                                    </Button>
                                </div>
                                <div
                                    class="flex flex-col gap-3 rounded-xl border border-slate-200/80 p-3.5 sm:flex-row sm:items-center dark:border-darkmode-400"
                                >
                                    <span
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                        :class="
                                            editing.docs_whatsapp_sent_at
                                                ? 'bg-success/10 text-success'
                                                : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                        "
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="h-4 w-4"
                                        />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            Por WhatsApp
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs"
                                            :class="
                                                editing.docs_whatsapp_sent_at
                                                    ? 'text-success'
                                                    : 'text-slate-400'
                                            "
                                        >
                                            {{
                                                editing.docs_whatsapp_sent_at
                                                    ? `Enviados el ${editing.docs_whatsapp_sent_at}`
                                                    : editing.wa_phone &&
                                                        editing.wa_text
                                                      ? 'Sin enviar'
                                                      : 'No disponible: sin WhatsApp o sin documentos de sus servicios'
                                            }}
                                        </div>
                                    </div>
                                    <Button
                                        v-if="
                                            editing.wa_phone && editing.wa_text
                                        "
                                        :as="'a'"
                                        :href="whatsappHref(editing)"
                                        target="_blank"
                                        variant="outline-secondary"
                                        size="sm"
                                        @click="markWhatsappSent(editing)"
                                    >
                                        <Lucide
                                            icon="MessageCircle"
                                            class="mr-2 h-3.5 w-3.5"
                                        />
                                        {{
                                            editing.docs_whatsapp_sent_at
                                                ? 'Reenviar'
                                                : 'Enviar'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </section>

                        <section>
                            <h3
                                class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Seguimiento
                            </h3>
                            <div class="mt-2.5 grid gap-4 sm:grid-cols-2">
                                <label
                                    ><span
                                        class="mb-2 block text-sm font-medium"
                                        >Estado comercial</span
                                    ><FormSelect v-model="form.status"
                                        ><option
                                            v-for="status in statusOptions"
                                            :key="status.value"
                                            :value="status.value"
                                        >
                                            {{ status.label }}
                                        </option></FormSelect
                                    >
                                    <p
                                        v-if="form.errors.status"
                                        class="mt-1 text-xs text-danger"
                                    >
                                        {{ form.errors.status }}
                                    </p></label
                                >
                                <div
                                    class="rounded-xl bg-slate-50 px-3.5 py-3 text-xs text-slate-500 dark:bg-darkmode-500"
                                >
                                    <div class="flex items-center gap-2">
                                        <Lucide
                                            icon="CalendarClock"
                                            class="h-4 w-4 shrink-0 text-primary"
                                        /><span
                                            >Recibido:
                                            {{ editing.created_at }}</span
                                        >
                                    </div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <Lucide
                                            icon="PhoneCall"
                                            class="h-4 w-4 shrink-0 text-primary"
                                        /><span>{{
                                            editing.contacted_at
                                                ? `Primer contacto: ${editing.contacted_at}`
                                                : 'Aún sin contacto registrado'
                                        }}</span>
                                    </div>
                                </div>
                            </div>
                            <label class="mt-4 block"
                                ><span class="mb-2 block text-sm font-medium"
                                    >Notas de seguimiento</span
                                ><FormTextarea
                                    v-model="form.notes"
                                    rows="4"
                                    placeholder="Acuerdos, próximo contacto, objeciones o información relevante..."
                                />
                                <p
                                    v-if="form.errors.notes"
                                    class="mt-1 text-xs text-danger"
                                >
                                    {{ form.errors.notes }}
                                </p></label
                            >
                        </section>
                    </div>

                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            variant="outline-secondary"
                            @click="editing = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            :disabled="form.processing"
                            @click="saveProspect"
                            ><Lucide icon="Save" class="mr-2 h-4 w-4" />{{
                                form.processing
                                    ? 'Guardando...'
                                    : 'Guardar seguimiento'
                            }}</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Confirmar borrado masivo -->
        <Dialog :open="bulkDeleteOpen" @close="bulkDeleteOpen = false">
            <Dialog.Panel>
                <div>
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                Eliminar {{ selectedRows.length }} prospecto{{
                                    selectedRows.length === 1 ? '' : 's'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se pierden sus notas y el historial de envíos.
                                Esta acción no se puede deshacer.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="bulkDeleteOpen = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="px-6 py-5">
                        <div
                            class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-dashed border-slate-300/70 p-2 text-sm dark:border-darkmode-400"
                        >
                            <div
                                v-for="row in selectedRows"
                                :key="row.id"
                                class="flex items-center justify-between gap-2 px-1"
                            >
                                <span class="min-w-0 truncate font-medium">{{
                                    row.hotel_name
                                }}</span>
                                <span class="shrink-0 text-xs text-slate-500">{{
                                    row.name
                                }}</span>
                            </div>
                        </div>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            variant="outline-secondary"
                            @click="bulkDeleteOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="bulkDeleting"
                            @click="bulkDelete"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{
                                bulkDeleting ? 'Eliminando...' : 'Sí, eliminar'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <Dialog :open="qrOpen" @close="qrOpen = false">
            <Dialog.Panel>
                <div class="p-6 text-center sm:p-8">
                    <div class="flex items-center justify-center gap-3">
                        <span
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                            ><Lucide icon="QrCode" class="h-5 w-5"
                        /></span>
                        <Dialog.Title class="text-lg font-medium"
                            >QR de registro</Dialog.Title
                        >
                    </div>
                    <p class="mt-3 text-sm text-slate-500">
                        Quien lo escanee llega al formulario público de
                        registro. Imprímelo o proyéctalo en la reunión.
                    </p>
                    <div class="mt-5 flex justify-center">
                        <img
                            v-if="qrDataUrl"
                            :src="qrDataUrl"
                            alt="QR del formulario de registro"
                            class="h-56 w-56 rounded-xl border border-slate-200 bg-white p-2"
                        />
                        <div
                            v-else
                            class="flex h-56 w-56 items-center justify-center rounded-xl border border-slate-200 text-xs text-slate-400"
                        >
                            Generando QR...
                        </div>
                    </div>
                    <a
                        :href="registerUrl"
                        target="_blank"
                        class="mt-4 block text-xs text-primary hover:underline"
                        >{{ registerUrl }}</a
                    >
                    <div class="mt-6 flex justify-center gap-3">
                        <Button
                            variant="outline-secondary"
                            @click="qrOpen = false"
                            >Cerrar</Button
                        >
                        <Button
                            variant="primary"
                            :disabled="!qrDataUrl"
                            @click="downloadQr"
                        >
                            <Lucide icon="Download" class="mr-2 h-4 w-4" />
                            Descargar PNG
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
