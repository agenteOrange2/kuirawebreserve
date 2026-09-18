<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import ReservationsNav from './ReservationsNav.vue';

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
        from: number | null;
        to: number | null;
    };
    /** El estado de la casa entera, no el de la página que se ve. */
    summary: {
        rooms: number;
        guests: number;
        departures_today: number;
        overdue: number;
    };
    filters: { q: string };
    canManage: boolean;
}>();

const money = (value: number | string) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value) || 0);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const tableHead =
    'text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const sectionLabel = tableHead;

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
    `${route('tenant.reservations.operation')}?stay=${s.id}`;
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
                            {{ property.name }} · quién está dentro y a qué hora
                            le toca salir
                        </p>
                    </div>
                </div>
                <div
                    class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:flex-wrap md:items-center md:gap-2"
                >
                    <Link
                        :href="route('tenant.reservations')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a reservas
                    </Link>
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
                </div>
            </div>

            <ReservationsNav current="in-house" />

            <!-- Cómo está la casa ahora mismo -->
            <div class="mt-4 flex items-center gap-2">
                <span :class="sectionLabel">La casa ahora</span>
                <span class="hidden text-[11px] text-slate-400 sm:inline">
                    Cuenta todas las estancias abiertas, no solo esta página
                </span>
            </div>
            <div class="mt-2 grid auto-rows-fr grid-cols-12 gap-4">
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-primary/10 bg-primary/10 text-primary',
                        ]"
                    >
                        <Lucide icon="BedDouble" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ summary.rooms }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            {{
                                summary.rooms === 1
                                    ? 'Habitación en uso'
                                    : 'Habitaciones en uso'
                            }}
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Estancias abiertas
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-info/10 bg-info/10 text-info',
                        ]"
                    >
                        <Lucide icon="Users" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ summary.guests }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            {{
                                summary.guests === 1
                                    ? 'Persona dentro'
                                    : 'Personas dentro'
                            }}
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Lo que declararon al entrar
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            'border-success/10 bg-success/10 text-success',
                        ]"
                    >
                        <Lucide icon="LogOut" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ summary.departures_today }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            Salidas hoy
                        </div>
                        <div class="truncate text-[11px] text-slate-400">
                            Según la salida prevista
                        </div>
                    </div>
                </div>
                <div
                    class="box box--stacked col-span-6 flex items-center gap-2.5 p-3 xl:col-span-3"
                >
                    <div
                        :class="[
                            sectionIcon,
                            summary.overdue
                                ? 'border-danger/10 bg-danger/10 text-danger'
                                : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
                        ]"
                    >
                        <Lucide icon="AlarmClock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">
                            {{ summary.overdue }}
                        </div>
                        <div class="truncate text-xs text-slate-500">
                            {{
                                summary.overdue === 1
                                    ? 'Salida vencida'
                                    : 'Salidas vencidas'
                            }}
                        </div>
                        <div
                            class="truncate text-[11px]"
                            :class="
                                summary.overdue
                                    ? 'text-danger'
                                    : 'text-slate-400'
                            "
                        >
                            {{
                                summary.overdue
                                    ? 'Ya pasó su hora de salida'
                                    : 'Nadie se pasó de la hora'
                            }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-4">
                <!-- Buscador en franja gris, pegado arriba de la lista -->
                <div
                    class="flex flex-col gap-2.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <div class="relative w-full min-w-0 sm:w-80">
                        <Lucide
                            icon="Search"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                        />
                        <FormInput
                            v-model="q"
                            type="search"
                            placeholder="Huésped, habitación o placa"
                            class="h-9 pl-9 text-xs"
                        />
                    </div>
                    <button
                        v-if="q"
                        type="button"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-500 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                        @click="q = ''"
                    >
                        <Lucide icon="X" class="h-3.5 w-3.5" />
                        Limpiar
                    </button>
                    <span
                        class="ml-auto hidden text-[11px] text-slate-400 lg:block"
                    >
                        Primero quien está por irse
                    </span>
                </div>

                <!-- Escritorio: tabla -->
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
                                <Table.Th :class="tableHead">Huésped</Table.Th>
                                <Table.Th :class="tableHead">Entró</Table.Th>
                                <Table.Th :class="tableHead">Sale</Table.Th>
                                <Table.Th :class="[tableHead, 'text-right']"
                                    >Monto</Table.Th
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
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                        >
                                            <Lucide
                                                icon="BedDouble"
                                                class="h-3.5 w-3.5"
                                            />
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium">
                                                {{ s.room ?? 'Sin asignar' }}
                                            </div>
                                            <div
                                                class="text-[11px] text-slate-500"
                                            >
                                                {{ s.rate_plan }}
                                            </div>
                                        </div>
                                    </div>
                                </Table.Td>
                                <Table.Td class="max-w-[20rem]">
                                    <div class="truncate text-sm font-medium">
                                        {{ s.guest_name ?? 'Anónimo' }}
                                    </div>
                                    <div
                                        class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500"
                                    >
                                        <span>
                                            {{ s.num_people }}
                                            {{
                                                s.num_people === 1
                                                    ? 'persona'
                                                    : 'personas'
                                            }}
                                        </span>
                                        <span
                                            class="text-slate-300 dark:text-darkmode-400"
                                            >·</span
                                        >
                                        <span>{{
                                            channelLabel[s.channel] ?? s.channel
                                        }}</span>
                                        <template v-if="s.vehicle_plate">
                                            <span
                                                class="text-slate-300 dark:text-darkmode-400"
                                                >·</span
                                            >
                                            <span
                                                class="inline-flex items-center gap-1"
                                                :title="
                                                    s.vehicle_desc ?? 'Vehículo'
                                                "
                                            >
                                                <Lucide
                                                    icon="Car"
                                                    class="h-3.5 w-3.5 stroke-[1.3]"
                                                />
                                                {{ s.vehicle_plate }}
                                            </span>
                                        </template>
                                    </div>
                                </Table.Td>
                                <Table.Td
                                    class="text-xs whitespace-nowrap tabular-nums"
                                >
                                    {{ s.check_in_at }}
                                </Table.Td>
                                <Table.Td class="whitespace-nowrap">
                                    <div class="text-xs tabular-nums">
                                        {{ s.planned_end_at }}
                                    </div>
                                    <span
                                        v-if="s.overdue"
                                        class="mt-1 inline-block rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >
                                        Salida vencida
                                    </span>
                                </Table.Td>
                                <Table.Td
                                    class="text-right text-sm font-medium whitespace-nowrap tabular-nums"
                                >
                                    {{ money(s.amount) }}
                                </Table.Td>
                                <Table.Td v-if="canManage">
                                    <div class="flex justify-end">
                                        <Button
                                            :as="Link"
                                            :href="checkOutHref(s)"
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] bg-white text-xs whitespace-nowrap"
                                        >
                                            <Lucide
                                                icon="LogOut"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Registrar salida
                                        </Button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                </div>

                <!-- Móvil y tablet: los mismos datos apilados -->
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
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >
                                        {{ s.room ?? 'Sin asignar' }}
                                    </span>
                                    <span
                                        v-if="s.overdue"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                    >
                                        Salida vencida
                                    </span>
                                </div>
                                <div
                                    class="mt-1.5 truncate text-sm font-medium"
                                >
                                    {{ s.guest_name ?? 'Anónimo' }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ s.num_people }}
                                    {{
                                        s.num_people === 1
                                            ? 'persona'
                                            : 'personas'
                                    }}
                                    · {{ channelLabel[s.channel] ?? s.channel }}
                                </div>
                            </div>
                            <div
                                class="shrink-0 text-sm font-medium tabular-nums"
                            >
                                {{ money(s.amount) }}
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
                                sale {{ s.planned_end_at }}
                            </span>
                            <span
                                v-if="s.vehicle_plate"
                                class="inline-flex items-center gap-1.5"
                                :title="s.vehicle_desc ?? 'Vehículo'"
                            >
                                <Lucide
                                    icon="Car"
                                    class="h-3.5 w-3.5 shrink-0 stroke-[1.3]"
                                />
                                {{ s.vehicle_plate }}
                            </span>
                        </div>

                        <div v-if="canManage" class="mt-2.5 flex justify-end">
                            <Button
                                :as="Link"
                                :href="checkOutHref(s)"
                                variant="outline-primary"
                                class="h-8 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                            >
                                <Lucide
                                    icon="LogOut"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Registrar salida
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- El vacío vive fuera del bloque de escritorio para que
                     también se vea en el celular. -->
                <div
                    v-if="!stays.data.length"
                    class="flex flex-col items-center gap-2 px-5 py-10 text-center"
                >
                    <Lucide
                        :icon="filters.q ? 'SearchX' : 'DoorOpen'"
                        class="h-8 w-8 text-slate-300"
                    />
                    <p class="text-sm font-medium text-slate-600">
                        {{
                            filters.q
                                ? 'Nada coincide con la búsqueda'
                                : 'Ninguna habitación en uso ahora mismo'
                        }}
                    </p>
                    <p class="text-xs text-slate-500">
                        {{
                            filters.q
                                ? 'Prueba con el número de habitación o solo el nombre.'
                                : 'Cuando registres una llegada, el huésped aparece aquí hasta que se le cobre la salida.'
                        }}
                    </p>
                </div>

                <!-- Paginación -->
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
            </div>
        </div>
    </RazeLayout>
</template>
