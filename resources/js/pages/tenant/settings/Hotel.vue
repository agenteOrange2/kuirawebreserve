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
            <!-- Header de tarjeta, mismo patrón que Usuarios: icono en
                 círculo + título + acción a la derecha -->
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Settings" class="h-7 w-7" />
                    </div>
                    <div>
                        <h1 class="text-xl font-medium">Ajustes del hotel</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Tu plan y las áreas de configuración del hotel.
                        </p>
                    </div>
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

                <!-- Límites: tarjetas compactas en fila. Antes era una
                     columna estirada a la altura de los 24 módulos, con
                     huecos enormes entre barra y barra. -->
                <div
                    class="mt-5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Gauge" class="h-3.5 w-3.5" /> Límites de tu
                    plan
                </div>
                <div
                    class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5"
                >
                    <div
                        v-for="l in planCard.limits"
                        :key="l.label"
                        class="rounded-[0.6rem] border border-dashed border-slate-300/70 p-3.5 dark:border-darkmode-400"
                    >
                        <div class="text-xs text-slate-500">{{ l.label }}</div>
                        <div class="mt-1 flex items-baseline gap-1">
                            <span
                                class="text-lg font-medium"
                                :class="
                                    l.max !== null && limitPercent(l) >= 100
                                        ? 'text-danger'
                                        : ''
                                "
                                >{{ l.used }}</span
                            >
                            <span class="text-xs text-slate-400">
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
                    class="mt-6 flex flex-wrap items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Blocks" class="h-3.5 w-3.5" /> Módulos
                    <span class="text-slate-400 normal-case">
                        ({{ activeModules.length }} de
                        {{ planCard.modules.length }} activos)
                    </span>
                </div>

                <div
                    v-if="activeModules.length"
                    class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <div
                        v-for="mod in activeModules"
                        :key="mod.key"
                        class="flex items-center gap-2.5 rounded-[0.5rem] border border-slate-200/70 px-3 py-2.5 dark:border-darkmode-400"
                        :title="mod.description"
                    >
                        <div
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                        >
                            <Lucide icon="Check" class="h-3 w-3" />
                        </div>
                        <span class="min-w-0 truncate text-sm">{{
                            mod.label
                        }}</span>
                    </div>
                </div>

                <template v-if="inactiveModules.length">
                    <div class="mt-6 text-xs text-slate-500">
                        No incluidos en tu plan
                    </div>
                    <div class="mt-3 grid grid-cols-12 gap-3">
                        <div
                            v-for="mod in inactiveModules"
                            :key="mod.key"
                            class="col-span-12 flex flex-col gap-3 rounded-[0.6rem] border border-dashed border-slate-300/70 p-4 sm:flex-row sm:items-center xl:col-span-6 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400/50"
                            >
                                <Lucide icon="Lock" class="h-3.5 w-3.5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="text-sm font-medium text-slate-500"
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
                            <span
                                v-if="isRequested(mod)"
                                class="shrink-0 rounded-full bg-warning/10 px-2.5 py-1 text-center text-xs text-warning"
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
                        </div>
                    </div>
                </template>
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
                            de venta, apariencia (logo y colores) y resumen de
                            métodos de pago.
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
                            {{ paymentSummary.transfer_accounts }} cuenta(s)
                            para transferencia
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
                            confirmaciones y avisos al huésped salgan por
                            correo.
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

                <!-- Avisos al huésped: recordatorios y agradecimiento post-estancia -->
                <Link
                    :href="route('tenant.guest-notices')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="BellRing" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Avisos al huésped</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: canal de envío, recordatorios de
                            llegada y agradecimiento al salir con encuesta y
                            link de reseñas.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Daños: lo que se cobra al revisar la habitación al salir -->
                <Link
                    :href="route('tenant.damage-catalog')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Hammer" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Daños</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: los conceptos y precios que se cobran
                            cuando se revisa la habitación antes de dejar salir
                            al cliente.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Operación del día: check-in automático, limpieza y cierre -->
                <Link
                    :href="route('tenant.housekeeping-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Brush" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Operación del día</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: check-in automático a la hora de
                            llegada, flujo de limpieza (manual, automático o
                            ambos) y cierre de día para reservadas vencidas.
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Cuestionario de experiencia: aspectos de la encuesta -->
                <Link
                    :href="route('tenant.survey-settings')"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Smile" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">Encuestas</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Área aparte: personaliza los aspectos que el huésped
                            califica en el cuestionario que llega con el
                            agradecimiento post-estancia.
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
                            políticas del hotel, preguntas frecuentes y
                            apariencia del panel.
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
