<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ReservationRow {
    code: string;
    guest: string | null;
    status: string;
    status_label: string;
    starts_at: string;
    total: number;
}

const props = defineProps<{
    tenant: {
        id: string;
        name: string;
        plan: string;
        plan_label: string;
        suspended: boolean;
        domain: string | null;
        created_at: string | null;
    };
    plan: {
        max_properties: number | null;
        max_rooms: number | null;
        max_users: number | null;
        price_monthly: number;
        ai_enabled: boolean;
    };
    ops: {
        owner: { name: string; email: string } | null;
        users: number;
        users_list: { id: number; name: string; email: string; role: string | null }[];
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
    ai: {
        enabled: boolean;
        limit: number | null;
        used: number;
        tokens: number;
        byok_allowed: boolean;
    };
    plans: { value: string; label: string }[];
}>();

const money = (n: number) => `$${n.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const statusTone: Record<string, string> = {
    pending: 'bg-warning/10 text-warning',
    confirmed: 'bg-info/10 text-info',
    checked_in: 'bg-primary/10 text-primary',
    completed: 'bg-success/10 text-success',
    cancelled: 'bg-danger/10 text-danger',
    no_show: 'bg-pending/10 text-pending',
};

// Entrar como (misma mecánica que el listado).
const impersonating = ref(false);
const actionError = ref<string | null>(null);

async function impersonate() {
    impersonating.value = true;
    actionError.value = null;
    // Abrir la pestaña ANTES del await: tras una respuesta asíncrona el
    // navegador ya no lo trata como gesto del usuario y bloquea el popup
    // (y el token de impersonación solo vive 60 s, no admite copiar/pegar).
    const win = window.open('', '_blank');
    try {
        const { data } = await axios.post<{ url: string }>(route('admin.tenants.impersonate', props.tenant.id));
        if (win) {
            win.location.href = data.url;
        } else {
            window.location.href = data.url;
        }
    } catch (e: any) {
        win?.close();
        actionError.value = e?.response?.data?.message ?? 'No se pudo generar el acceso.';
    } finally {
        impersonating.value = false;
    }
}

function toggleSuspend() {
    router.patch(route('admin.tenants.suspend', props.tenant.id), {}, { preserveScroll: true });
}

// Editar nombre / plan.
const showEdit = ref(false);
const editForm = useForm({ name: props.tenant.name, plan: props.tenant.plan });

function submitEdit() {
    editForm.put(route('admin.tenants.update', props.tenant.id), {
        onSuccess: () => (showEdit.value = false),
    });
}

const quotaPercent = () => {
    if (!props.ai.limit) return null;
    return Math.min(100, Math.round((props.ai.used / props.ai.limit) * 100));
};
</script>

<template>
    <RazeLayout :title="tenant.name">
        <!-- Encabezado -->
        <div class="mt-2 flex flex-col gap-y-3 md:flex-row md:items-center">
            <div class="flex min-w-0 items-center gap-3.5">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                    <Lucide icon="Building2" class="h-6 w-6 text-primary fill-primary/10" />
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h1 class="truncate text-lg font-medium group-[.mode--light]:text-white">{{ tenant.name }}</h1>
                        <span
                            class="flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                            :class="tenant.suspended ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'"
                        >
                            <span class="h-1.5 w-1.5 rounded-full" :class="tenant.suspended ? 'bg-danger' : 'bg-success'" />
                            {{ tenant.suspended ? 'Suspendido' : 'Activo' }}
                        </span>
                        <span class="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">{{ tenant.plan_label }}</span>
                    </div>
                    <div class="mt-0.5 flex items-center gap-2 text-sm text-slate-500">
                        <a v-if="tenant.domain" :href="`http://${tenant.domain}`" target="_blank" class="flex items-center gap-1 text-primary hover:underline">
                            {{ tenant.domain }} <Lucide icon="ExternalLink" class="h-3 w-3" />
                        </a>
                        <span v-if="tenant.created_at">· cliente desde {{ tenant.created_at }}</span>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 md:ml-auto">
                <Button :as="Link" :href="route('admin.tenants.index')" variant="outline-secondary" class="rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80">
                    <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" /> Hoteles
                </Button>
                <Button variant="outline-secondary" class="rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80" @click="showEdit = true">
                    <Lucide icon="Pencil" class="mr-2 h-4 w-4 stroke-[1.3]" /> Editar
                </Button>
                <Button
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white/80 dark:bg-darkmode-400/80"
                    :class="tenant.suspended ? '!text-success' : '!text-warning'"
                    @click="toggleSuspend"
                >
                    <Lucide :icon="tenant.suspended ? 'Play' : 'Pause'" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    {{ tenant.suspended ? 'Reactivar' : 'Suspender' }}
                </Button>
                <Button v-if="!tenant.suspended" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="impersonating" @click="impersonate">
                    <Lucide icon="LogIn" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    {{ impersonating ? 'Abriendo…' : 'Entrar como' }}
                </Button>
            </div>
        </div>

        <div v-if="actionError" class="mt-4 flex items-center rounded-md border border-danger/20 bg-danger/5 px-4 py-3 text-sm text-danger">
            <Lucide icon="TriangleAlert" class="mr-2 h-4 w-4 shrink-0" /> {{ actionError }}
        </div>

        <div class="mt-3.5 grid grid-cols-12 gap-5">
            <!-- KPIs de operación -->
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Users" class="h-5 w-5 text-primary" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.users }}<span v-if="plan.max_users" class="text-xs text-slate-400">/{{ plan.max_users }}</span></div>
                            <div class="truncate text-xs text-slate-500">Usuarios</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10">
                            <Lucide icon="BedDouble" class="h-5 w-5 text-info" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.rooms }}<span v-if="plan.max_rooms" class="text-xs text-slate-400">/{{ plan.max_rooms }}</span></div>
                            <div class="truncate text-xs text-slate-500">Habitaciones</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10">
                            <Lucide icon="CalendarCheck" class="h-5 w-5 text-success" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.reservations_month }}</div>
                            <div class="truncate text-xs text-slate-500">Reservas del mes</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10">
                            <Lucide icon="Banknote" class="h-5 w-5 text-warning" />
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-lg font-medium">{{ money(ops.revenue_month) }}</div>
                            <div class="truncate text-xs text-slate-500">Cobrado este mes</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10">
                            <Lucide icon="DoorOpen" class="h-5 w-5 text-pending" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.active_stays }}</div>
                            <div class="truncate text-xs text-slate-500">Estancias activas</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-span-6 sm:col-span-4 xl:col-span-2">
                <div class="box box--stacked h-full p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-dark/10 bg-dark/10">
                            <Lucide icon="MessagesSquare" class="h-5 w-5 text-dark dark:text-slate-300" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-lg font-medium">{{ ops.conversations }}</div>
                            <div class="truncate text-xs text-slate-500">Conversaciones</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contrato + dueño -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="FileText" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Contrato</h2>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Plan</span>
                            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">{{ tenant.plan_label }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Precio de lista</span>
                            <span class="font-medium">${{ plan.price_monthly.toLocaleString('es-MX') }} MXN/mes</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Propiedades</span>
                            <span class="font-medium">{{ ops.properties }}<span class="text-slate-400"> / {{ plan.max_properties ?? 'sin límite' }}</span></span>
                        </div>
                        <div class="border-t border-dashed border-slate-300/70 pt-3">
                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Dueño (owner)</div>
                            <template v-if="ops.owner">
                                <div class="font-medium">{{ ops.owner.name }}</div>
                                <div class="text-slate-500">{{ ops.owner.email }}</div>
                            </template>
                            <div v-else class="text-slate-400">Sin usuario propietario</div>
                        </div>
                        <div class="mt-auto border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                            {{ ops.guests }} huésped(es) en su CRM · {{ ops.conversations_pending }} conversación(es) esperando humano
                        </div>
                    </div>
                </div>
            </div>

            <!-- IA -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="Bot" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Asistente IA</h2>
                        <Link :href="route('admin.ai')" class="ml-auto flex items-center text-xs text-primary">
                            Administrar <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5 text-sm">
                        <template v-if="plan.ai_enabled">
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Bot</span>
                                <span
                                    class="flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                    :class="ai.enabled ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="ai.enabled ? 'bg-success' : 'bg-danger'" />
                                    {{ ai.enabled ? 'Activo' : 'Apagado' }}
                                </span>
                            </div>
                            <div>
                                <div class="mb-1.5 flex items-center justify-between">
                                    <span class="text-slate-500">Respuestas del mes</span>
                                    <span class="text-xs text-slate-500">{{ ai.used }}{{ ai.limit ? ` / ${ai.limit}` : ' · sin límite' }}</span>
                                </div>
                                <div v-if="ai.limit" class="h-2 rounded-full bg-slate-200/70 dark:bg-darkmode-400">
                                    <div
                                        class="h-2 rounded-full"
                                        :class="(quotaPercent() ?? 0) >= 90 ? 'bg-danger' : (quotaPercent() ?? 0) >= 70 ? 'bg-warning' : 'bg-success'"
                                        :style="{ width: `${quotaPercent()}%` }"
                                    />
                                </div>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">Tokens del mes</span>
                                <span class="font-medium">{{ ai.tokens.toLocaleString('es-MX') }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500">BYOK (keys propias)</span>
                                <span class="text-xs" :class="ai.byok_allowed ? 'text-success' : 'text-slate-400'">{{ ai.byok_allowed ? 'Permitido' : 'No' }}</span>
                            </div>
                        </template>
                        <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 py-4 text-center">
                            <Lucide icon="BotOff" class="h-8 w-8 text-slate-300" />
                            <p class="text-xs text-slate-500">Su plan no incluye IA — palanca de upsell.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Equipo -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="Users" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Equipo</h2>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400">{{ ops.users }}</span>
                    </div>
                    <div class="max-h-64 flex-1 divide-y divide-dashed divide-slate-300/70 overflow-y-auto p-5 py-2">
                        <div v-for="u in ops.users_list" :key="u.id" class="flex items-center gap-3 py-2.5">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-[10px] font-semibold text-white">
                                {{ u.name.trim().split(/\s+/).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ u.name }}</div>
                                <div class="truncate text-xs text-slate-500">{{ u.email }}</div>
                            </div>
                            <span v-if="u.role" class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] capitalize text-slate-500 dark:bg-darkmode-400">{{ u.role }}</span>
                        </div>
                        <div v-if="!ops.users_list.length" class="py-6 text-center text-sm text-slate-400">Sin usuarios.</div>
                    </div>
                </div>
            </div>

            <!-- Reservas recientes -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4">
                        <Lucide icon="CalendarClock" class="h-4 w-4 stroke-[1.5] text-primary" />
                        <h2 class="text-base font-medium">Reservas recientes</h2>
                    </div>
                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="ops.recent_reservations.length" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Código</Table.Th>
                                    <Table.Th>Huésped</Table.Th>
                                    <Table.Th>Llegada</Table.Th>
                                    <Table.Th>Estado</Table.Th>
                                    <Table.Th class="text-right">Total</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="r in ops.recent_reservations" :key="r.code">
                                    <Table.Td class="font-mono text-xs">{{ r.code }}</Table.Td>
                                    <Table.Td class="font-medium">{{ r.guest ?? '—' }}</Table.Td>
                                    <Table.Td>{{ r.starts_at }}</Table.Td>
                                    <Table.Td>
                                        <span class="rounded-full px-2 py-0.5 text-xs" :class="statusTone[r.status] ?? 'bg-slate-100 text-slate-500'">
                                            {{ r.status_label }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td class="text-right font-medium">{{ money(r.total) }}</Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div v-else class="py-8 text-center text-slate-500">Aún no hay reservas.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal editar -->
        <Dialog :open="showEdit" @close="showEdit = false">
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-4 text-base font-medium">Editar hotel</h2>
                    <form class="space-y-4" @submit.prevent="submitEdit">
                        <div>
                            <FormLabel htmlFor="edit-name">Nombre</FormLabel>
                            <FormInput id="edit-name" v-model="editForm.name" type="text" />
                            <FormHelp v-if="editForm.errors.name" class="text-danger">{{ editForm.errors.name }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="edit-plan">Plan</FormLabel>
                            <FormSelect id="edit-plan" v-model="editForm.plan">
                                <option v-for="p in plans" :key="p.value" :value="p.value">{{ p.label }}</option>
                            </FormSelect>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="outline-secondary" @click="showEdit = false">Cancelar</Button>
                            <Button type="submit" variant="primary" :disabled="editForm.processing">Guardar</Button>
                        </div>
                    </form>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
