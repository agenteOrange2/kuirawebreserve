<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormSelect, FormSwitch } from '@/components/Base/Form';
import Lucide, { type Icon } from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    paymentMode: 'automatic' | 'always' | 'never';
    cashEnabled: boolean;
    enabledMethods: Record<string, boolean>;
    hasCobrosModule: boolean;
    hasMotorWebModule: boolean;
    ratePlansWithDeposit: number;
    activeRatePlans: number;
    gatewaysSummary: { connected: number; active: number; test: number };
    transferSummary: { accounts_active: number; whatsapps: number };
    termsSummary: {
        hold_value: number;
        hold_unit: string;
        transfer_valid_value: number;
        transfer_valid_unit: string;
        cash_deadline_value: number;
        cash_deadline_unit: string;
        balance_due_enabled: boolean;
    };
    policiesSummary: {
        cancel_policy_enabled: boolean;
        walkin_charge: 'checkout' | 'checkin';
        guarantee_enabled: boolean;
    };
}>();

const toast = useToasts();

// ---- Modo de pago del wizard (cuándo se pide pago en línea al reservar) ----
const savingPaymentMode = ref(false);
const paymentMode = ref(props.paymentMode);
const paymentModeLabels: Record<string, string> = {
    automatic: 'Automático (lo decide cada tarifa con su anticipo)',
    always: 'Siempre pedir pago en línea',
    never: 'Nunca: se paga al llegar',
};

async function savePaymentMode() {
    savingPaymentMode.value = true;
    const previous = paymentMode.value;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: { payment_mode: paymentMode.value },
        });
        toast.success('Guardado', 'Modo de pago actualizado.');
    } catch (e: any) {
        paymentMode.value = previous;
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        savingPaymentMode.value = false;
    }
}

// Diagnóstico: por qué el wizard puede no ofrecer pago aunque haya pasarela.
// La causa #1 de "conecté Stripe y no aparece": modo automático sin ninguna
// tarifa con anticipo configurado.
const wizardWillCharge = computed(() => {
    if (paymentMode.value === 'always') return true;
    if (paymentMode.value === 'never') return false;
    return props.ratePlansWithDeposit > 0;
});

// ---- Pago en el hotel (efectivo): el huésped aparta sin pagar en línea ----
// El interruptor de plataforma (/admin/payments) manda: apagado ahí, esto
// se muestra deshabilitado sin importar lo que el hotel haya guardado.
const cashAllowedByPlatform = computed(
    () => props.enabledMethods.cash !== false,
);
const savingCash = ref(false);
const cashEnabled = ref(props.cashEnabled);

async function toggleCashEnabled() {
    const next = !cashEnabled.value;
    cashEnabled.value = next;
    savingCash.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: { cash_payment_enabled: next },
        });
        toast.success(
            'Guardado',
            next
                ? 'El wizard ofrecerá pagar en el hotel.'
                : 'El wizard ya no ofrecerá pagar en el hotel.',
        );
    } catch (e: any) {
        cashEnabled.value = !next;
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        savingCash.value = false;
    }
}

// ---- Resúmenes por tarjeta: qué hay adentro sin tener que entrar ----
const UNITS: Record<string, [string, string]> = {
    minute: ['minuto', 'minutos'],
    hour: ['hora', 'horas'],
    day: ['día', 'días'],
    week: ['semana', 'semanas'],
};
const spanOf = (value: number, unit: string) => {
    const u = UNITS[unit] ?? UNITS.hour;
    return `${value} ${value === 1 ? u[0] : u[1]}`;
};

const gatewaysLine = computed(() => {
    const s = props.gatewaysSummary;
    if (s.connected === 0) return 'Sin pasarelas conectadas';
    const base =
        s.active > 0
            ? `${s.active} activa(s) de ${s.connected} conectada(s)`
            : `${s.connected} conectada(s), ninguna activa`;
    return s.test > 0 ? `${base} · ${s.test} en modo prueba` : base;
});

const transferLine = computed(() => {
    const s = props.transferSummary;
    if (s.accounts_active === 0) return 'Sin cuentas activas';
    const base = `${s.accounts_active} cuenta(s) activa(s)`;
    return s.whatsapps > 0
        ? `${base} · ${s.whatsapps} WhatsApp para comprobantes`
        : base;
});

const termsLine = computed(() => {
    const s = props.termsSummary;
    const parts = [
        `Apartado ${spanOf(s.hold_value, s.hold_unit)}`,
        `transferencia ${spanOf(s.transfer_valid_value, s.transfer_valid_unit)}`,
    ];
    if (cashAllowedByPlatform.value && cashEnabled.value) {
        parts.push(
            `pago en hotel ${spanOf(s.cash_deadline_value, s.cash_deadline_unit)}`,
        );
    }
    parts.push(
        s.balance_due_enabled
            ? 'pago total exigido antes de llegar'
            : 'sin fecha límite del total',
    );
    return parts.join(' · ');
});

const policiesLine = computed(() => {
    const s = props.policiesSummary;
    return [
        `Cancelación ${s.cancel_policy_enabled ? 'activa' : 'apagada'}`,
        `walk-in al ${s.walkin_charge === 'checkin' ? 'llegar' : 'salir'}`,
        `fianza ${s.guarantee_enabled ? 'activa' : 'apagada'}`,
    ].join(' · ');
});

const sectionCards = computed<
    {
        route: string;
        icon: Icon;
        title: string;
        description: string;
        line: string;
        ok: boolean | null;
    }[]
>(() => [
    {
        route: 'tenant.payment-methods.gateways',
        icon: 'CreditCard',
        title: 'Pasarelas de pago',
        description:
            'Stripe, Mercado Pago y PayPal con tus propias llaves: cobra con link y el dinero llega directo a tu cuenta.',
        line: gatewaysLine.value,
        ok: props.gatewaysSummary.active > 0,
    },
    {
        route: 'tenant.payment-methods.transfers',
        icon: 'Landmark',
        title: 'Pago por transferencia',
        description:
            'Cuentas bancarias que se comparten al huésped y los WhatsApp a donde manda su comprobante.',
        line: transferLine.value,
        ok: props.transferSummary.accounts_active > 0,
    },
    {
        route: 'tenant.payment-methods.terms',
        icon: 'Clock',
        title: 'Plazos y saldo',
        description:
            'Cuánto vive un apartado, el reloj de cada método de pago y cómo se cobra el saldo restante.',
        line: termsLine.value,
        ok: null,
    },
    {
        route: 'tenant.payment-methods.policies',
        icon: 'Scale',
        title: 'Políticas y cobros en recepción',
        description:
            'Política de cancelación con reembolso, cuándo se cobra un walk-in y la fianza al llegar.',
        line: policiesLine.value,
        ok: null,
    },
]);
</script>

<template>
    <RazeLayout title="Métodos de pago">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Wallet" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Métodos de pago</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cómo cobra tu hotel, con cada tema en su propia
                            página: pasarelas, transferencias, plazos y
                            políticas. Las usan el wizard de reservas, el
                            asistente IA y el panel.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando encima
                         de la tarjeta. -->
                    <Link
                        :href="route('tenant.hotel-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a Ajustes
                    </Link>
                    <Button
                        as="a"
                        :href="route('tenant.online-payments')"
                        variant="outline-primary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide icon="Landmark" class="mr-1.5 h-3.5 w-3.5" />
                        Ver cobros en línea
                    </Button>
                </div>
            </div>

            <!-- Estado: qué está listo y si el wizard de verdad va a cobrar -->
            <div class="box box--stacked mt-4 p-4">
                <div
                    class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Wallet" class="h-3.5 w-3.5" /> Estado de tus
                    cobros en línea
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[11px] font-medium"
                        :class="
                            gatewaysSummary.active > 0
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="
                                gatewaysSummary.active > 0
                                    ? 'CircleCheck'
                                    : 'CircleX'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            gatewaysSummary.active > 0
                                ? `${gatewaysSummary.active} pasarela(s) activa(s)`
                                : 'Sin pasarela activa'
                        }}
                    </span>
                    <span
                        class="flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[11px] font-medium"
                        :class="
                            transferSummary.accounts_active > 0
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="
                                transferSummary.accounts_active > 0
                                    ? 'CircleCheck'
                                    : 'CircleX'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            transferSummary.accounts_active > 0
                                ? `${transferSummary.accounts_active} cuenta(s) para transferencia`
                                : 'Sin cuentas para transferencia'
                        }}
                    </span>
                    <span
                        v-if="hasMotorWebModule"
                        class="flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[11px] font-medium"
                        :class="
                            wizardWillCharge
                                ? 'bg-success/10 text-success'
                                : 'bg-warning/10 text-warning'
                        "
                    >
                        <Lucide
                            :icon="
                                wizardWillCharge
                                    ? 'CircleCheck'
                                    : 'TriangleAlert'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            wizardWillCharge
                                ? 'El wizard pide pago en línea'
                                : 'El wizard NO pide pago en línea'
                        }}
                    </span>
                </div>

                <!-- La causa #1 de "conecté la pasarela y no aparece" -->
                <div
                    v-if="
                        hasMotorWebModule &&
                        !wizardWillCharge &&
                        (gatewaysSummary.active > 0 ||
                            transferSummary.accounts_active > 0)
                    "
                    class="mt-4 flex items-start gap-2 rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                    />
                    <span v-if="paymentMode === 'never'">
                        Tus métodos están listos, pero el modo de pago está en
                        "Nunca": el wizard jamás los ofrecerá al reservar. El
                        asistente y el panel sí pueden seguir cobrando con
                        ellos.
                    </span>
                    <span v-else>
                        Tus métodos están listos, pero en modo "Automático" el
                        wizard solo cobra cuando la tarifa elegida tiene
                        anticipo configurado — y hoy
                        <strong
                            >ninguna de tus {{ activeRatePlans }} tarifa(s)
                            activa(s) tiene anticipo</strong
                        >. Por eso las pasarelas no aparecen en el wizard.
                        Cambia el modo a "Siempre pedir pago en línea" abajo, o
                        configura un porcentaje de anticipo en tus tarifas.
                    </span>
                </div>
                <div
                    v-else-if="
                        gatewaysSummary.active === 0 &&
                        transferSummary.accounts_active === 0
                    "
                    class="mt-4 flex items-start gap-2 rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                >
                    <Lucide
                        icon="TriangleAlert"
                        class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                    />
                    Sin ningún método listo no se puede cobrar en línea: conecta
                    una pasarela o registra al menos una cuenta bancaria activa.
                </div>
            </div>

            <!-- Modo de pago del wizard: el interruptor maestro vive en el hub -->
            <div v-if="hasMotorWebModule" class="box box--stacked mt-4 p-4">
                <div
                    class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="ShoppingBag" class="h-3.5 w-3.5" />
                    Pago en el wizard de reservas
                </div>
                <p class="mb-4 text-xs text-slate-500">
                    Decide cuándo el wizard público pide pago en línea al
                    confirmar una reserva. Si hay más de un método listo, el
                    huésped elige con cuál pagar.
                </p>
                <div class="grid grid-cols-12 items-end gap-4">
                    <div class="col-span-12 xl:col-span-6">
                        <label class="mb-1 block text-sm font-medium"
                            >¿Cuándo pedir pago en línea al reservar?</label
                        >
                        <FormSelect
                            v-model="paymentMode"
                            :disabled="savingPaymentMode"
                            @change="savePaymentMode"
                            class="h-9 text-xs"
                        >
                            <option
                                v-for="(label, key) in paymentModeLabels"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </FormSelect>
                        <FormHelp>
                            "Automático": cada tarifa decide con su casilla
                            "Exigir cobro anticipado" (Catálogo → tarifas).
                            "Siempre" obliga a pagar en línea. "Nunca": todo se
                            paga al llegar. Con "Pago en el hotel" activo
                            (abajo), el huésped además puede elegir no pagar en
                            línea. Si la tarifa tiene anticipo, también elige
                            entre pagar el anticipo o el total.
                        </FormHelp>
                    </div>
                    <div class="col-span-12 xl:col-span-6">
                        <Link
                            :href="route('tenant.wizard-settings')"
                            class="flex items-center gap-1.5 text-sm font-medium text-primary hover:underline xl:justify-end xl:pb-7"
                        >
                            Más ajustes del wizard (extras, huéspedes)
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </Link>
                    </div>
                </div>

                <!-- Pago en el hotel (efectivo): apartar sin pagar en línea -->
                <div
                    class="mt-5 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                            >
                                <Lucide icon="Banknote" class="h-4 w-4" />
                            </div>
                            <div>
                                <div
                                    class="flex flex-wrap items-center gap-2 text-sm font-medium"
                                >
                                    Pago en el hotel (efectivo)
                                    <span
                                        v-if="!cashAllowedByPlatform"
                                        class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-medium text-danger"
                                        >Deshabilitado por plataforma</span
                                    >
                                </div>
                                <FormHelp>
                                    El huésped puede apartar sin pagar en línea
                                    y pagar al llegar, en recepción; tú
                                    confirmas el apartado. Aparece como opción
                                    en el paso de pago del wizard.
                                    <template v-if="paymentMode === 'never'">
                                        Con el modo "Nunca" el wizard no muestra
                                        paso de pago: todo se paga al llegar de
                                        por sí.
                                    </template>
                                </FormHelp>
                            </div>
                        </div>
                        <FormSwitch v-if="cashAllowedByPlatform" class="mt-1">
                            <FormSwitch.Input
                                :checked="cashEnabled"
                                :disabled="savingCash"
                                type="checkbox"
                                @change="toggleCashEnabled"
                            />
                        </FormSwitch>
                    </div>
                </div>
            </div>

            <!-- Cada tema de cobro, encapsulado en su propia página -->
            <div class="mt-4 grid grid-cols-12 gap-5">
                <Link
                    v-for="card in sectionCards"
                    :key="card.route"
                    :href="route(card.route)"
                    class="box box--stacked col-span-12 flex items-center gap-4 p-5 transition hover:border-primary/30 xl:col-span-6"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide :icon="card.icon" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium">{{ card.title }}</div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ card.description }}
                        </p>
                        <p
                            class="mt-1 text-xs"
                            :class="
                                card.ok === null
                                    ? 'text-slate-500'
                                    : card.ok
                                      ? 'text-success'
                                      : 'text-warning'
                            "
                        >
                            {{ card.line }}
                        </p>
                    </div>
                    <Lucide
                        icon="ArrowRight"
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                </Link>

                <!-- Los avisos al huésped no viven aquí: página propia -->
                <div class="col-span-12">
                    <Link
                        :href="route('tenant.guest-notices')"
                        class="box box--stacked flex items-center gap-4 p-5 transition hover:border-primary/30"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="BellRing" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium">
                                ¿Buscas los avisos al huésped?
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Los recordatorios de llegada, el agradecimiento
                                al salir, la encuesta y el link de reseñas
                                tienen su propia página en Ajustes.
                            </p>
                        </div>
                        <Lucide
                            icon="ArrowRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </Link>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
