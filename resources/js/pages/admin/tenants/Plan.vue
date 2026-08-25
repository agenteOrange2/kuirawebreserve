<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import { FormHelp, FormSwitch } from '@/components/Base/Form';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface AddonServiceRow {
    key: string;
    name: string;
    summary: string | null;
    price_monthly: number;
    activation_fee: number;
    modules: string[];
    requires: string | null;
    active: boolean;
    contracted: boolean;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    limits: {
        max_properties: number | null;
        max_rooms: number | null;
        max_users: number | null;
        ai_enabled: boolean;
        ai_monthly_replies: number | null;
    };
    usage: { properties: number; rooms: number; users: number };
    billing: {
        plan_monthly: number;
        addons_monthly: number;
        total_monthly: number;
    };
    addonServices: AddonServiceRow[];
}>();

const toast = useToasts();
const pesos = (n: number) => `$${n.toLocaleString('es-MX')}`;

// Cuánto de su tope lleva usado: el dato que dice si le queda plan o ya
// hay que subirlo.
const rows = [
    {
        label: 'Propiedades',
        used: props.usage.properties,
        cap: props.limits.max_properties,
    },
    {
        label: 'Habitaciones',
        used: props.usage.rooms,
        cap: props.limits.max_rooms,
    },
    {
        label: 'Usuarios',
        used: props.usage.users,
        cap: props.limits.max_users,
    },
];

const percent = (used: number, cap: number | null) =>
    cap ? Math.min(100, Math.round((used / cap) * 100)) : null;

const barTone = (pct: number | null) =>
    pct === null
        ? 'bg-slate-300'
        : pct >= 100
          ? 'bg-danger'
          : pct >= 80
            ? 'bg-warning'
            : 'bg-primary';

function toggleAddon(service: AddonServiceRow) {
    router.patch(
        route('admin.tenants.addon-services', [props.tenant.id, service.key]),
        { contracted: !service.contracted },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    service.contracted
                        ? 'Servicio retirado'
                        : 'Servicio contratado',
                    service.contracted
                        ? `${service.name}: dejó de cobrarse y sus módulos se apagan`
                        : `${service.name}: se suma ${pesos(service.price_monthly)} MXN/mes`,
                ),
            onError: (errors) =>
                toast.error(
                    'No se pudo actualizar',
                    errors.service ?? 'Ocurrió un error.',
                ),
        },
    );
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Plan`">
        <TenantHeader :tenant="tenant" :plans="plans" active="plan" />

        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Lo que paga -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="Receipt"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">Lo que paga</h2>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5 text-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500"
                                >Plan {{ tenant.plan_label }}</span
                            >
                            <span class="font-medium"
                                >{{ pesos(billing.plan_monthly) }} MXN</span
                            >
                        </div>
                        <div
                            v-if="billing.addons_monthly"
                            class="flex items-center justify-between"
                        >
                            <span class="text-slate-500"
                                >Servicios adicionales</span
                            >
                            <span class="font-medium"
                                >+{{ pesos(billing.addons_monthly) }} MXN</span
                            >
                        </div>
                        <div
                            class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-3"
                        >
                            <span class="font-medium">Total mensual</span>
                            <span class="text-lg font-medium text-primary"
                                >{{ pesos(billing.total_monthly) }} MXN</span
                            >
                        </div>
                        <div
                            class="mt-auto rounded-lg border border-dashed border-slate-300/70 p-3 dark:border-darkmode-400"
                        >
                            <div class="text-xs text-slate-500">
                                Asistente IA en el plan
                            </div>
                            <div
                                class="mt-0.5 flex flex-wrap items-center gap-2"
                            >
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        limits.ai_enabled
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    {{ limits.ai_enabled ? 'Sí' : 'No' }}
                                </span>
                                <span
                                    v-if="limits.ai_monthly_replies !== null"
                                    class="text-xs text-slate-500"
                                >
                                    {{ limits.ai_monthly_replies }} respuestas
                                    al mes
                                </span>
                                <Link
                                    :href="
                                        route(
                                            'admin.tenants.assistant',
                                            tenant.id,
                                        )
                                    "
                                    class="ml-auto text-xs text-primary"
                                    >Ajustar</Link
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Qué tanto le queda del plan -->
            <div class="col-span-12 xl:col-span-8">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="Gauge"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Uso contra sus topes
                        </h2>
                    </div>
                    <div class="flex flex-1 flex-col justify-center gap-5 p-5">
                        <div v-for="row in rows" :key="row.label">
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="text-slate-500">{{
                                    row.label
                                }}</span>
                                <span class="font-medium">
                                    {{ row.used }}
                                    <span class="text-slate-400">
                                        / {{ row.cap ?? 'sin límite' }}</span
                                    >
                                </span>
                            </div>
                            <div
                                class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full transition-all"
                                    :class="barTone(percent(row.used, row.cap))"
                                    :style="{
                                        width: `${percent(row.used, row.cap) ?? 4}%`,
                                    }"
                                />
                            </div>
                        </div>
                        <FormHelp class="mt-0">
                            Los topes salen del plan contratado. Para cambiarlos
                            se edita el plan en el catálogo, o se le mueve el
                            plan a este hotel desde "Editar".
                        </FormHelp>
                    </div>
                </div>
            </div>

            <!-- Servicios adicionales -->
            <div class="col-span-12">
                <div class="box box--stacked flex flex-col">
                    <div
                        class="flex flex-wrap items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="PackagePlus"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Servicios adicionales
                        </h2>
                        <span
                            v-if="billing.addons_monthly"
                            class="rounded-full bg-success/10 px-2 py-0.5 text-xs font-medium text-success"
                        >
                            +{{ pesos(billing.addons_monthly) }} MXN/mes
                        </span>
                        <Link
                            :href="route('admin.services')"
                            class="ml-auto flex items-center text-xs text-primary"
                        >
                            Catálogo y precios
                            <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="grid gap-3 p-5 lg:grid-cols-2">
                        <div
                            v-for="service in addonServices"
                            :key="service.key"
                            class="flex items-center gap-3 rounded-lg border p-3.5 transition"
                            :class="
                                service.contracted
                                    ? 'border-success/20 bg-success/5'
                                    : 'border-slate-200/70 dark:border-darkmode-400'
                            "
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{
                                        service.name
                                    }}</span>
                                    <span
                                        v-if="!service.active"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-darkmode-400"
                                        title="Retirado del catálogo: no aplica aunque esté contratado"
                                    >
                                        Fuera de catálogo
                                    </span>
                                </div>
                                <p
                                    v-if="service.summary"
                                    class="mt-0.5 text-xs text-slate-500"
                                >
                                    {{ service.summary }}
                                </p>
                                <div class="mt-1 text-xs text-slate-400">
                                    {{ pesos(service.price_monthly) }} MXN/mes ·
                                    {{ pesos(service.activation_fee) }}
                                    activación única
                                </div>
                            </div>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    :checked="service.contracted"
                                    type="checkbox"
                                    @change="toggleAddon(service)"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <div
                        class="border-t border-dashed border-slate-300/70 px-5 py-3.5"
                    >
                        <FormHelp class="mt-0">
                            Contratar enciende de inmediato los módulos del
                            servicio para este hotel y suma su precio al plan
                            base; retirarlo los apaga sin borrar datos. La
                            Modalidad 3 requiere la Modalidad 2 contratada.
                        </FormHelp>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
