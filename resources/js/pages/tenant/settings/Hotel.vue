<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface PlanLimitRow {
    label: string;
    used: number;
    max: number | null;
}

interface PlanModuleRow {
    key: string;
    label: string;
    description: string;
    available: boolean;
    enabled: boolean;
    requested: boolean;
}

const props = defineProps<{
    plan: string;
    planCard: {
        label: string;
        price_monthly: number;
        limits: PlanLimitRow[];
        modules: PlanModuleRow[];
    };
    paymentSummary: { active_gateways: number; transfer_accounts: number };
    mailSummary: { configured: boolean; from_address: string };
    generalSummary: { phones: number; socials: number; policies: boolean };
}>();

const toast = useToasts();

// Tarjeta "Tu plan": uso de límites y solicitud de módulos.
const limitPercent = (l: PlanLimitRow) =>
    l.max === null || l.max === 0
        ? 0
        : Math.min(100, Math.round((l.used / l.max) * 100));

const requestedLocal = reactive<Record<string, boolean>>({});
const requestingModule = ref<string | null>(null);

const isRequested = (mod: PlanModuleRow) =>
    mod.requested || requestedLocal[mod.key] === true;

async function requestModule(mod: PlanModuleRow) {
    requestingModule.value = mod.key;
    try {
        await axios.post('/api/module-requests', { module: mod.key });
        requestedLocal[mod.key] = true;
        toast.success(
            'Solicitud enviada',
            `La plataforma revisará activar ${mod.label} para tu hotel.`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo enviar',
            e.response?.data?.message ?? 'Ocurrió un error inesperado.',
        );
    } finally {
        requestingModule.value = null;
    }
}
</script>

<template>
    <RazeLayout title="Ajustes del hotel">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Ajustes del hotel</h1>
                    <p class="text-sm text-slate-500">
                        Tu plan y las áreas de configuración del hotel.
                    </p>
                </div>
                <span
                    class="flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary capitalize"
                >
                    <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" /> Plan
                    {{ plan }}
                </span>
            </div>

            <!-- Tu plan: límites con uso real y módulos incluidos -->
            <div class="box box--stacked mt-5 p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                    >
                        <Lucide icon="Layers" class="h-5 w-5 text-primary" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-base font-medium">
                            Tu plan: {{ planCard.label }}
                        </div>
                        <div class="text-xs text-slate-500">
                            Lo que incluye tu plan y cuánto llevas usado. Para
                            cambiar de plan o activar un módulo, usa "Solicitar
                            activación" o contacta a la plataforma.
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-12 gap-5">
                    <div
                        class="col-span-12 flex flex-col rounded-[0.6rem] border border-dashed border-slate-300/70 p-5 lg:col-span-5 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Gauge" class="h-3.5 w-3.5" /> Límites
                        </div>
                        <div class="flex flex-1 flex-col justify-between gap-3.5">
                            <div v-for="l in planCard.limits" :key="l.label">
                                <div
                                    class="mb-1 flex items-center justify-between text-sm"
                                >
                                    <span class="text-slate-500">{{
                                        l.label
                                    }}</span>
                                    <span
                                        class="text-xs"
                                        :class="
                                            l.max !== null &&
                                            limitPercent(l) >= 100
                                                ? 'font-medium text-danger'
                                                : 'text-slate-500'
                                        "
                                    >
                                        {{ l.used
                                        }}{{
                                            l.max !== null
                                                ? ` de ${l.max}`
                                                : ' · sin límite'
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-if="l.max !== null"
                                    class="h-1.5 rounded-full bg-slate-200/70 dark:bg-darkmode-400"
                                >
                                    <div
                                        class="h-1.5 rounded-full"
                                        :class="
                                            limitPercent(l) >= 100
                                                ? 'bg-danger'
                                                : limitPercent(l) >= 80
                                                  ? 'bg-warning'
                                                  : 'bg-primary'
                                        "
                                        :style="{ width: `${limitPercent(l)}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="col-span-12 flex flex-col rounded-[0.6rem] border border-dashed border-slate-300/70 p-5 lg:col-span-7 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Blocks" class="h-3.5 w-3.5" /> Módulos
                        </div>
                        <div
                            class="divide-y divide-dashed divide-slate-200/80 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="mod in planCard.modules"
                                :key="mod.key"
                                class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border"
                                    :class="
                                        mod.enabled
                                            ? 'border-success/10 bg-success/10 text-success'
                                            : 'border-slate-200/80 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400/50'
                                    "
                                >
                                    <Lucide
                                        :icon="mod.enabled ? 'Check' : 'Lock'"
                                        class="h-3.5 w-3.5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span
                                            class="text-sm font-medium"
                                            :class="
                                                mod.enabled
                                                    ? ''
                                                    : 'text-slate-400'
                                            "
                                            >{{ mod.label }}</span
                                        >
                                        <span
                                            v-if="!mod.available"
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium text-pending"
                                        >
                                            Próximamente
                                        </span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-400">
                                        {{ mod.description }}
                                    </div>
                                </div>
                                <template v-if="!mod.enabled">
                                    <span
                                        v-if="isRequested(mod)"
                                        class="shrink-0 rounded-full bg-warning/10 px-2.5 py-1 text-xs text-warning"
                                    >
                                        Solicitud enviada
                                    </span>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="outline-secondary"
                                        class="shrink-0 !px-3 !py-1 text-xs"
                                        :disabled="requestingModule === mod.key"
                                        @click="requestModule(mod)"
                                    >
                                        Solicitar activación
                                    </Button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Áreas de configuración (cada una con superficie propia) -->
            <div class="mt-6 grid grid-cols-12 gap-6">
                <Link
                    :href="route('tenant.wizard-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShoppingBag" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Wizard de reservas</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: modalidad y huéspedes, extras del punto
                            de venta, resumen de métodos de pago.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <Link
                    :href="route('tenant.payment-methods')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CreditCard" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Métodos de pago</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: pasarelas de pago, cuentas para
                            transferencia, confirmación automática y saldos.
                        </p>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                paymentSummary.active_gateways +
                                    paymentSummary.transfer_accounts >
                                0
                                    ? 'text-success'
                                    : 'text-warning'
                            "
                        >
                            {{ paymentSummary.active_gateways }} pasarela(s)
                            activa(s) ·
                            {{ paymentSummary.transfer_accounts }} cuenta(s) para
                            transferencia
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <Link
                    :href="route('tenant.mail-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Mail" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Correo saliente</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: SMTP propio del hotel para que
                            confirmaciones y avisos al huésped salgan por correo.
                        </p>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                mailSummary.configured
                                    ? 'text-success'
                                    : 'text-warning'
                            "
                        >
                            {{
                                mailSummary.configured
                                    ? `SMTP configurado · remitente ${mailSummary.from_address || 'sin definir'}`
                                    : 'Sin configurar — los avisos solo salen por WhatsApp'
                            }}
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Datos generales: contacto, redes, horarios/moneda, políticas, FAQs -->
                <Link
                    :href="route('tenant.general-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Building2" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Datos generales</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: contacto y redes, horarios y moneda,
                            políticas del hotel y preguntas frecuentes.
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ generalSummary.phones }} teléfono(s) ·
                            {{ generalSummary.socials }} red(es) ·
                            {{
                                generalSummary.policies
                                    ? 'políticas escritas'
                                    : 'sin políticas aún'
                            }}
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>
            </div>
        </div>
    </RazeLayout>
</template>
