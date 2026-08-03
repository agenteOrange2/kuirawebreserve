<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ProspectRow {
    id: number;
    name: string;
    hotel_name: string;
    email: string;
    phone: string;
    rooms: number | null;
    plan_key: string | null;
    plan_label: string;
    message: string | null;
    status: ProspectStatus;
    notes: string | null;
    source: string;
    contacted_at: string | null;
    created_at: string | null;
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
}>();

const search = ref(props.filters.search);
const statusFilter = ref(props.filters.status);
const planFilter = ref(props.filters.plan);
const editing = ref<ProspectRow | null>(null);

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
</script>

<template>
    <RazeLayout title="Prospectos">
        <div
            class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
        >
            <div>
                <h1 class="text-lg font-medium group-[.mode--light]:text-white">
                    Prospectos de planes
                </h1>
                <p class="text-sm text-slate-500">
                    Solicitudes comerciales recibidas desde la landing page
                </p>
            </div>
            <div class="flex gap-2 md:ml-auto">
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
                    :href="route('admin.plans')"
                    variant="primary"
                >
                    <Lucide icon="Layers" class="mr-2 h-4 w-4" />
                    Planes
                </Button>
            </div>
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

        <div class="box box--stacked mt-5 p-4">
            <form
                class="grid gap-3 md:grid-cols-[1fr_190px_190px_auto]"
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
        </div>

        <div
            v-if="prospects.data.length"
            class="mt-5 hidden overflow-x-auto md:block"
        >
            <Table class="border-separate border-spacing-y-[10px]">
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th class="border-b-0">Prospecto</Table.Th>
                        <Table.Th class="border-b-0">Contacto</Table.Th>
                        <Table.Th class="border-b-0">Plan</Table.Th>
                        <Table.Th class="border-b-0">Estado</Table.Th>
                        <Table.Th class="border-b-0">Recibido</Table.Th>
                        <Table.Th class="border-b-0 text-right"
                            >Acciones</Table.Th
                        >
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    <Table.Tr
                        v-for="prospect in prospects.data"
                        :key="prospect.id"
                    >
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
                                    <div class="mt-0.5 text-xs text-slate-500">
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
                            <a
                                :href="`tel:${prospect.phone}`"
                                class="mt-1 block text-xs text-slate-500 hover:text-primary"
                                >{{ prospect.phone }}</a
                            >
                        </Table.Td>
                        <Table.Td :class="cellClass">
                            <span
                                class="rounded-md bg-primary/5 px-2 py-1 text-xs font-medium text-primary"
                                >{{ prospect.plan_label }}</span
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
                            <div class="text-sm">{{ prospect.created_at }}</div>
                            <div
                                class="mt-1 text-[11px] text-slate-400 capitalize"
                            >
                                {{ prospect.source }}
                            </div>
                        </Table.Td>
                        <Table.Td :class="`${cellClass} text-right`">
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
                        </Table.Td>
                    </Table.Tr>
                </Table.Tbody>
            </Table>
        </div>

        <div v-if="prospects.data.length" class="mt-5 grid gap-4 md:hidden">
            <button
                v-for="prospect in prospects.data"
                :key="prospect.id"
                class="box box--stacked p-5 text-left"
                @click="openProspect(prospect)"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ prospect.hotel_name }}</div>
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
                    class="mt-4 flex items-center justify-between border-t border-dashed border-slate-200 pt-3 text-xs text-slate-500"
                >
                    <span
                        >{{ prospect.plan_label }} ·
                        {{
                            prospect.rooms
                                ? `${prospect.rooms} hab.`
                                : 'Habitaciones N/D'
                        }}</span
                    >
                    <span>{{ prospect.created_at }}</span>
                </div>
            </button>
        </div>

        <div
            v-else
            class="box box--stacked mt-5 flex min-h-72 flex-col items-center justify-center p-8 text-center"
        >
            <span
                class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/5"
                ><Lucide icon="ContactRound" class="h-7 w-7 text-primary"
            /></span>
            <h2 class="mt-4 text-base font-medium">
                No hay prospectos con estos filtros
            </h2>
            <p class="mt-1 max-w-sm text-sm text-slate-500">
                Las solicitudes enviadas desde la landing aparecerán aquí para
                darles seguimiento.
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
            class="mt-5 flex flex-col items-center justify-between gap-3 sm:flex-row"
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

        <Dialog :open="editing !== null" size="lg" @close="editing = null">
            <Dialog.Panel>
                <div v-if="editing" class="p-6 sm:p-8">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <span
                                class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
                                >{{ initials(editing.name) }}</span
                            >
                            <div>
                                <Dialog.Title class="text-lg font-medium">{{
                                    editing.hotel_name
                                }}</Dialog.Title>
                                <p class="mt-0.5 text-sm text-slate-500">
                                    {{ editing.name }} · Plan
                                    {{ editing.plan_label }}
                                </p>
                            </div>
                        </div>
                        <button
                            class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                            @click="editing = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="mt-6 grid gap-3 sm:grid-cols-3">
                        <a
                            :href="`mailto:${editing.email}`"
                            class="rounded-xl border border-slate-200 p-3 transition hover:border-primary/30"
                            ><span
                                class="flex items-center gap-2 text-[11px] text-slate-400"
                                ><Lucide icon="Mail" class="h-3.5 w-3.5" />
                                Correo</span
                            ><span
                                class="mt-1.5 block truncate text-sm text-primary"
                                >{{ editing.email }}</span
                            ></a
                        >
                        <a
                            :href="`tel:${editing.phone}`"
                            class="rounded-xl border border-slate-200 p-3 transition hover:border-primary/30"
                            ><span
                                class="flex items-center gap-2 text-[11px] text-slate-400"
                                ><Lucide icon="Phone" class="h-3.5 w-3.5" />
                                Teléfono</span
                            ><span class="mt-1.5 block text-sm text-primary">{{
                                editing.phone
                            }}</span></a
                        >
                        <div class="rounded-xl border border-slate-200 p-3">
                            <span
                                class="flex items-center gap-2 text-[11px] text-slate-400"
                                ><Lucide icon="BedDouble" class="h-3.5 w-3.5" />
                                Tamaño</span
                            ><span class="mt-1.5 block text-sm">{{
                                editing.rooms
                                    ? `${editing.rooms} habitaciones`
                                    : 'No indicado'
                            }}</span>
                        </div>
                    </div>

                    <div
                        v-if="editing.message"
                        class="mt-5 rounded-xl bg-slate-50 p-4 dark:bg-darkmode-500"
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

                    <div
                        class="mt-6 border-t border-dashed border-slate-200 pt-6"
                    >
                        <div class="grid gap-5 sm:grid-cols-2">
                            <label
                                ><span class="mb-2 block text-sm font-medium"
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
                                class="rounded-xl bg-slate-50 p-3 text-xs text-slate-500 dark:bg-darkmode-500"
                            >
                                <div class="flex items-center gap-2">
                                    <Lucide
                                        icon="CalendarClock"
                                        class="h-4 w-4 text-primary"
                                    /><span
                                        >Recibido:
                                        {{ editing.created_at }}</span
                                    >
                                </div>
                                <div class="mt-2 flex items-center gap-2">
                                    <Lucide
                                        icon="PhoneCall"
                                        class="h-4 w-4 text-primary"
                                    /><span>{{
                                        editing.contacted_at
                                            ? `Primer contacto: ${editing.contacted_at}`
                                            : 'Aún sin contacto registrado'
                                    }}</span>
                                </div>
                            </div>
                        </div>
                        <label class="mt-5 block"
                            ><span class="mb-2 block text-sm font-medium"
                                >Notas de seguimiento</span
                            ><FormTextarea
                                v-model="form.notes"
                                rows="5"
                                placeholder="Acuerdos, próximo contacto, objeciones o información relevante..."
                            />
                            <p
                                v-if="form.errors.notes"
                                class="mt-1 text-xs text-danger"
                            >
                                {{ form.errors.notes }}
                            </p></label
                        >
                    </div>

                    <div
                        class="mt-6 flex justify-end gap-3 border-t border-slate-200 pt-5"
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
    </RazeLayout>
</template>
