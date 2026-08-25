<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive } from 'vue';
import { FormHelp, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

interface MethodRow {
    method: string;
    label: string;
    platform_enabled: boolean;
    tenant_enabled: boolean;
}

interface GatewayRow {
    id: number;
    provider: string;
    provider_label: string;
    mode: string;
    active: boolean;
    last_event_at: string | null;
}

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    methods: MethodRow[];
    gateways: GatewayRow[];
}>();

const toast = useToasts();

const local = reactive<Record<string, boolean>>(
    Object.fromEntries(props.methods.map((m) => [m.method, m.tenant_enabled])),
);

async function toggle(m: MethodRow) {
    const next = !local[m.method];
    local[m.method] = next;
    try {
        await axios.patch(route('admin.payments.tenant', props.tenant.id), {
            method: m.method,
            enabled: next,
        });
        toast.success(
            'Método actualizado',
            `${m.label}: ${next ? 'habilitado' : 'apagado'} para ${props.tenant.name}`,
        );
    } catch (e: any) {
        local[m.method] = !next;
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Cobros`">
        <TenantHeader :tenant="tenant" :plans="plans" active="payments" />

        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Qué puede cobrar -->
            <div class="col-span-12 xl:col-span-5">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="CreditCard"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Métodos permitidos
                        </h2>
                        <Link
                            :href="route('admin.payments')"
                            class="ml-auto flex items-center text-xs text-primary"
                        >
                            Interruptores globales
                            <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="flex flex-1 flex-col gap-3 p-5">
                        <div
                            v-for="m in methods"
                            :key="m.method"
                            class="flex items-center justify-between gap-3 rounded-lg border p-3.5"
                            :class="
                                m.platform_enabled
                                    ? 'border-slate-200/70 dark:border-darkmode-400'
                                    : 'border-dashed border-slate-300/70 bg-slate-50/60 dark:border-darkmode-400 dark:bg-darkmode-600/40'
                            "
                        >
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    {{ m.label }}
                                </div>
                                <div
                                    v-if="!m.platform_enabled"
                                    class="mt-0.5 text-[11px] text-slate-400"
                                >
                                    Apagado a nivel plataforma: este interruptor
                                    no aplica hasta reencenderlo
                                </div>
                            </div>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    :checked="local[m.method]"
                                    type="checkbox"
                                    :disabled="!m.platform_enabled"
                                    @change="toggle(m)"
                                />
                            </FormSwitch>
                        </div>
                        <FormHelp class="mt-auto">
                            Lo que apagues aquí no se le ofrece a los huéspedes
                            de este hotel ni aparece en su panel.
                        </FormHelp>
                    </div>
                </div>
            </div>

            <!-- Con qué cobra -->
            <div class="col-span-12 xl:col-span-7">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="Landmark"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Pasarelas conectadas
                        </h2>
                        <span
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500 dark:bg-darkmode-400"
                        >
                            {{ gateways.length }}
                        </span>
                    </div>
                    <div class="flex-1 p-5">
                        <div v-if="gateways.length" class="grid gap-3">
                            <div
                                v-for="g in gateways"
                                :key="g.id"
                                class="flex flex-wrap items-center gap-3 rounded-lg border p-3.5"
                                :class="
                                    g.active
                                        ? 'border-success/20 bg-success/5'
                                        : 'border-slate-200/70 dark:border-darkmode-400'
                                "
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                    :class="
                                        g.active
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                    "
                                >
                                    <Lucide icon="Landmark" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        {{ g.provider_label }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{
                                            g.mode === 'live'
                                                ? 'Modo producción'
                                                : 'Modo pruebas'
                                        }}
                                        <template v-if="g.last_event_at">
                                            · último evento
                                            {{ g.last_event_at }}
                                        </template>
                                        <template v-else>
                                            · sin eventos todavía
                                        </template>
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs"
                                    :class="
                                        g.active
                                            ? 'bg-success/10 text-success'
                                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                    "
                                >
                                    {{ g.active ? 'Activa' : 'Inactiva' }}
                                </span>
                            </div>
                        </div>
                        <div
                            v-else
                            class="flex flex-col items-center gap-2 py-10 text-center"
                        >
                            <Lucide
                                icon="Landmark"
                                class="h-9 w-9 text-slate-300"
                            />
                            <p class="text-sm font-medium text-slate-600">
                                Sin pasarela conectada
                            </p>
                            <p class="max-w-sm text-xs text-slate-500">
                                Las conecta el propio hotel desde
                                /ajustes/metodos-pago/pasarela-pago con sus
                                llaves. Sin pasarela solo puede cobrar en
                                efectivo o por transferencia con comprobante.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="gateways.length"
                        class="mt-auto border-t border-dashed border-slate-300/70 px-5 py-3.5"
                    >
                        <FormHelp class="mt-0">
                            Las conecta el propio hotel desde
                            /ajustes/metodos-pago/pasarela-pago con sus llaves;
                            desde aquí solo se ve su estado. El último evento es
                            el webhook más reciente que nos mandó la pasarela.
                        </FormHelp>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
