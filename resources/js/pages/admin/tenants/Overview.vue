<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface ReservationRow {
    code: string;
    guest: string | null;
    status: string;
    status_label: string;
    starts_at: string;
    total: number;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    ops: {
        owner: { name: string; email: string } | null;
        users: number;
        properties: number;
        rooms: number;
        guests: number;
        active_stays: number;
        reservations_month: number;
        revenue_month: number;
        conversations: number;
        conversations_pending: number;
        recent_reservations: ReservationRow[];
    };
    contract: {
        price_monthly: number;
        addons: number;
        ai_in_plan: boolean;
        max_properties: number | null;
        max_rooms: number | null;
        max_users: number | null;
    };
}>();

const money = (n: number) =>
    `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const statusTone: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-info/10 text-info',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-success/10 text-success',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-pending/10 text-pending',
};

// Cómo va el hotel: lo que se mira de un vistazo. Lo que se administra
// vive en las otras pestañas.
const kpis: Array<{
    icon: Icon;
    tone: string;
    value: string;
    cap: number | null;
    label: string;
    alert?: string;
}> = [
    {
        icon: 'Users',
        tone: 'border-primary/10 bg-primary/10 text-primary',
        value: String(props.ops.users),
        cap: props.contract.max_users,
        label: 'Usuarios',
    },
    {
        icon: 'BedDouble',
        tone: 'border-info/10 bg-info/10 text-info',
        value: String(props.ops.rooms),
        cap: props.contract.max_rooms,
        label: 'Habitaciones',
    },
    {
        icon: 'CalendarCheck',
        tone: 'border-success/10 bg-success/10 text-success',
        value: String(props.ops.reservations_month),
        cap: null,
        label: 'Reservas del mes',
    },
    {
        icon: 'Banknote',
        tone: 'border-warning/10 bg-warning/10 text-warning',
        value: money(props.ops.revenue_month),
        cap: null,
        label: 'Cobrado este mes',
    },
    {
        icon: 'DoorOpen',
        tone: 'border-pending/10 bg-pending/10 text-pending',
        value: String(props.ops.active_stays),
        cap: null,
        label: 'Estancias activas',
    },
    {
        icon: 'MessagesSquare',
        tone: 'border-dark/10 bg-dark/10 text-dark dark:text-slate-300',
        value: String(props.ops.conversations),
        cap: null,
        label: 'Conversaciones',
        alert:
            props.ops.conversations_pending > 0
                ? `${props.ops.conversations_pending} esperando persona`
                : undefined,
    },
];
</script>

<template>
    <RazeLayout :title="tenant.name">
        <TenantHeader :tenant="tenant" :plans="plans" active="overview" />

        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Cómo va la operación -->
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="col-span-6 sm:col-span-4 xl:col-span-2"
            >
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                            :class="kpi.tone"
                        >
                            <Lucide :icon="kpi.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-lg font-medium">
                                {{ kpi.value
                                }}<span
                                    v-if="kpi.cap"
                                    class="text-xs text-slate-400"
                                    >/{{ kpi.cap }}</span
                                >
                            </div>
                            <div class="text-xs leading-tight text-slate-500">
                                {{ kpi.label }}
                            </div>
                            <div
                                v-if="kpi.alert"
                                class="truncate text-xs font-medium text-warning"
                            >
                                {{ kpi.alert }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ficha corta: quién es y qué paga. El detalle, en su pestaña -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="FileText"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">El cliente</h2>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Plan</span>
                            <span
                                class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary"
                                >{{ tenant.plan_label }}</span
                            >
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Paga al mes</span>
                            <span class="font-medium text-primary">
                                ${{
                                    contract.price_monthly.toLocaleString(
                                        'es-MX',
                                    )
                                }}
                                MXN
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Propiedades</span>
                            <span class="font-medium"
                                >{{ ops.properties
                                }}<span class="text-slate-400">
                                    /
                                    {{
                                        contract.max_properties ?? 'sin límite'
                                    }}</span
                                ></span
                            >
                        </div>
                        <div
                            v-if="contract.addons"
                            class="flex items-center justify-between"
                        >
                            <span class="text-slate-500"
                                >Servicios adicionales</span
                            >
                            <span class="font-medium"
                                >{{ contract.addons }} contratado(s)</span
                            >
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500"
                                >Huéspedes en su CRM</span
                            >
                            <span class="font-medium">{{ ops.guests }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Asistente IA</span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="
                                    contract.ai_in_plan
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                "
                            >
                                {{
                                    contract.ai_in_plan
                                        ? 'En su plan'
                                        : 'Fuera del plan'
                                }}
                            </span>
                        </div>
                        <div
                            class="border-t border-dashed border-slate-300/70 pt-3"
                        >
                            <div
                                class="mb-1 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Dueño (owner)
                            </div>
                            <template v-if="ops.owner">
                                <div class="font-medium">
                                    {{ ops.owner.name }}
                                </div>
                                <div class="text-slate-500">
                                    {{ ops.owner.email }}
                                </div>
                            </template>
                            <div v-else class="text-slate-400">
                                Sin usuario propietario
                            </div>
                        </div>
                        <Link
                            :href="route('admin.tenants.plan', tenant.id)"
                            class="mt-auto flex items-center gap-1.5 border-t border-dashed border-slate-300/70 pt-3 text-xs text-primary"
                        >
                            Ver plan y facturación
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Reservas recientes: acompaña a la ficha para que la fila
                 quede pareja, en vez de dejar una tarjeta a medio llenar -->
            <div class="col-span-12 xl:col-span-8">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="CalendarClock"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Reservas recientes
                        </h2>
                    </div>
                    <div class="flex-1 overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="ops.recent_reservations.length" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Código</Table.Th>
                                    <Table.Th>Huésped</Table.Th>
                                    <Table.Th>Llegada</Table.Th>
                                    <Table.Th>Estado</Table.Th>
                                    <Table.Th class="text-right"
                                        >Total</Table.Th
                                    >
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr
                                    v-for="r in ops.recent_reservations"
                                    :key="r.code"
                                >
                                    <Table.Td class="font-mono text-xs">{{
                                        r.code
                                    }}</Table.Td>
                                    <Table.Td class="font-medium">{{
                                        r.guest ?? '—'
                                    }}</Table.Td>
                                    <Table.Td>{{ r.starts_at }}</Table.Td>
                                    <Table.Td>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs"
                                            :class="
                                                statusTone[r.status] ??
                                                'bg-slate-100 text-slate-500'
                                            "
                                        >
                                            {{ r.status_label }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td class="text-right font-medium">{{
                                        money(r.total)
                                    }}</Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div v-else class="py-8 text-center text-slate-500">
                            Aún no hay reservas.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
