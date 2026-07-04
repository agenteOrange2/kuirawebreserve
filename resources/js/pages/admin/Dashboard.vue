<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface AiTenantRow {
    id: string;
    name: string;
    plan: string;
    plan_label: string;
    suspended: boolean;
    enabled: boolean;
    provider_label: string | null;
    used: number;
    limit: number | null;
    prompt_tokens: number;
    completion_tokens: number;
}

interface RecentTenantRow {
    id: string;
    name: string;
    plan_label: string;
    suspended: boolean;
    domain: string | null;
    created_at: string | null;
}

const props = defineProps<{
    stats: {
        tenants: number;
        active: number;
        suspended: number;
        users: number;
        ai_replies_month: number;
        ai_tokens_month: number;
        ai_keys_active: number;
        ai_keys_total: number;
    };
    monthLabel: string;
    activity: { date: string; replies: number }[];
    planDistribution: { value: string; label: string; count: number; ai: boolean }[];
    aiTenants: AiTenantRow[];
    recentTenants: RecentTenantRow[];
}>();

// Formato compacto para tokens (la base del costo): 12400 → "12.4k".
function fmt(n: number): string {
    if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
    if (n >= 1_000) return `${(n / 1_000).toFixed(1)}k`;
    return `${n}`;
}

const maxActivity = computed(() => Math.max(...props.activity.map((d) => d.replies), 1));
const activityTotal = computed(() => props.activity.reduce((sum, d) => sum + d.replies, 0));
const maxPlanCount = computed(() => Math.max(...props.planDistribution.map((p) => p.count), 1));

function quotaPercent(row: AiTenantRow): number | null {
    if (!row.limit) return null;
    return Math.min(100, Math.round((row.used / row.limit) * 100));
}

function quotaColor(percent: number): string {
    if (percent >= 90) return 'bg-danger';
    if (percent >= 70) return 'bg-warning';
    return 'bg-success';
}
</script>

<template>
    <RazeLayout title="Panel de plataforma">
        <div class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center">
            <div class="text-base font-medium group-[.mode--light]:text-white">
                Panel de plataforma
            </div>
            <div class="flex flex-col gap-x-3 gap-y-2 sm:flex-row md:ml-auto">
                <Button
                    :as="Link"
                    :href="route('admin.ai')"
                    variant="outline-secondary"
                    class="bg-white/80 dark:bg-darkmode-400/80"
                >
                    <Lucide icon="Bot" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Agentes IA
                </Button>
                <Button :as="Link" :href="route('admin.tenants.index')" variant="primary">
                    <Lucide icon="Building2" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Hoteles
                </Button>
            </div>
        </div>

        <div class="mt-3.5 grid grid-cols-12 gap-5">
            <!-- KPIs -->
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                            <Lucide icon="Building2" class="h-6 w-6 text-primary fill-primary/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ stats.tenants }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Hoteles registrados</div>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center gap-3 border-t border-dashed border-slate-300/70 pt-3 text-xs">
                        <span class="flex items-center gap-1.5 text-success">
                            <span class="h-1.5 w-1.5 rounded-full bg-success" /> {{ stats.active }} activos
                        </span>
                        <span class="flex items-center gap-1.5" :class="stats.suspended ? 'text-danger' : 'text-slate-400'">
                            <span class="h-1.5 w-1.5 rounded-full" :class="stats.suspended ? 'bg-danger' : 'bg-slate-300'" />
                            {{ stats.suspended }} suspendidos
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-info/10 bg-info/10">
                            <Lucide icon="MessagesSquare" class="h-6 w-6 text-info fill-info/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ fmt(stats.ai_replies_month) }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Respuestas IA · {{ monthLabel }}</div>
                        </div>
                    </div>
                    <div class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                        Con keys maestras de la plataforma (BYOK no cuenta).
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-warning/10 bg-warning/10">
                            <Lucide icon="Cpu" class="h-6 w-6 text-warning fill-warning/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ fmt(stats.ai_tokens_month) }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">Tokens IA · {{ monthLabel }}</div>
                        </div>
                    </div>
                    <div class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                        Base del costo con los proveedores LLM.
                    </div>
                </div>
            </div>
            <div class="col-span-12 sm:col-span-6 xl:col-span-3">
                <div class="box box--stacked h-full p-5">
                    <div class="flex items-center">
                        <div class="flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10">
                            <Lucide icon="KeyRound" class="h-6 w-6 text-success fill-success/10" />
                        </div>
                        <div class="ml-4">
                            <div class="text-2xl font-medium">{{ stats.ai_keys_active }}<span class="text-base text-slate-400">/{{ stats.ai_keys_total }}</span></div>
                            <div class="mt-0.5 text-xs text-slate-500">Keys maestras activas</div>
                        </div>
                    </div>
                    <div class="mt-4 border-t border-dashed border-slate-300/70 pt-3 text-xs">
                        <Link :href="route('admin.ai')" class="flex items-center text-primary">
                            Administrar proveedores
                            <Lucide icon="ArrowRight" class="ml-1 h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>
            </div>

            <!-- Actividad del bot -->
            <div class="col-span-12 xl:col-span-8">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center justify-between border-b border-dashed border-slate-300/70 px-5 py-4">
                        <h2 class="text-base font-medium">Actividad del bot · últimos 14 días</h2>
                        <span class="rounded-full bg-info/10 px-2.5 py-0.5 text-xs text-info">
                            {{ fmt(activityTotal) }} respuestas
                        </span>
                    </div>
                    <div class="flex flex-1 flex-col justify-end p-5">
                        <div class="flex h-40 items-end gap-1.5">
                            <div
                                v-for="day in activity"
                                :key="day.date"
                                class="group flex flex-1 flex-col items-center justify-end self-stretch"
                                :title="`${day.date}: ${day.replies} respuestas`"
                            >
                                <div class="mb-1 text-[10px] text-slate-500 opacity-0 transition group-hover:opacity-100">
                                    {{ day.replies }}
                                </div>
                                <div
                                    class="w-full rounded-t transition"
                                    :class="day.replies ? 'bg-primary/70 group-hover:bg-primary' : 'bg-slate-200/70 dark:bg-darkmode-400'"
                                    :style="{ height: day.replies ? `${Math.max(6, (day.replies / maxActivity) * 100)}%` : '4px' }"
                                />
                            </div>
                        </div>
                        <div class="mt-2 flex gap-1.5 border-t border-dashed border-slate-300/70 pt-2">
                            <div v-for="day in activity" :key="day.date" class="flex-1 text-center text-[10px] text-slate-400">
                                {{ day.date.slice(0, 2) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Distribución por plan -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div class="flex items-center justify-between border-b border-dashed border-slate-300/70 px-5 py-4">
                        <h2 class="text-base font-medium">Distribución por plan</h2>
                    </div>
                    <div class="flex flex-1 flex-col justify-center gap-5 p-5">
                        <div v-for="plan in planDistribution" :key="plan.value">
                            <div class="mb-1.5 flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2">
                                    {{ plan.label }}
                                    <span
                                        v-if="plan.ai"
                                        class="flex items-center gap-1 rounded-full bg-info/10 px-2 py-0.5 text-[10px] text-info"
                                    >
                                        <Lucide icon="Bot" class="h-3 w-3" /> IA incluida
                                    </span>
                                </span>
                                <span class="font-medium">{{ plan.count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-slate-200/70 dark:bg-darkmode-400">
                                <div
                                    class="h-2 rounded-full bg-primary/80"
                                    :style="{ width: `${Math.max(plan.count ? 6 : 0, (plan.count / maxPlanCount) * 100)}%` }"
                                />
                            </div>
                        </div>
                        <div class="border-t border-dashed border-slate-300/70 pt-3 text-xs text-slate-500">
                            {{ stats.users }} usuario(s) con acceso al panel de plataforma.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Uso de IA por hotel -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div class="flex items-center justify-between border-b border-dashed border-slate-300/70 px-5 py-4">
                        <h2 class="text-base font-medium">Uso de IA por hotel · {{ monthLabel }}</h2>
                        <Link :href="route('admin.ai')" class="flex items-center text-sm text-primary">
                            Asignaciones y cuotas
                            <Lucide icon="ArrowRight" class="ml-1 h-4 w-4" />
                        </Link>
                    </div>
                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="aiTenants.length" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Hotel</Table.Th>
                                    <Table.Th>Plan</Table.Th>
                                    <Table.Th>Bot</Table.Th>
                                    <Table.Th>Proveedor</Table.Th>
                                    <Table.Th>Cuota del mes</Table.Th>
                                    <Table.Th class="text-right">Tokens (entrada / salida)</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="row in aiTenants" :key="row.id">
                                    <Table.Td>
                                        <div class="flex items-center gap-2 font-medium">
                                            {{ row.name }}
                                            <span
                                                v-if="row.suspended"
                                                class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] text-danger"
                                            >
                                                Suspendido
                                            </span>
                                        </div>
                                    </Table.Td>
                                    <Table.Td>
                                        <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                                            {{ row.plan_label }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>
                                        <span
                                            class="flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                            :class="row.enabled ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" :class="row.enabled ? 'bg-success' : 'bg-danger'" />
                                            {{ row.enabled ? 'Activo' : 'Apagado' }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>
                                        <span v-if="row.provider_label">{{ row.provider_label }}</span>
                                        <span v-else class="text-slate-400">Automático</span>
                                    </Table.Td>
                                    <Table.Td>
                                        <template v-if="row.limit">
                                            <div class="flex items-center gap-3">
                                                <div class="h-2 w-32 rounded-full bg-slate-200/70 dark:bg-darkmode-400">
                                                    <div
                                                        class="h-2 rounded-full"
                                                        :class="quotaColor(quotaPercent(row) ?? 0)"
                                                        :style="{ width: `${quotaPercent(row)}%` }"
                                                    />
                                                </div>
                                                <span class="whitespace-nowrap text-xs text-slate-500">
                                                    {{ row.used }} / {{ row.limit }}
                                                </span>
                                            </div>
                                        </template>
                                        <span v-else class="whitespace-nowrap text-xs text-slate-500">
                                            {{ row.used }} · sin límite
                                        </span>
                                    </Table.Td>
                                    <Table.Td class="text-right">
                                        <span class="whitespace-nowrap text-sm">
                                            {{ fmt(row.prompt_tokens) }} <span class="text-slate-400">/</span> {{ fmt(row.completion_tokens) }}
                                        </span>
                                    </Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div v-else class="py-8 text-center text-slate-500">
                            Ningún hotel tiene IA en su plan todavía. Los planes con IA aparecerán aquí con su consumo.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hoteles recientes -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div class="flex items-center justify-between border-b border-dashed border-slate-300/70 px-5 py-4">
                        <h2 class="text-base font-medium">Hoteles recientes</h2>
                        <Link :href="route('admin.tenants.index')" class="flex items-center text-sm text-primary">
                            Ver todos
                            <Lucide icon="ArrowRight" class="ml-1 h-4 w-4" />
                        </Link>
                    </div>
                    <div class="overflow-auto p-5 lg:overflow-visible">
                        <Table v-if="recentTenants.length" striped>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>Hotel</Table.Th>
                                    <Table.Th>Dominio</Table.Th>
                                    <Table.Th>Plan</Table.Th>
                                    <Table.Th>Estado</Table.Th>
                                    <Table.Th>Alta</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                <Table.Tr v-for="tenant in recentTenants" :key="tenant.id">
                                    <Table.Td class="font-medium">{{ tenant.name }}</Table.Td>
                                    <Table.Td>
                                        <a
                                            v-if="tenant.domain"
                                            :href="`http://${tenant.domain}`"
                                            target="_blank"
                                            class="text-primary hover:underline"
                                        >
                                            {{ tenant.domain }}
                                        </a>
                                        <span v-else class="text-slate-400">—</span>
                                    </Table.Td>
                                    <Table.Td>
                                        <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs text-primary">
                                            {{ tenant.plan_label }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>
                                        <span
                                            class="flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-xs"
                                            :class="tenant.suspended ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" :class="tenant.suspended ? 'bg-danger' : 'bg-success'" />
                                            {{ tenant.suspended ? 'Suspendido' : 'Activo' }}
                                        </span>
                                    </Table.Td>
                                    <Table.Td>{{ tenant.created_at ?? '—' }}</Table.Td>
                                </Table.Tr>
                            </Table.Tbody>
                        </Table>
                        <div v-else class="py-8 text-center text-slate-500">
                            Aún no hay hoteles registrados.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
