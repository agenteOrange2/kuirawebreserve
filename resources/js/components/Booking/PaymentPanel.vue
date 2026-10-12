<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import { formatMoney } from '@/lib/bookingFormat';
import type { BankAccount } from './BankAccounts.vue';
import BankAccounts from './BankAccounts.vue';

interface GatewayOption {
    provider: 'stripe' | 'mercadopago' | 'paypal';
    label: string;
}

interface PaymentOptions {
    gateways: GatewayOption[];
    transfer: {
        available: boolean;
        accounts_count: number;
        notice?: string | null;
    };
}

interface PaymentResult {
    method: 'gateway' | 'transfer';
    provider?: string;
    amount: number;
    amount_label: string;
    checkout_url?: string;
    bank_accounts?: BankAccount[];
    whatsapps?: string[];
    valid_hours?: number;
    valid_label?: string | null;
    return_url: string;
}

/**
 * El cobro de un apartado, igual en habitaciones, grupos y experiencias.
 * Antes eran tres copias que fallaban distinto: en grupos, si la consulta
 * de métodos fallaba o el hotel no tenía ninguno, la pantalla se quedaba
 * girando "Te estamos llevando a la página de pago" para siempre.
 *
 * Con anticipo el huésped elige cuánto paga hoy; con efectivo activo,
 * también puede pagar en el hotel (emite `pay-later`). Elegida la
 * transferencia puede volver a cambiar de método: el servidor cancela el
 * cobro anterior al emitir el nuevo.
 */
const props = defineProps<{
    code: string;
    total: number;
    deposit: number;
    currency: string;
    paymentOptional: boolean;
    balanceNotice?: string | null;
    expired?: boolean;
    phone?: string | null;
    urls: { options: string; payment: string; payLater: string | null };
    // Texto cuando el hotel no tiene métodos en línea.
    contactNotice?: string;
}>();

const emit = defineEmits<{
    (e: 'pay-later', holdExpiresAt: string | null): void;
}>();

const money = (n: number) => formatMoney(n, props.currency);

type State = 'loading' | 'choice' | 'transfer' | 'redirect' | 'error' | 'none';
const state = ref<State>('loading');
const options = ref<PaymentOptions | null>(null);
const payment = ref<PaymentResult | null>(null);
const error = ref<string | null>(null);
const payAmount = ref<'deposit' | 'full'>('deposit');

const depositApplies = computed(
    () => props.deposit > 0 && props.deposit < props.total,
);
const methodsCount = computed(
    () =>
        (options.value?.gateways.length ?? 0) +
        Number(options.value?.transfer.available ?? false),
);

async function start() {
    state.value = 'loading';
    error.value = null;
    payment.value = null;
    payAmount.value = 'deposit';
    try {
        const { data } = await axios.get<PaymentOptions>(props.urls.options);
        options.value = data;
    } catch {
        options.value = null;
    }

    const count = methodsCount.value;
    if (count === 0) {
        // Sin métodos en línea: con efectivo activo se confirma directo y
        // se paga en el hotel; si no, el hotel contacta para cobrar.
        if (props.paymentOptional && props.urls.payLater) {
            await payLater();
            return;
        }
        state.value = options.value ? 'none' : 'error';
        if (!options.value) {
            error.value =
                'No pudimos consultar las formas de pago. Tu apartado sigue vigente.';
        }
        return;
    }

    // Con anticipo o efectivo activo se muestra la elección aunque solo
    // haya un método: el huésped decide cuánto y si paga ahora.
    if (count >= 2 || depositApplies.value || props.paymentOptional) {
        state.value = 'choice';
        return;
    }

    if (options.value?.gateways.length === 1) {
        await request('gateway', options.value.gateways[0].provider);
    } else {
        await request('transfer');
    }
}

async function request(
    method: 'gateway' | 'transfer',
    provider?: GatewayOption['provider'],
) {
    state.value = 'loading';
    error.value = null;
    try {
        const { data } = await axios.post<PaymentResult>(props.urls.payment, {
            method,
            provider,
            pay: depositApplies.value ? payAmount.value : undefined,
        });
        payment.value = data;
        if (data.method === 'gateway' && data.checkout_url) {
            state.value = 'redirect';
            window.location.href = data.checkout_url;
            return;
        }
        state.value = 'transfer';
    } catch (e: any) {
        error.value =
            e?.response?.data?.message ??
            'No se pudo generar el cobro. Tu apartado sigue vigente.';
        state.value = 'error';
    }
}

async function payLater() {
    let expiresAt: string | null = null;
    if (props.urls.payLater) {
        try {
            const { data } = await axios.post<{
                hold_expires_at: string | null;
            }>(props.urls.payLater);
            expiresAt = data.hold_expires_at;
        } catch {
            // Sin extensión: el plazo corto del apartado sigue mandando.
        }
    }
    emit('pay-later', expiresAt);
}

defineExpose({ start });

// ── Copiar cuenta y código ──
const copied = ref<string | null>(null);
async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text.replace(/\s/g, ''));
        copied.value = text;
        window.setTimeout(() => (copied.value = null), 2000);
    } catch {
        // El número sigue a la vista si el navegador no deja copiar.
    }
}

const methodRow =
    'flex w-full items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-primary/40 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50';
const sectionLabel =
    'mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase';
</script>

<template>
    <div>
        <!-- Apartado vencido: no se ofrece cobrar algo que ya se liberó -->
        <div
            v-if="expired"
            class="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm text-slate-700"
        >
            <Lucide
                icon="TimerOff"
                class="mt-0.5 h-5 w-5 shrink-0 text-warning"
            />
            <div>
                <div class="font-medium text-slate-800">Tu apartado venció</div>
                <p class="mt-0.5 text-slate-600">
                    El tiempo para pagar terminó y el espacio se liberó. Puedes
                    hacer una reserva nueva; si ya pagaste, comunícate con el
                    hotel con tu código {{ code }}.
                </p>
            </div>
        </div>

        <div
            v-else-if="state === 'loading'"
            class="flex flex-col items-center gap-3 py-10 text-center"
        >
            <Lucide
                icon="LoaderCircle"
                class="h-8 w-8 animate-spin text-primary"
            />
            <p class="text-sm text-slate-500">Preparando tu cobro…</p>
        </div>

        <!-- Elegir cuánto y cómo -->
        <div v-else-if="state === 'choice' && options" class="space-y-5">
            <div v-if="depositApplies">
                <div :class="sectionLabel">¿Cuánto pagas hoy?</div>
                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <button
                        v-for="choice in ['deposit', 'full'] as const"
                        :key="choice"
                        type="button"
                        class="relative rounded-xl border p-4 text-left transition"
                        :class="
                            payAmount === choice
                                ? 'border-primary bg-primary/5 ring-2 ring-primary/15'
                                : 'border-slate-200 hover:border-primary/40'
                        "
                        :aria-pressed="payAmount === choice"
                        @click="payAmount = choice"
                    >
                        <span
                            class="absolute top-4 right-4 flex h-5 w-5 items-center justify-center rounded-full border-2"
                            :class="
                                payAmount === choice
                                    ? 'border-primary bg-primary text-white'
                                    : 'border-slate-300'
                            "
                        >
                            <Lucide
                                v-if="payAmount === choice"
                                icon="Check"
                                class="h-3 w-3"
                            />
                        </span>
                        <div class="pr-7 text-sm font-medium text-slate-700">
                            {{
                                choice === 'deposit'
                                    ? 'Solo el anticipo'
                                    : 'Todo de una vez'
                            }}
                        </div>
                        <div class="mt-1 text-lg font-semibold text-slate-800">
                            {{ money(choice === 'deposit' ? deposit : total) }}
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            {{
                                choice === 'deposit'
                                    ? (balanceNotice ??
                                      `Restan ${money(total - deposit)}; los pagas después por link o transferencia.`)
                                    : 'Liquidas hoy y te olvidas de saldos.'
                            }}
                        </div>
                    </button>
                </div>
            </div>

            <div>
                <div :class="sectionLabel">¿Cómo prefieres pagar?</div>
                <div class="space-y-2.5">
                    <button
                        v-for="gw in options.gateways"
                        :key="gw.provider"
                        type="button"
                        :class="methodRow"
                        @click="request('gateway', gw.provider)"
                    >
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="CreditCard" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-medium text-slate-800"
                                >Tarjeta con {{ gw.label }}</span
                            >
                            <span class="block text-xs text-slate-500"
                                >Pago seguro en su página · se confirma al
                                instante</span
                            >
                        </span>
                        <Lucide
                            icon="ChevronRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </button>
                    <button
                        v-if="options.transfer.available"
                        type="button"
                        :class="methodRow"
                        @click="request('transfer')"
                    >
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-info/10 bg-info/10 text-info"
                        >
                            <Lucide icon="Landmark" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-medium text-slate-800"
                                >Transferencia bancaria</span
                            >
                            <span class="block text-xs text-slate-500"
                                >Te damos la cuenta y mandas tu comprobante · el
                                hotel lo verifica</span
                            >
                        </span>
                        <Lucide
                            icon="ChevronRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </button>
                    <button
                        v-if="paymentOptional"
                        type="button"
                        :class="methodRow"
                        @click="payLater"
                    >
                        <span
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-slate-500"
                        >
                            <Lucide icon="Banknote" class="h-5 w-5" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-medium text-slate-800"
                                >Pagar en el hotel</span
                            >
                            <span class="block text-xs text-slate-500"
                                >Pagas al llegar, en recepción; el hotel
                                confirma tu apartado</span
                            >
                        </span>
                        <Lucide
                            icon="ChevronRight"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                    </button>
                </div>
                <p
                    v-if="
                        options.transfer.notice && !options.transfer.available
                    "
                    class="mt-2.5 flex items-start gap-1.5 text-xs text-slate-500"
                >
                    <Lucide icon="Clock" class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    {{ options.transfer.notice }}
                </p>
            </div>
        </div>

        <!-- Transferencia: cuánto, a dónde, y qué sigue -->
        <div v-else-if="state === 'transfer' && payment" class="space-y-4">
            <div
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-info/10 px-4 py-3.5"
            >
                <div>
                    <div class="text-xs text-slate-600">Transfiere</div>
                    <div class="text-xl font-semibold text-slate-800">
                        {{ payment.amount_label }}
                    </div>
                </div>
                <div class="text-right text-xs text-slate-600">
                    <div>Con el concepto</div>
                    <button
                        type="button"
                        class="mt-0.5 inline-flex items-center gap-1 font-mono text-sm font-semibold text-slate-800"
                        title="Copiar código"
                        @click="copy(code)"
                    >
                        {{ code }}
                        <Lucide
                            :icon="copied === code ? 'Check' : 'Copy'"
                            class="h-3.5 w-3.5 text-slate-500"
                        />
                    </button>
                </div>
            </div>

            <ol class="space-y-4">
                <li class="flex items-start gap-3">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                        >1</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-slate-700">
                            Transfiere a
                            {{
                                (payment.bank_accounts?.length ?? 0) > 1
                                    ? 'una de estas cuentas'
                                    : 'esta cuenta'
                            }}:
                        </p>
                        <BankAccounts
                            class="mt-2"
                            :accounts="payment.bank_accounts ?? []"
                        />
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                        >2</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-slate-700">
                            Manda tu comprobante con tu código
                            <span class="font-medium">{{ code }}</span
                            >:
                        </p>
                        <template v-if="payment.whatsapps?.length">
                            <a
                                v-for="wa in payment.whatsapps"
                                :key="wa"
                                :href="`https://wa.me/${wa}?text=${encodeURIComponent(`Hola, envío el comprobante de mi reserva ${code}`)}`"
                                target="_blank"
                                rel="noopener"
                                class="mt-2 flex items-center gap-3 rounded-xl border border-success/30 bg-success/5 p-3.5 transition hover:bg-success/10"
                            >
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                >
                                    <Lucide
                                        icon="MessageCircle"
                                        class="h-5 w-5"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-sm font-medium text-slate-800"
                                        >Enviar por WhatsApp</span
                                    >
                                    <span class="block text-xs text-slate-500"
                                        >+{{ wa }}</span
                                    >
                                </span>
                                <Lucide
                                    icon="ExternalLink"
                                    class="h-4 w-4 text-slate-400"
                                />
                            </a>
                        </template>
                        <p v-else class="mt-1 text-xs text-slate-500">
                            El hotel te contactará para recibir tu comprobante.
                        </p>
                    </div>
                </li>
                <li class="flex items-start gap-3">
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                        >3</span
                    >
                    <p class="text-sm text-slate-700">
                        En cuanto el hotel verifique tu pago, tu reserva queda
                        confirmada y te avisamos.
                    </p>
                </li>
            </ol>

            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4"
            >
                <span
                    class="inline-flex items-center gap-1.5 text-xs text-slate-500"
                >
                    <Lucide icon="Clock" class="h-3.5 w-3.5" />
                    Vigente por
                    {{ payment.valid_label ?? `${payment.valid_hours} horas` }}
                </span>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="
                            methodsCount > 1 ||
                            paymentOptional ||
                            depositApplies
                        "
                        type="button"
                        class="inline-flex h-10 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 text-sm font-medium text-slate-600 transition hover:border-slate-300"
                        @click="state = 'choice'"
                    >
                        <Lucide icon="ArrowLeftRight" class="h-4 w-4" />
                        Cambiar forma de pago
                    </button>
                    <a
                        :href="payment.return_url"
                        class="inline-flex h-10 items-center gap-1.5 rounded-full bg-primary/10 px-4 text-sm font-medium text-primary transition hover:bg-primary/15"
                    >
                        Ver estado
                        <Lucide icon="ArrowRight" class="h-4 w-4" />
                    </a>
                </div>
            </div>
        </div>

        <div
            v-else-if="state === 'redirect' && payment"
            class="flex flex-col items-center gap-3 py-10 text-center"
        >
            <Lucide
                icon="LoaderCircle"
                class="h-8 w-8 animate-spin text-primary"
            />
            <p class="text-sm text-slate-600">
                Te estamos llevando a la página de pago segura…
            </p>
            <a
                v-if="payment.checkout_url"
                :href="payment.checkout_url"
                class="text-sm font-medium text-primary hover:underline"
                >Si no se abre sola, toca aquí</a
            >
        </div>

        <!-- Sin métodos en línea -->
        <div
            v-else-if="state === 'none'"
            class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4"
        >
            <Lucide
                icon="PhoneCall"
                class="mt-0.5 h-5 w-5 shrink-0 text-primary"
            />
            <div class="text-sm">
                <div class="font-medium text-slate-800">
                    El hotel te contactará para el pago
                </div>
                <p class="mt-0.5 text-slate-600">
                    {{
                        contactNotice ??
                        'Tu apartado quedó registrado; te escribimos para completar el pago.'
                    }}
                </p>
                <a
                    v-if="phone"
                    :href="`tel:${phone}`"
                    class="mt-2 inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                >
                    <Lucide icon="Phone" class="h-4 w-4" />
                    O llámanos al {{ phone }}
                </a>
            </div>
        </div>

        <div v-else-if="state === 'error'" class="space-y-3">
            <div
                class="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm"
            >
                <Lucide
                    icon="CircleAlert"
                    class="mt-0.5 h-5 w-5 shrink-0 text-warning"
                />
                <div>
                    <div class="font-medium text-slate-800">
                        Tu apartado quedó guardado
                    </div>
                    <p class="mt-0.5 text-slate-600">{{ error }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="primary"
                    class="h-11 rounded-full px-5 text-sm shadow-md shadow-primary/20"
                    @click="start"
                >
                    <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                    Intentar de nuevo
                </Button>
                <a
                    v-if="phone"
                    :href="`tel:${phone}`"
                    class="inline-flex h-11 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-5 text-sm font-medium text-slate-600"
                >
                    <Lucide icon="Phone" class="h-4 w-4" />
                    Llamar al hotel
                </a>
            </div>
        </div>
    </div>
</template>
