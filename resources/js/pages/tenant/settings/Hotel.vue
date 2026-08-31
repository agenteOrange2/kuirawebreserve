<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
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

// Dos grupos: los activos se leen de un vistazo (nombre y palomita) y
// los que faltan llevan su descripción y el botón, que es donde el hotel
// decide. Antes iban los 24 en una sola tira interminable.
const activeModules = computed(() =>
    props.planCard.modules.filter((mod) => mod.enabled),
);

const inactiveModules = computed(() =>
    props.planCard.modules.filter((mod) => !mod.enabled),
);

/** Los límites que ya se toparon: es lo que hay que avisar arriba. */
const limitsAtCap = computed(() =>
    props.planCard.limits.filter(
        (l) => l.max !== null && limitPercent(l) >= 100,
    ),
);

const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700 dark:text-slate-300';
const stripDivider =
    'hidden h-3.5 w-px bg-slate-300/70 sm:block dark:bg-darkmode-400';

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
            <div class="box box--stacked overflow-hidden">
                <div
                    class="flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Settings" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h1 class="text-base font-medium">
                                Ajustes del hotel
                            </h1>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Tu plan y las áreas de configuración, cada una
                                con su propia pantalla.
                            </p>
                        </div>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-medium text-primary capitalize"
                    >
                        <Lucide icon="BadgeCheck" class="h-3.5 w-3.5" />
                        Plan {{ plan }}
                    </span>
                </div>

                <!-- Cómo está el hotel de un vistazo -->
                <div
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-slate-200/60 bg-slate-50/70 px-4 py-3 text-xs sm:px-5 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                >
                    <span :class="stripItem">
                        <Lucide
                            icon="Blocks"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ activeModules.length }} de
                            {{ planCard.modules.length }}
                        </span>
                        módulos activos
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="CreditCard"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{ paymentSummary.active_gateways }}
                        </span>
                        pasarela(s) ·
                        <span :class="stripValue">
                            {{ paymentSummary.transfer_accounts }}
                        </span>
                        cuenta(s)
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="Mail"
                            class="h-3.5 w-3.5 shrink-0 text-slate-400"
                        />
                        <span :class="stripValue">
                            {{
                                mailSummary.configured
                                    ? 'Correo configurado'
                                    : 'Correo sin configurar'
                            }}
                        </span>
                    </span>
                    <span
                        v-if="limitsAtCap.length"
                        class="inline-flex items-center gap-1.5 rounded-full bg-danger/10 px-2.5 py-1 text-[11px] font-medium text-danger md:ml-auto"
                        :title="limitsAtCap.map((l) => l.label).join(' · ')"
                    >
                        <Lucide icon="TriangleAlert" class="h-3.5 w-3.5" />
                        {{ limitsAtCap.length }} límite(s) al tope
                    </span>
                </div>
            </div>

            <!-- Tu plan: límites con uso real y módulos incluidos -->
            <div class="box box--stacked mt-4">
                <div
                    class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Layers" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-medium">
                            Tu plan: {{ planCard.label }}
                        </h2>
                        <p class="text-xs text-slate-500">
                            Qué incluye y cuánto llevas usado. Para cambiar de
                            plan, contacta a la plataforma.
                        </p>
                    </div>
                </div>
                <div class="px-4 py-3">
                    <!-- Límites: tarjetas compactas en fila. Antes era una
                     columna estirada a la altura de los 24 módulos, con
                     huecos enormes entre barra y barra. -->
                    <div
                        class="flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        <Lucide icon="Gauge" class="h-3.5 w-3.5" />
                        Límites de tu plan
                    </div>
                    <div
                        class="mt-2.5 grid auto-rows-fr grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-5"
                    >
                        <div
                            v-for="l in planCard.limits"
                            :key="l.label"
                            class="rounded-[0.6rem] border border-dashed border-slate-300/70 p-3 dark:border-darkmode-400"
                        >
                            <div class="truncate text-xs text-slate-500">
                                {{ l.label }}
                            </div>
                            <div class="mt-0.5 flex items-baseline gap-1">
                                <span
                                    class="text-sm font-medium"
                                    :class="
                                        l.max !== null && limitPercent(l) >= 100
                                            ? 'text-danger'
                                            : ''
                                    "
                                    >{{ l.used }}</span
                                >
                                <span class="text-[11px] text-slate-400">
                                    {{
                                        l.max !== null
                                            ? `de ${l.max}`
                                            : 'sin límite'
                                    }}
                                </span>
                            </div>
                            <div
                                v-if="l.max !== null"
                                class="mt-2 h-1.5 rounded-full bg-slate-200/70 dark:bg-darkmode-400"
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

                    <!-- Módulos en dos grupos: lo que ya tienes se lee de un
                     vistazo; lo que no, se explica y se puede solicitar. -->
                    <div
                        class="mt-4 flex flex-wrap items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        <Lucide icon="Blocks" class="h-3.5 w-3.5" />
                        Módulos
                        <span class="text-slate-400 normal-case">
                            ({{ activeModules.length }} de
                            {{ planCard.modules.length }} activos)
                        </span>
                    </div>

                    <div
                        v-if="activeModules.length"
                        class="mt-2.5 grid grid-cols-1 gap-1.5 sm:grid-cols-2 xl:grid-cols-3"
                    >
                        <div
                            v-for="mod in activeModules"
                            :key="mod.key"
                            class="flex items-center gap-2 rounded-[0.5rem] border border-slate-200/70 px-2.5 py-2 dark:border-darkmode-400"
                            :title="mod.description"
                        >
                            <div
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Check" class="h-3 w-3" />
                            </div>
                            <span class="min-w-0 truncate text-xs">{{
                                mod.label
                            }}</span>
                        </div>
                    </div>

                    <template v-if="inactiveModules.length">
                        <div
                            class="mt-4 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Lock" class="h-3.5 w-3.5" />
                            No incluidos en tu plan
                        </div>
                        <div
                            class="mt-2.5 grid auto-rows-fr grid-cols-12 gap-2.5"
                        >
                            <div
                                v-for="mod in inactiveModules"
                                :key="mod.key"
                                class="col-span-12 flex flex-col gap-2.5 rounded-[0.6rem] border border-dashed border-slate-300/70 p-3 sm:flex-row sm:items-center xl:col-span-6 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400/50"
                                >
                                    <Lucide icon="Lock" class="h-3.5 w-3.5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="text-xs font-medium text-slate-500"
                                            >{{ mod.label }}</span
                                        >
                                        <span
                                            v-if="!mod.available"
                                            class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >
                                            Próximamente
                                        </span>
                                    </div>
                                    <div
                                        class="mt-0.5 text-[11px] text-slate-400"
                                    >
                                        {{ mod.description }}
                                    </div>
                                </div>
                                <span
                                    v-if="isRequested(mod)"
                                    class="shrink-0 rounded-full bg-warning/10 px-2.5 py-1 text-center text-[11px] font-medium text-warning"
                                >
                                    Solicitud enviada
                                </span>
                                <Button
                                    v-else
                                    type="button"
                                    variant="outline-secondary"
                                    class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                    :disabled="requestingModule === mod.key"
                                    @click="requestModule(mod)"
                                >
                                    Solicitar activación
                                </Button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Áreas de configuración (cada una con superficie propia) -->
            <div class="mt-4 grid auto-rows-fr grid-cols-12 gap-4">
                <Link
                    :href="route('tenant.wizard-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ShoppingBag" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">
                            Wizard de reservas
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: modalidad y huéspedes, extras del punto
                            de venta, apariencia (logo y colores) y resumen de
                            métodos de pago.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <Link
                    :href="route('tenant.payment-methods')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="CreditCard" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Métodos de pago</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: pasarelas de pago, cuentas para
                            transferencia, confirmación automática y saldos.
                        </p>
                        <p
                            class="mt-1 text-[11px]"
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
                            {{ paymentSummary.transfer_accounts }} cuenta(s)
                            para transferencia
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <Link
                    :href="route('tenant.mail-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Mail" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Correo saliente</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: SMTP propio del hotel para que
                            confirmaciones y avisos al huésped salgan por
                            correo.
                        </p>
                        <p
                            class="mt-1 text-[11px]"
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
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Avisos al huésped: recordatorios y agradecimiento post-estancia -->
                <Link
                    :href="route('tenant.guest-notices')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="BellRing" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Avisos al huésped</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: canal de envío, recordatorios de
                            llegada y agradecimiento al salir con encuesta y
                            link de reseñas.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Daños: lo que se cobra al revisar la habitación al salir -->
                <Link
                    :href="route('tenant.damage-catalog')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Hammer" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Daños</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: los conceptos y precios que se cobran
                            cuando se revisa la habitación antes de dejar salir
                            al cliente.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Operación del día: check-in automático, limpieza y cierre -->
                <Link
                    :href="route('tenant.housekeeping-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Operación del día</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: check-in automático a la hora de
                            llegada, flujo de limpieza (manual, automático o
                            ambos) y cierre de día para reservadas vencidas.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Cuestionario de experiencia: aspectos de la encuesta -->
                <Link
                    :href="route('tenant.survey-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Smile" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Encuestas</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: personaliza los aspectos que el huésped
                            califica en el cuestionario que llega con el
                            agradecimiento post-estancia.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Datos generales: contacto, redes, horarios/moneda, políticas, FAQs -->
                <Link
                    :href="route('tenant.general-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-3 p-4 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Building2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">Datos generales</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: contacto y redes, horarios y moneda,
                            políticas del hotel, preguntas frecuentes y
                            apariencia del panel.
                        </p>
                        <p class="mt-1 text-[11px] text-slate-500">
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
                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                    />
                </Link>
            </div>
        </div>
    </RazeLayout>
</template>
