<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface BankAccount {
    bank: string;
    holder: string;
    clabe: string;
    active: boolean;
}

interface GatewayLink {
    id: number;
    provider: string;
    provider_label: string;
    mode: string;
    public_key: string | null;
    masked_secret: string;
    has_webhook_secret: boolean;
    webhook_url: string;
    active: boolean;
    last_event_at: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        bank_accounts: BankAccount[];
        auto_confirm_on_payment: boolean;
        balance_request_days: number;
        cancel_on_balance_overdue: boolean;
        payment_mode: 'automatic' | 'always' | 'never';
        hold_value: number;
        hold_unit: string;
        transfer_valid_value: number;
        transfer_valid_unit: string;
        balance_due_enabled: boolean;
        balance_due_value: number;
        balance_due_unit: string;
        direct_notify_channel: 'auto' | 'meta' | 'evolution';
        arrival_reminder_enabled: boolean;
    };
    notifyChannels: { meta_whatsapp: boolean; evolution: boolean };
    gateways: GatewayLink[];
    gatewayProviders: Record<string, string>;
    enabledMethods: Record<string, boolean>;
    hasCobrosModule: boolean;
    hasMotorWebModule: boolean;
    maxGateways: number | null;
    ratePlansWithDeposit: number;
    activeRatePlans: number;
}>();

const toast = useToasts();

// ---- Pasarelas de pago (llaves propias del hotel) ----
const gateways = ref<GatewayLink[]>([...props.gateways]);
const gwBusy = ref<string | null>(null);
const gwForms = reactive<
    Record<
        string,
        {
            mode: string;
            public_key: string;
            secret_key: string;
            webhook_secret: string;
        }
    >
>({});

Object.keys(props.gatewayProviders).forEach((provider) => {
    const link = props.gateways.find((g) => g.provider === provider);
    gwForms[provider] = {
        mode: link?.mode ?? 'test',
        public_key: link?.public_key ?? '',
        secret_key: '',
        webhook_secret: '',
    };
});

const gatewayFor = (provider: string) =>
    gateways.value.find((g) => g.provider === provider);

// Filtro del admin (plataforma/hotel): un método apagado no se ofrece; una
// pasarela ya conectada pero deshabilitada queda visible solo informativa.
const providerAllowed = (provider: string) =>
    props.enabledMethods[provider] !== false;
// Array (no objeto) para que `provider` se tipe como string en el v-for:
// iterar las claves de un Record en la plantilla las degrada a string|number.
const visibleProviders = computed<{ provider: string; label: string }[]>(() =>
    Object.entries(props.gatewayProviders)
        .filter(([p]) => providerAllowed(p) || gatewayFor(p))
        .map(([provider, label]) => ({ provider, label })),
);

const activeGatewaysCount = computed(
    () => gateways.value.filter((g) => g.active).length,
);
const activeAccountsCount = computed(
    () => form.bank_accounts.filter((a) => a.active).length,
);

const gwFieldHints: Record<
    string,
    { public: string; secret: string; webhook: string }
> = {
    stripe: {
        public: 'Publishable key (pk_…)',
        secret: 'Secret key (sk_…)',
        webhook: 'Signing secret del webhook (whsec_…)',
    },
    mercadopago: {
        public: 'Public key (APP_USR-…)',
        secret: 'Access token (APP_USR-…)',
        webhook: 'Opcional: el evento se valida contra la API',
    },
    paypal: {
        public: 'Client ID',
        secret: 'Secret',
        webhook: 'Opcional: el pago se captura y valida contra la API',
    },
};

async function saveGateway(provider: string) {
    const gwForm = gwForms[provider];
    const existing = gatewayFor(provider);
    if (!existing && !gwForm.secret_key.trim()) {
        toast.error(
            'Falta la llave secreta',
            'Pega la llave secreta de tu cuenta para conectar.',
        );
        return;
    }
    gwBusy.value = provider;
    try {
        const payload = {
            mode: gwForm.mode,
            public_key: gwForm.public_key.trim() || null,
            secret_key: gwForm.secret_key.trim() || null,
            webhook_secret: gwForm.webhook_secret.trim() || null,
        };
        const { data } = existing
            ? await axios.patch<
                  GatewayLink & { test?: { ok: boolean; detail: string } }
              >(`/api/payment-gateways/${existing.id}`, payload)
            : await axios.post<
                  GatewayLink & { test?: { ok: boolean; detail: string } }
              >('/api/payment-gateways', {
                  ...payload,
                  provider,
                  secret_key: gwForm.secret_key.trim(),
              });
        gateways.value = [
            ...gateways.value.filter((g) => g.provider !== provider),
            data,
        ];
        gwForm.secret_key = '';
        gwForm.webhook_secret = '';
        if (data.test && !data.test.ok) {
            toast.error('Conectada, pero la prueba falló', data.test.detail);
        } else {
            toast.success(
                'Pasarela guardada',
                data.test?.detail ?? 'Credenciales actualizadas.',
            );
        }
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Revisa los datos de la pasarela.',
        );
    } finally {
        gwBusy.value = null;
    }
}

async function testGateway(link: GatewayLink) {
    gwBusy.value = link.provider;
    try {
        const { data } = await axios.post(
            `/api/payment-gateways/${link.id}/test`,
        );
        if (data.test.ok) {
            toast.success('Conexión correcta', data.test.detail);
        } else {
            toast.error('Fallo de conexión', data.test.detail);
        }
    } catch {
        toast.error('Error', 'No se pudo probar la conexión.');
    } finally {
        gwBusy.value = null;
    }
}

async function toggleGateway(link: GatewayLink) {
    try {
        const { data } = await axios.patch(`/api/payment-gateways/${link.id}`, {
            active: !link.active,
        });
        gateways.value = gateways.value.map((g) =>
            g.id === link.id ? { ...g, active: data.active } : g,
        );
        toast.success(
            data.active ? 'Pasarela activada' : 'Pasarela desactivada',
            data.canceled_requests
                ? `Se cancelaron ${data.canceled_requests} cobro(s) pendiente(s).`
                : undefined,
        );
    } catch {
        toast.error('Error', 'No se pudo cambiar el estado de la pasarela.');
    }
}

async function copyWebhookUrl(link: GatewayLink) {
    try {
        await navigator.clipboard.writeText(link.webhook_url);
        toast.success(
            'URL copiada',
            'Pégala como webhook en el dashboard del proveedor.',
        );
    } catch {
        toast.error('No se pudo copiar', link.webhook_url);
    }
}

// ---- Modo de pago del wizard (cuándo se pide pago en línea al reservar) ----
const savingPaymentMode = ref(false);
const paymentMode = ref(props.settings.payment_mode);
const paymentModeLabels: Record<string, string> = {
    automatic: 'Automático (lo decide cada tarifa con su anticipo)',
    always: 'Siempre pedir pago en línea',
    never: 'Nunca pedir pago en línea',
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

// ---- Cuentas bancarias, confirmación automática y saldos ----
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    bank_accounts: props.settings.bank_accounts.map((a) => ({
        ...a,
    })) as BankAccount[],
    auto_confirm_on_payment: props.settings.auto_confirm_on_payment,
    balance_request_days: props.settings.balance_request_days,
    cancel_on_balance_overdue: props.settings.cancel_on_balance_overdue,
    hold_value: props.settings.hold_value,
    hold_unit: props.settings.hold_unit,
    transfer_valid_value: props.settings.transfer_valid_value,
    transfer_valid_unit: props.settings.transfer_valid_unit,
    balance_due_enabled: props.settings.balance_due_enabled,
    balance_due_value: props.settings.balance_due_value,
    balance_due_unit: props.settings.balance_due_unit,
    direct_notify_channel: props.settings.direct_notify_channel,
    arrival_reminder_enabled: props.settings.arrival_reminder_enabled,
});

// Aviso si el canal elegido no está conectado (el envío caería en silencio).
const notifyChannelWarning = computed(() => {
    if (
        form.direct_notify_channel === 'meta' &&
        !props.notifyChannels.meta_whatsapp
    ) {
        return 'No tienes un canal de WhatsApp por Meta conectado: los avisos directos no saldrán hasta conectarlo en Asistente IA.';
    }
    if (
        form.direct_notify_channel === 'evolution' &&
        !props.notifyChannels.evolution
    ) {
        return 'No tienes una instancia de WhatsApp Evolution conectada: los avisos directos no saldrán hasta conectarla en Asistente IA.';
    }
    if (
        form.direct_notify_channel === 'auto' &&
        !props.notifyChannels.meta_whatsapp &&
        !props.notifyChannels.evolution
    ) {
        return 'No tienes ningún canal de WhatsApp conectado: los avisos directos solo saldrán por correo.';
    }
    return null;
});

function addBankAccount() {
    form.bank_accounts.push({ bank: '', holder: '', clabe: '', active: true });
}

function removeBankAccount(index: number) {
    form.bank_accounts.splice(index, 1);
}

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                bank_accounts: form.bank_accounts,
                auto_confirm_on_payment: form.auto_confirm_on_payment,
                balance_request_days: form.balance_request_days,
                cancel_on_balance_overdue: form.cancel_on_balance_overdue,
                hold_value: form.hold_value,
                hold_unit: form.hold_unit,
                transfer_valid_value: form.transfer_valid_value,
                transfer_valid_unit: form.transfer_valid_unit,
                balance_due_enabled: form.balance_due_enabled,
                balance_due_value: form.balance_due_value,
                balance_due_unit: form.balance_due_unit,
                direct_notify_channel: form.direct_notify_channel,
                arrival_reminder_enabled: form.arrival_reminder_enabled,
            },
        });
        toast.success('Guardado', 'Los métodos de pago se actualizaron.');
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) =>
                    (errors[key.replace('settings.', '')] = (
                        msgs as string[]
                    )[0]),
            );
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Métodos de pago">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-medium">Métodos de pago</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Área aislada: pasarelas, cuentas para transferencia y
                        reglas de cobro en un solo lugar. Las usan el wizard de
                        reservas, el asistente IA y el panel.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.hotel-settings')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="ArrowLeft"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Volver a Ajustes
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.online-payments')"
                        variant="outline-primary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide
                            icon="Landmark"
                            class="mr-2 h-4 w-4 stroke-[1.3]"
                        />
                        Ver cobros en línea
                    </Button>
                </div>
            </div>

            <!-- Estado: qué está listo y si el wizard de verdad va a cobrar -->
            <div class="box box--stacked mt-5 p-5">
                <div
                    class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Wallet" class="h-3.5 w-3.5" /> Estado de tus
                    cobros en línea
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium"
                        :class="
                            activeGatewaysCount > 0
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="
                                activeGatewaysCount > 0
                                    ? 'CircleCheck'
                                    : 'CircleX'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            activeGatewaysCount > 0
                                ? `${activeGatewaysCount} pasarela(s) activa(s)`
                                : 'Sin pasarela activa'
                        }}
                    </span>
                    <span
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium"
                        :class="
                            activeAccountsCount > 0
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="
                                activeAccountsCount > 0
                                    ? 'CircleCheck'
                                    : 'CircleX'
                            "
                            class="h-3.5 w-3.5"
                        />
                        {{
                            activeAccountsCount > 0
                                ? `${activeAccountsCount} cuenta(s) para transferencia`
                                : 'Sin cuentas para transferencia'
                        }}
                    </span>
                    <span
                        v-if="hasMotorWebModule"
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium"
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
                        (activeGatewaysCount > 0 || activeAccountsCount > 0)
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
                        activeGatewaysCount === 0 && activeAccountsCount === 0
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

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Modo de pago del wizard -->
                <div v-if="hasMotorWebModule" class="col-span-12">
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="ShoppingBag" class="h-3.5 w-3.5" />
                            Pago en el wizard de reservas
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Decide cuándo el wizard público pide pago en línea
                            al confirmar una reserva. Si hay más de un método
                            listo, el huésped elige con cuál pagar.
                        </p>
                        <div class="grid grid-cols-12 items-end gap-4">
                            <div class="col-span-12 xl:col-span-6">
                                <label class="mb-1 block text-sm font-medium"
                                    >¿Cuándo pedir pago en línea al
                                    reservar?</label
                                >
                                <FormSelect
                                    v-model="paymentMode"
                                    :disabled="savingPaymentMode"
                                    @change="savePaymentMode"
                                >
                                    <option
                                        v-for="(
                                            label, key
                                        ) in paymentModeLabels"
                                        :key="key"
                                        :value="key"
                                    >
                                        {{ label }}
                                    </option>
                                </FormSelect>
                                <FormHelp>
                                    "Automático" es el default: cada tarifa
                                    decide según su propio anticipo configurado.
                                    "Siempre"/"Nunca" lo fuerzan para todas las
                                    reservas, sin importar la tarifa.
                                </FormHelp>
                            </div>
                            <div class="col-span-12 xl:col-span-6">
                                <Link
                                    :href="route('tenant.wizard-settings')"
                                    class="flex items-center gap-1.5 text-sm font-medium text-primary hover:underline xl:justify-end xl:pb-7"
                                >
                                    Más ajustes del wizard (extras, huéspedes)
                                    <Lucide
                                        icon="ArrowRight"
                                        class="h-3.5 w-3.5"
                                    />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pasarelas de pago -->
                <div class="col-span-12">
                    <div class="box box--stacked p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CreditCard" class="h-3.5 w-3.5" />
                            Pasarelas de pago
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Conecta tus cuentas para cobrar con link de pago: el
                            dinero llega directo a tu cuenta del proveedor.
                            <span class="font-medium"
                                >Con una pasarela activa, el asistente envía
                                links en lugar de datos de transferencia.</span
                            >
                            <span v-if="maxGateways !== null">
                                Tu plan permite hasta
                                {{ maxGateways }} pasarela(s)
                                conectada(s).</span
                            >
                        </p>

                        <div
                            v-if="!hasCobrosModule"
                            class="flex items-start gap-3 rounded-lg border border-dashed border-slate-300/70 p-5 dark:border-darkmode-400"
                        >
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400/50"
                            >
                                <Lucide icon="Lock" class="h-4 w-4" />
                            </div>
                            <div class="text-sm text-slate-500">
                                <div
                                    class="font-medium text-slate-600 dark:text-slate-300"
                                >
                                    Necesitas el módulo Cobros en línea
                                </div>
                                <p class="mt-0.5 text-xs">
                                    Tu plan no incluye pasarelas de pago; puedes
                                    cobrar por transferencia con las cuentas de
                                    abajo. Solicita la activación del módulo
                                    desde la tarjeta
                                    <Link
                                        :href="route('tenant.hotel-settings')"
                                        class="font-medium text-primary hover:underline"
                                        >Tu plan en Ajustes</Link
                                    >.
                                </p>
                            </div>
                        </div>

                        <div v-else class="grid grid-cols-12 gap-4">
                            <div
                                v-for="{ provider, label } in visibleProviders"
                                :key="provider"
                                class="col-span-12 rounded-lg border border-slate-200/70 p-4 xl:col-span-6 dark:border-darkmode-400"
                            >
                                <div class="flex items-center gap-2.5">
                                    <div
                                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                                    >
                                        <Lucide
                                            icon="CreditCard"
                                            class="h-4 w-4 text-primary"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium">{{
                                                label
                                            }}</span>
                                            <span
                                                v-if="
                                                    gatewayFor(provider)?.active
                                                "
                                                class="rounded-full bg-success/10 px-2 py-0.5 text-[10px] font-medium text-success"
                                                >Activa</span
                                            >
                                            <span
                                                v-else-if="gatewayFor(provider)"
                                                class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                                >Pausada</span
                                            >
                                            <span
                                                v-if="
                                                    gatewayFor(provider)
                                                        ?.mode === 'test'
                                                "
                                                class="rounded-full bg-warning/10 px-2 py-0.5 text-[10px] font-medium text-warning"
                                                >Modo prueba</span
                                            >
                                            <span
                                                v-if="
                                                    !providerAllowed(provider)
                                                "
                                                class="rounded-full bg-danger/10 px-2 py-0.5 text-[10px] font-medium text-danger"
                                                >Deshabilitada por
                                                plataforma</span
                                            >
                                        </div>
                                        <p
                                            v-if="
                                                gatewayFor(provider)
                                                    ?.last_event_at
                                            "
                                            class="text-xs text-slate-500"
                                        >
                                            Último evento del webhook:
                                            {{
                                                gatewayFor(provider)
                                                    ?.last_event_at
                                            }}
                                        </p>
                                        <p
                                            v-else-if="gatewayFor(provider)"
                                            class="text-xs text-slate-500"
                                        >
                                            Aún sin eventos del webhook.
                                        </p>
                                    </div>
                                    <FormSwitch
                                        v-if="
                                            gatewayFor(provider) &&
                                            providerAllowed(provider)
                                        "
                                        title="Pausar deja de ofrecer esta pasarela y cancela sus cobros pendientes"
                                    >
                                        <FormSwitch.Input
                                            :checked="
                                                gatewayFor(provider)?.active
                                            "
                                            type="checkbox"
                                            @change="
                                                toggleGateway(
                                                    gatewayFor(provider)!,
                                                )
                                            "
                                        />
                                    </FormSwitch>
                                </div>

                                <div
                                    v-if="providerAllowed(provider)"
                                    class="mt-4 space-y-3"
                                >
                                    <div>
                                        <label class="mb-1 block text-sm">{{
                                            gwFieldHints[provider]?.public
                                        }}</label>
                                        <FormInput
                                            v-model="
                                                gwForms[provider].public_key
                                            "
                                            type="text"
                                            placeholder="Llave pública"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm">{{
                                            gwFieldHints[provider]?.secret
                                        }}</label>
                                        <FormInput
                                            v-model="
                                                gwForms[provider].secret_key
                                            "
                                            type="password"
                                            :placeholder="
                                                gatewayFor(provider)
                                                    ? `Guardada (${gatewayFor(provider)?.masked_secret}) — escribe para reemplazar`
                                                    : 'Llave secreta'
                                            "
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm"
                                            >Secreto del webhook</label
                                        >
                                        <FormInput
                                            v-model="
                                                gwForms[provider].webhook_secret
                                            "
                                            type="password"
                                            :placeholder="
                                                gatewayFor(provider)
                                                    ?.has_webhook_secret
                                                    ? 'Guardado — escribe para reemplazar'
                                                    : gwFieldHints[provider]
                                                          ?.webhook
                                            "
                                        />
                                        <FormHelp>{{
                                            gwFieldHints[provider]?.webhook
                                        }}</FormHelp>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm"
                                            >Modo</label
                                        >
                                        <FormSelect
                                            v-model="gwForms[provider].mode"
                                            class="!py-1.5 text-sm"
                                        >
                                            <option value="test">
                                                Prueba (sandbox)
                                            </option>
                                            <option value="live">
                                                Producción
                                            </option>
                                        </FormSelect>
                                    </div>

                                    <div
                                        v-if="gatewayFor(provider)"
                                        class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 dark:bg-darkmode-700"
                                    >
                                        <span
                                            class="min-w-0 flex-1 truncate font-mono text-xs text-slate-500"
                                            :title="
                                                gatewayFor(provider)
                                                    ?.webhook_url
                                            "
                                            >{{
                                                gatewayFor(provider)
                                                    ?.webhook_url
                                            }}</span
                                        >
                                        <button
                                            type="button"
                                            class="shrink-0 rounded p-1 text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                            title="Copiar URL del webhook"
                                            @click="
                                                copyWebhookUrl(
                                                    gatewayFor(provider)!,
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="Copy"
                                                class="h-3.5 w-3.5"
                                            />
                                        </button>
                                    </div>

                                    <div class="flex items-center gap-2 pt-1">
                                        <Button
                                            variant="primary"
                                            size="sm"
                                            class="rounded-[0.5rem]"
                                            :disabled="gwBusy === provider"
                                            @click="saveGateway(provider)"
                                        >
                                            <Lucide
                                                icon="Check"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            {{
                                                gwBusy === provider
                                                    ? 'Guardando…'
                                                    : gatewayFor(provider)
                                                      ? 'Guardar cambios'
                                                      : 'Conectar'
                                            }}
                                        </Button>
                                        <Button
                                            v-if="gatewayFor(provider)"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="rounded-[0.5rem] bg-white"
                                            title="Prueba las llaves GUARDADAS — si acabas de escribirlas, primero usa Guardar cambios"
                                            :disabled="gwBusy === provider"
                                            @click="
                                                testGateway(
                                                    gatewayFor(provider)!,
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="PlugZap"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Probar conexión
                                        </Button>
                                    </div>
                                    <FormHelp v-if="gatewayFor(provider)">
                                        "Probar conexión" usa las llaves
                                        guardadas: si acabas de escribir llaves
                                        nuevas, guarda primero.
                                    </FormHelp>
                                </div>
                                <p
                                    v-else
                                    class="mt-3 flex items-start gap-1.5 text-xs text-slate-400"
                                >
                                    <Lucide
                                        icon="Info"
                                        class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                    />
                                    La plataforma deshabilitó esta pasarela; sus
                                    cobros pendientes se pausaron. Contacta a
                                    soporte si la necesitas.
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-4 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="ShieldCheck"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span
                                >Las llaves se guardan cifradas. Registra la URL
                                del webhook en el dashboard del proveedor (en
                                Stripe copia también el signing secret) para que
                                los pagos se confirmen solos. Nadie del hotel ni
                                el asistente ven datos de tarjeta: el cobro
                                ocurre en la página del proveedor.</span
                            >
                        </div>
                    </div>
                </div>

                <!-- Cuentas bancarias y reglas de cobro -->
                <div class="col-span-12">
                    <form
                        class="box box--stacked flex flex-col p-5"
                        @submit.prevent="submit"
                    >
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Landmark" class="h-3.5 w-3.5" />
                            Cuentas bancarias y reglas de cobro
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Cuentas que se comparten al huésped para pagar por
                            transferencia (anticipos y saldos).
                            <span class="font-medium"
                                >El asistente IA las entrega tal cual al
                                solicitar un pago; el comprobante siempre lo
                                verifica tu equipo.</span
                            >
                        </p>

                        <p
                            v-if="enabledMethods.transfer === false"
                            class="mb-4 flex items-start gap-1.5 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                            />
                            Las transferencias bancarias no están habilitadas
                            para tu hotel; el asistente no las ofrecerá.
                            Contacta a la plataforma si las necesitas.
                        </p>

                        <div
                            v-if="
                                enabledMethods.transfer !== false &&
                                form.bank_accounts.length
                            "
                            class="flex flex-col gap-3"
                        >
                            <div
                                v-for="(account, index) in form.bank_accounts"
                                :key="index"
                                class="grid grid-cols-12 items-end gap-3 rounded-lg border border-slate-200/70 p-3 dark:border-darkmode-400"
                            >
                                <div class="col-span-12 sm:col-span-3">
                                    <label class="mb-1 block text-sm"
                                        >Banco</label
                                    >
                                    <FormInput
                                        v-model="account.bank"
                                        type="text"
                                        placeholder="BBVA"
                                    />
                                    <FormHelp
                                        v-if="
                                            errors[
                                                `bank_accounts.${index}.bank`
                                            ]
                                        "
                                        class="text-danger"
                                        >{{
                                            errors[
                                                `bank_accounts.${index}.bank`
                                            ]
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-4">
                                    <label class="mb-1 block text-sm"
                                        >Titular</label
                                    >
                                    <FormInput
                                        v-model="account.holder"
                                        type="text"
                                        placeholder="Hotel Demo Centro SA de CV"
                                    />
                                    <FormHelp
                                        v-if="
                                            errors[
                                                `bank_accounts.${index}.holder`
                                            ]
                                        "
                                        class="text-danger"
                                        >{{
                                            errors[
                                                `bank_accounts.${index}.holder`
                                            ]
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-3">
                                    <label class="mb-1 block text-sm"
                                        >CLABE / cuenta</label
                                    >
                                    <FormInput
                                        v-model="account.clabe"
                                        type="text"
                                        placeholder="18 dígitos"
                                    />
                                    <FormHelp
                                        v-if="
                                            errors[
                                                `bank_accounts.${index}.clabe`
                                            ]
                                        "
                                        class="text-danger"
                                        >{{
                                            errors[
                                                `bank_accounts.${index}.clabe`
                                            ]
                                        }}</FormHelp
                                    >
                                </div>
                                <div
                                    class="col-span-12 flex items-center justify-between gap-3 sm:col-span-2 sm:justify-end"
                                >
                                    <FormSwitch
                                        title="Solo las cuentas activas se comparten al huésped"
                                    >
                                        <FormSwitch.Input
                                            :checked="account.active"
                                            type="checkbox"
                                            @change="
                                                account.active = !account.active
                                            "
                                        />
                                    </FormSwitch>
                                    <button
                                        type="button"
                                        class="rounded p-1.5 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                        title="Quitar cuenta"
                                        @click="removeBankAccount(index)"
                                    >
                                        <Lucide icon="Trash2" class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div
                            v-else-if="enabledMethods.transfer !== false"
                            class="rounded-lg border border-dashed border-slate-300/70 px-4 py-6 text-center text-sm text-slate-500 dark:border-darkmode-400"
                        >
                            Sin cuentas registradas: no se podrá ofrecer pago
                            por transferencia.
                        </div>

                        <div
                            v-if="enabledMethods.transfer !== false"
                            class="mt-3"
                        >
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="rounded-[0.5rem] bg-white"
                                @click="addBankAccount"
                            >
                                <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                                Agregar cuenta
                            </Button>
                        </div>

                        <!-- Plazos: cuánto vive un apartado y un cobro por transferencia -->
                        <div
                            class="mt-6 mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Clock" class="h-3.5 w-3.5" /> Plazos
                        </div>
                        <div class="grid grid-cols-12 gap-3">
                            <div
                                class="col-span-12 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 xl:col-span-6 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div class="text-sm font-medium">
                                    Duración del apartado
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Cuánto vive una reserva pendiente antes de
                                    liberarse sola si nadie la confirma ni la
                                    paga. Aplica al wizard, al asistente y al
                                    panel.
                                </p>
                                <div class="mt-2 flex items-center gap-2">
                                    <FormInput
                                        v-model.number="form.hold_value"
                                        type="number"
                                        min="1"
                                        max="999"
                                        class="!w-24 text-center"
                                    />
                                    <FormSelect
                                        v-model="form.hold_unit"
                                        class="!w-40"
                                    >
                                        <option value="minute">Minutos</option>
                                        <option value="hour">Horas</option>
                                        <option value="day">Días</option>
                                        <option value="week">Semanas</option>
                                    </FormSelect>
                                </div>
                                <FormHelp
                                    v-if="errors.hold_value || errors.hold_unit"
                                    class="text-danger"
                                    >{{
                                        errors.hold_value ?? errors.hold_unit
                                    }}</FormHelp
                                >
                            </div>
                            <div
                                class="col-span-12 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 xl:col-span-6 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div class="text-sm font-medium">
                                    Vigencia de un cobro por transferencia
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Cuánto tiempo tiene el huésped para
                                    transferir y mandar su comprobante. Mientras
                                    el cobro viva, el apartado se extiende con
                                    él.
                                </p>
                                <div class="mt-2 flex items-center gap-2">
                                    <FormInput
                                        v-model.number="
                                            form.transfer_valid_value
                                        "
                                        type="number"
                                        min="1"
                                        max="999"
                                        class="!w-24 text-center"
                                    />
                                    <FormSelect
                                        v-model="form.transfer_valid_unit"
                                        class="!w-40"
                                    >
                                        <option value="hour">Horas</option>
                                        <option value="day">Días</option>
                                        <option value="week">Semanas</option>
                                    </FormSelect>
                                </div>
                                <FormHelp
                                    v-if="
                                        errors.transfer_valid_value ||
                                        errors.transfer_valid_unit
                                    "
                                    class="text-danger"
                                    >{{
                                        errors.transfer_valid_value ??
                                        errors.transfer_valid_unit
                                    }}</FormHelp
                                >
                            </div>
                        </div>

                        <!-- Fecha límite de pago total: interruptor del módulo + default -->
                        <div
                            class="mt-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="text-sm">
                                    <div class="font-medium">
                                        Exigir el pago total antes de la llegada
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Cada reserva nace con una fecha límite
                                        para cubrir el total. Si la tarifa
                                        define su propia anticipación, esa
                                        manda; si no, se usa el plazo de aquí.
                                        Apagado: nadie tiene fecha límite y no
                                        se piden saldos automáticos.
                                    </p>
                                </div>
                                <FormSwitch class="mt-1">
                                    <FormSwitch.Input
                                        :checked="form.balance_due_enabled"
                                        type="checkbox"
                                        @change="
                                            form.balance_due_enabled =
                                                !form.balance_due_enabled
                                        "
                                    />
                                </FormSwitch>
                            </div>
                            <div
                                v-if="form.balance_due_enabled"
                                class="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400"
                            >
                                <span class="text-xs text-slate-500"
                                    >Plazo default (cuando la tarifa no define
                                    el suyo):</span
                                >
                                <FormInput
                                    v-model.number="form.balance_due_value"
                                    type="number"
                                    min="1"
                                    max="365"
                                    class="!w-24 text-center"
                                />
                                <FormSelect
                                    v-model="form.balance_due_unit"
                                    class="!w-40"
                                >
                                    <option value="day">Días</option>
                                    <option value="week">Semanas</option>
                                </FormSelect>
                                <span class="text-xs text-slate-500"
                                    >antes de la llegada</span
                                >
                            </div>
                        </div>

                        <!-- Canal de avisos directos (huésped sin conversación: wizard web) -->
                        <div
                            class="mt-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-4"
                            >
                                <div class="text-sm">
                                    <div class="font-medium">
                                        Canal para avisos directos al huésped
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Confirmaciones y avisos de pago para
                                        reservas del wizard (huéspedes que nunca
                                        han escrito por un canal). Con
                                        "Automático" se intenta la API oficial
                                        de Meta y, si no puede entregar, sale
                                        por Evolution.
                                    </p>
                                </div>
                                <FormSelect
                                    v-model="form.direct_notify_channel"
                                    class="!w-64 shrink-0"
                                >
                                    <option value="auto">
                                        Automático (Meta y respaldo Evolution)
                                    </option>
                                    <option value="meta">
                                        Solo Meta (API oficial de WhatsApp)
                                    </option>
                                    <option value="evolution">
                                        Solo Evolution
                                    </option>
                                </FormSelect>
                            </div>
                            <p
                                v-if="notifyChannelWarning"
                                class="mt-2 flex items-start gap-1.5 text-xs text-warning"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                />
                                {{ notifyChannelWarning }}
                            </p>
                            <p
                                v-else-if="
                                    form.direct_notify_channel === 'meta'
                                "
                                class="mt-2 flex items-start gap-1.5 text-xs text-slate-500"
                            >
                                <Lucide
                                    icon="Info"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                />
                                La API oficial solo entrega mensajes libres
                                dentro de las 24 horas después de que el huésped
                                escribe; para huéspedes que nunca han escrito
                                puede requerir una plantilla aprobada por Meta.
                            </p>
                        </div>

                        <div
                            class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="text-sm">
                                <div class="font-medium">
                                    Recordatorio de llegada
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Un aviso automático al huésped 24 horas
                                    antes de su llegada, con su código y
                                    horario. Sale por la conversación si existe,
                                    o por el canal directo de arriba.
                                </p>
                            </div>
                            <FormSwitch class="mt-1">
                                <FormSwitch.Input
                                    :checked="form.arrival_reminder_enabled"
                                    type="checkbox"
                                    @change="
                                        form.arrival_reminder_enabled =
                                            !form.arrival_reminder_enabled
                                    "
                                />
                            </FormSwitch>
                        </div>

                        <div
                            class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="text-sm">
                                <div class="font-medium">
                                    Confirmar reservas automáticamente al
                                    recibir el pago
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Cuando un pago verificado cubre el anticipo,
                                    la reserva se confirma sola y se avisa al
                                    huésped. Apágalo si prefieres confirmar cada
                                    reserva a mano.
                                </p>
                            </div>
                            <FormSwitch class="mt-1">
                                <FormSwitch.Input
                                    :checked="form.auto_confirm_on_payment"
                                    type="checkbox"
                                    @change="
                                        form.auto_confirm_on_payment =
                                            !form.auto_confirm_on_payment
                                    "
                                />
                            </FormSwitch>
                        </div>

                        <div
                            v-if="form.balance_due_enabled"
                            class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="text-sm">
                                <div class="font-medium">
                                    Cobro automático del saldo
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    El asistente pide el saldo por el chat con
                                    esta anticipación a la fecha límite y
                                    recuerda 24 horas antes de que venza.
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <FormInput
                                    v-model.number="form.balance_request_days"
                                    type="number"
                                    min="1"
                                    max="30"
                                    class="!w-20 text-center"
                                />
                                <span class="text-xs text-slate-500"
                                    >días antes</span
                                >
                            </div>
                        </div>

                        <div
                            v-if="form.balance_due_enabled"
                            class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="text-sm">
                                <div class="font-medium">
                                    Cancelar reservas con saldo vencido
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Apagado (recomendado): el saldo vencido solo
                                    genera una alerta en la bandeja y tu equipo
                                    decide. Encendido: la reserva se cancela
                                    sola al vencer y se avisa al huésped.
                                </p>
                            </div>
                            <FormSwitch class="mt-1">
                                <FormSwitch.Input
                                    :checked="form.cancel_on_balance_overdue"
                                    type="checkbox"
                                    @change="
                                        form.cancel_on_balance_overdue =
                                            !form.cancel_on_balance_overdue
                                    "
                                />
                            </FormSwitch>
                        </div>

                        <div class="mt-5 flex justify-end">
                            <Button
                                type="submit"
                                variant="primary"
                                class="rounded-[0.5rem] shadow-md shadow-primary/20"
                                :disabled="saving"
                            >
                                <Lucide icon="Check" class="mr-2 h-4 w-4" />
                                {{ saving ? 'Guardando…' : 'Guardar' }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
