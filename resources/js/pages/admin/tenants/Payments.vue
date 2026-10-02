<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import { FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
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

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

const methodIcon: Record<string, Icon> = {
    transfer: 'Landmark',
    stripe: 'CreditCard',
    mercadopago: 'Wallet',
    paypal: 'Wallet',
    cash: 'Banknote',
};

// Métodos que solo funcionan con una pasarela conectada por el hotel.
const ONLINE = ['stripe', 'mercadopago', 'paypal'];

const local = reactive<Record<string, boolean>>(
    Object.fromEntries(props.methods.map((m) => [m.method, m.tenant_enabled])),
);
const saving = ref<string | null>(null);

const gatewayFor = (method: string) =>
    props.gateways.find((g) => g.provider === method);

// Lo que de verdad ve el huésped: plataforma Y hotel, y si es en línea,
// además una pasarela activa.
function effective(m: MethodRow): { on: boolean; reason: string | null } {
    if (!m.platform_enabled) {
        return { on: false, reason: 'Apagado para toda la plataforma' };
    }
    if (!local[m.method]) {
        return { on: false, reason: 'Apagado para este hotel' };
    }
    if (ONLINE.includes(m.method)) {
        const g = gatewayFor(m.method);
        if (!g) return { on: false, reason: 'Sin pasarela conectada' };
        if (!g.active)
            return { on: false, reason: 'Su pasarela está inactiva' };
        if (g.mode !== 'live') {
            return { on: true, reason: 'Pasarela en modo pruebas' };
        }
    }
    return { on: true, reason: null };
}

const offeredCount = computed(
    () => props.methods.filter((m) => effective(m).on).length,
);
const testGateways = computed(() =>
    props.gateways.filter((g) => g.active && g.mode !== 'live'),
);

async function toggle(m: MethodRow) {
    if (saving.value || !m.platform_enabled) return;
    const next = !local[m.method];
    saving.value = m.method;
    try {
        await axios.patch(route('admin.payments.tenant', props.tenant.id), {
            method: m.method,
            enabled: next,
        });
        local[m.method] = next;
        toast.success(
            'Método actualizado',
            `${m.label}: ${next ? 'habilitado' : 'apagado'} para ${props.tenant.name}`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Cobros`">
        <TenantHeader :tenant="tenant" :plans="plans" active="payments" />

        <div
            v-if="testGateways.length"
            class="mt-4 flex items-start gap-2 rounded-lg border border-warning/20 bg-warning/5 px-4 py-3 text-xs text-warning"
        >
            <Lucide icon="FlaskConical" class="mt-px h-3.5 w-3.5 shrink-0" />
            <span>
                {{ testGateways.map((g) => g.provider_label).join(', ') }}
                {{ testGateways.length === 1 ? 'está' : 'están' }} en modo
                pruebas: las ligas de pago se ven normales, pero
                <span class="font-medium">no cobran dinero real</span>. El hotel
                debe cambiar a sus llaves de producción.
            </span>
        </div>

        <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
            <!-- Qué puede cobrar -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-primary/10 bg-primary/10 text-primary',
                            ]"
                        >
                            <Lucide icon="CreditCard" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">
                                Métodos de cobro
                            </h2>
                            <p class="text-xs text-slate-500">
                                {{ offeredCount }} de {{ methods.length }} se le
                                ofrecen a sus huéspedes.
                            </p>
                        </div>
                        <Link
                            :href="route('admin.payments')"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[0.5rem] border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:text-slate-300"
                        >
                            Globales
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                    <div
                        class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="m in methods"
                            :key="m.method"
                            class="flex items-center gap-3 px-4 py-3"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    effective(m).on
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide
                                    :icon="methodIcon[m.method] ?? 'CreditCard'"
                                    class="h-3.5 w-3.5"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-medium">
                                    {{ m.label }}
                                </div>
                                <div
                                    class="text-[11px]"
                                    :class="
                                        effective(m).on
                                            ? effective(m).reason
                                                ? 'text-warning'
                                                : 'text-success'
                                            : 'text-slate-400'
                                    "
                                >
                                    {{
                                        effective(m).on
                                            ? (effective(m).reason ??
                                              'Se ofrece a sus huéspedes')
                                            : `No se ofrece: ${effective(m).reason?.toLowerCase()}`
                                    }}
                                </div>
                            </div>
                            <FormSwitch
                                class="shrink-0"
                                :title="
                                    m.platform_enabled
                                        ? 'Permitirlo o apagarlo para este hotel'
                                        : 'Apagado a nivel plataforma: este interruptor no aplica'
                                "
                            >
                                <FormSwitch.Input
                                    :checked="local[m.method]"
                                    type="checkbox"
                                    :disabled="
                                        !m.platform_enabled ||
                                        saving === m.method
                                    "
                                    @click.prevent="toggle(m)"
                                />
                            </FormSwitch>
                        </div>
                    </div>
                    <p
                        class="border-t border-slate-200/60 px-4 py-2.5 text-[11px] text-slate-400 dark:border-darkmode-400"
                    >
                        Lo que se apague aquí no se le ofrece a sus huéspedes ni
                        aparece en su panel. Los métodos en línea además
                        necesitan su pasarela.
                    </p>
                </div>
            </div>

            <!-- Con qué cobra -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-success/10 bg-success/10 text-success',
                            ]"
                        >
                            <Lucide icon="Landmark" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">
                                Pasarelas conectadas
                            </h2>
                            <p class="text-xs text-slate-500">
                                Las conecta el hotel con sus llaves; aquí solo
                                se ve su estado.
                            </p>
                        </div>
                    </div>
                    <div
                        v-if="gateways.length"
                        class="flex-1 divide-y divide-slate-200/60 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="g in gateways"
                            :key="g.id"
                            class="flex items-center gap-3 px-4 py-3"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    g.active
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide
                                    :icon="methodIcon[g.provider] ?? 'Landmark'"
                                    class="h-3.5 w-3.5"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span class="text-sm font-medium">{{
                                        g.provider_label
                                    }}</span>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            g.mode === 'live'
                                                ? 'bg-success/10 text-success'
                                                : 'bg-warning/10 text-warning'
                                        "
                                        >{{
                                            g.mode === 'live'
                                                ? 'Producción'
                                                : 'Pruebas'
                                        }}</span
                                    >
                                </div>
                                <div
                                    class="mt-0.5 inline-flex items-center gap-1 text-[11px] text-slate-500"
                                    title="Último webhook que nos mandó la pasarela"
                                >
                                    <Lucide icon="Activity" class="h-3 w-3" />
                                    {{
                                        g.last_event_at
                                            ? `Último evento hace ${g.last_event_at}`
                                            : 'Sin eventos todavía'
                                    }}
                                </div>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
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
                        class="flex flex-1 flex-col items-center justify-center gap-2 px-4 py-10 text-center"
                    >
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                        >
                            <Lucide icon="Landmark" class="h-4 w-4" />
                        </div>
                        <p class="text-xs font-medium text-slate-600">
                            Sin pasarela conectada
                        </p>
                        <p class="max-w-sm text-xs text-slate-500">
                            Solo puede cobrar en efectivo o por transferencia
                            con comprobante. La conecta el hotel en Ajustes,
                            Métodos de pago, Pasarela de pago.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
