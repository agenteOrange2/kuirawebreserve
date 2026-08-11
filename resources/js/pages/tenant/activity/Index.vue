<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ActivityRow {
    id: string;
    at: string | null;
    by: string;
    type: string;
    type_label: string;
    subject: string;
    message: string;
}
interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
interface TypeOption {
    value: string;
    label: string;
}

const props = defineProps<{
    property: { id: number; name: string };
    staff: { id: number; name: string }[];
    filters: { user: string; type: string; from: string; to: string };
    types: TypeOption[];
    activities: {
        data: ActivityRow[];
        links: PaginationLink[];
        total: number;
    };
}>();

const user = ref(props.filters.user);
const type = ref(props.filters.type);
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyFilters() {
    router.get(
        route('tenant.activity'),
        {
            user: user.value || undefined,
            type: type.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

function clearFilters() {
    user.value = '';
    type.value = '';
    from.value = '';
    to.value = '';
    applyFilters();
}

const typeBadge: Record<string, string> = {
    reservation: 'bg-primary/10 text-primary',
    stay: 'bg-info/10 text-info',
    room: 'bg-success/10 text-success',
    incident: 'bg-warning/10 text-warning',
    payment: 'bg-pending/10 text-pending',
    other: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

const typeIcon: Record<string, Icon> = {
    reservation: 'CalendarCheck',
    stay: 'DoorOpen',
    room: 'BedDouble',
    incident: 'Wrench',
    payment: 'Wallet',
    other: 'CircleDot',
};
</script>

<template>
    <RazeLayout title="Actividad">
        <div class="grid grid-cols-12 gap-x-6 gap-y-8">
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                        >
                            <Lucide
                                icon="History"
                                class="h-5 w-5 sm:h-7 sm:w-7"
                            />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-lg font-medium sm:text-xl">
                                Bitácora de actividad
                            </h1>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ property.name }} · quién hizo qué y cuándo:
                                reservas, pagos, check-in y check-out, semáforo
                                de habitaciones, incidencias y cupones
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div
                    class="box box--stacked mt-5 flex flex-wrap items-end gap-3 p-3"
                >
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Usuario</label
                        >
                        <div class="relative">
                            <Lucide
                                icon="User"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormSelect v-model="user" class="w-52 pl-9">
                                <option value="">Todos</option>
                                <option value="system">
                                    Sistema (automático o huésped web)
                                </option>
                                <option
                                    v-for="s in staff"
                                    :key="s.id"
                                    :value="String(s.id)"
                                >
                                    {{ s.name }}
                                </option>
                            </FormSelect>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Tipo</label
                        >
                        <div class="relative">
                            <Lucide
                                icon="Filter"
                                class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                            />
                            <FormSelect v-model="type" class="w-56 pl-9">
                                <option value="">Todo</option>
                                <option
                                    v-for="t in types"
                                    :key="t.value"
                                    :value="t.value"
                                >
                                    {{ t.label }}
                                </option>
                            </FormSelect>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Desde</label
                        >
                        <FormInput v-model="from" type="date" class="w-40" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-slate-500"
                            >Hasta</label
                        >
                        <FormInput v-model="to" type="date" class="w-40" />
                    </div>
                    <Button
                        variant="outline-primary"
                        class="rounded-[0.5rem] bg-white"
                        @click="applyFilters"
                    >
                        <Lucide icon="Search" class="mr-2 h-4 w-4" />
                        Filtrar
                    </Button>
                    <Button
                        v-if="filters.user || filters.type || filters.from || filters.to"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                        @click="clearFilters"
                    >
                        <Lucide icon="X" class="mr-2 h-4 w-4" />
                        Limpiar
                    </Button>
                    <div
                        class="ml-auto flex items-center gap-2 rounded-[0.5rem] border border-dashed border-slate-300/70 px-3 py-2 text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="ClipboardList"
                            class="h-4 w-4 text-primary"
                        />
                        {{ activities.total }} registros
                    </div>
                </div>
            </div>

            <!-- Línea de tiempo -->
            <div class="col-span-12">
                <div
                    class="box box--stacked overflow-auto p-5 lg:overflow-visible"
                >
                    <Table v-if="activities.data.length">
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th class="whitespace-nowrap"
                                    >Fecha y hora</Table.Th
                                >
                                <Table.Th>Usuario</Table.Th>
                                <Table.Th>Acción</Table.Th>
                                <Table.Th>Objeto</Table.Th>
                                <Table.Th>Tipo</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="a in activities.data" :key="a.id">
                                <Table.Td
                                    class="text-sm whitespace-nowrap text-slate-500"
                                    >{{ a.at }}</Table.Td
                                >
                                <Table.Td class="whitespace-nowrap">
                                    <span
                                        class="text-sm"
                                        :class="
                                            a.by === 'Sistema'
                                                ? 'text-slate-400'
                                                : 'font-medium'
                                        "
                                        >{{ a.by }}</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-sm">{{
                                    a.message
                                }}</Table.Td>
                                <Table.Td
                                    class="text-sm whitespace-nowrap text-slate-500"
                                    >{{ a.subject }}</Table.Td
                                >
                                <Table.Td>
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap"
                                        :class="
                                            typeBadge[a.type] ??
                                            typeBadge.other
                                        "
                                    >
                                        <Lucide
                                            :icon="
                                                typeIcon[a.type] ??
                                                typeIcon.other
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ a.type_label }}
                                    </span>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div
                        v-else
                        class="flex flex-col items-center gap-3 py-10 text-center text-slate-400"
                    >
                        <Lucide icon="History" class="h-8 w-8" />
                        <p class="text-sm">
                            No hay actividad con los filtros elegidos.
                        </p>
                    </div>

                    <!-- Paginación -->
                    <div
                        v-if="activities.links.length > 3"
                        class="mt-4 flex flex-wrap justify-center gap-1"
                    >
                        <template
                            v-for="(link, i) in activities.links"
                            :key="i"
                        >
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
        </div>
    </RazeLayout>
</template>
