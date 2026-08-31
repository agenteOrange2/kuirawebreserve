<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface StayRow {
    id: number;
    room: string | null;
    guest_name: string | null;
    num_people: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    rate_plan: string | null;
    check_in_at: string;
    planned_end_at: string;
    planned_end_at_iso: string;
    overdue: boolean;
    amount: string;
    channel: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    property: { id: number; name: string };
    stays: {
        data: StayRow[];
        links: PaginationLink[];
        total: number;
    };
    filters: { q: string };
    canManage: boolean;
}>();

const q = ref(props.filters.q);

let timer: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            route('tenant.reservations.in-house'),
            { q: q.value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['stays', 'filters'],
            },
        );
    }, 350);
});

const channelLabel: Record<string, string> = {
    front_desk: 'Mostrador',
    phone: 'Teléfono',
    web: 'Web',
    whatsapp: 'WhatsApp',
    walk_in: 'Llegó sin reserva',
    agent: 'Asistente IA',
};

// La salida se registra en /reservas: ahí vive el folio con sus consumos,
// el saldo y la fianza. Este botón manda allá con la estancia enfocada.
const checkOutHref = (s: StayRow) =>
    `${route('tenant.reservations')}?stay=${s.id}`;
</script>

<template>
    <RazeLayout title="Huéspedes alojados">
        <div class="mt-2">
            <!-- Encabezado estándar del panel: icono, título y subtítulo a la
                 izquierda, acciones a la derecha, todo dentro del box. -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="DoorOpen" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Huéspedes alojados ahora
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · {{ stays.total }}
                            {{
                                stays.total === 1
                                    ? 'habitación en uso'
                                    : 'habitaciones en uso'
                            }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        :as="Link"
                        :href="route('tenant.plano')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="LayoutGrid"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.3]"
                        />
                        Ver el plano
                    </Button>
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

            <div class="box box--stacked mt-5">
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
                            placeholder="Buscar por huésped, habitación o placa…"
                            class="pl-9"
                        />
                    </div>
                    <span
                        class="ml-auto hidden text-xs text-slate-500 lg:block"
                    >
                        Ordenadas por salida prevista: primero quien está por
                        irse.
                    </span>
                </div>

                <div class="overflow-auto p-4 lg:overflow-visible">
                    <Table v-if="stays.data.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Entrada</Table.Th>
                                <Table.Th>Salida prevista</Table.Th>
                                <Table.Th>Monto</Table.Th>
                                <Table.Th v-if="canManage" class="text-right"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="s in stays.data" :key="s.id">
                                <Table.Td class="font-medium">{{
                                    s.room ?? '—'
                                }}</Table.Td>
                                <Table.Td>
                                    {{ s.guest_name ?? 'Anónimo' }}
                                    <span class="block text-xs text-slate-500"
                                        >{{ s.num_people }}
                                        {{
                                            s.num_people === 1
                                                ? 'persona'
                                                : 'personas'
                                        }}
                                        ·
                                        {{
                                            channelLabel[s.channel] ?? s.channel
                                        }}</span
                                    >
                                    <span
                                        v-if="s.vehicle_plate"
                                        class="mt-0.5 inline-flex items-center gap-1 text-xs text-slate-500"
                                        :title="s.vehicle_desc ?? 'Vehículo'"
                                    >
                                        <Lucide icon="Car" class="h-3 w-3" />
                                        {{ s.vehicle_plate }}
                                    </span>
                                </Table.Td>
                                <Table.Td class="text-sm">{{
                                    s.check_in_at
                                }}</Table.Td>
                                <Table.Td class="text-sm">
                                    {{ s.planned_end_at }}
                                    <span
                                        v-if="s.overdue"
                                        class="ml-1 rounded-full bg-danger/10 px-1.5 text-xs text-danger"
                                        >vencida</span
                                    >
                                </Table.Td>
                                <Table.Td>${{ s.amount }}</Table.Td>
                                <Table.Td v-if="canManage">
                                    <div class="flex justify-end">
                                        <Button
                                            :as="Link"
                                            :href="checkOutHref(s)"
                                            variant="outline-primary"
                                            size="sm"
                                            class="rounded-[0.5rem] whitespace-nowrap"
                                        >
                                            <Lucide
                                                icon="LogOut"
                                                class="mr-1.5 h-4 w-4"
                                            />
                                            Registrar salida
                                        </Button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="py-10 text-center text-slate-500">
                        {{
                            filters.q
                                ? 'Nada coincide con la búsqueda.'
                                : 'Ninguna habitación en uso ahora mismo.'
                        }}
                    </div>

                    <!-- Paginación -->
                    <div
                        v-if="stays.links.length > 3"
                        class="mt-4 flex flex-wrap justify-center gap-1"
                    >
                        <template v-for="(link, i) in stays.links" :key="i">
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
