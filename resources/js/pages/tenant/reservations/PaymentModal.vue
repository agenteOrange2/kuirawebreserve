<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormInput,
    FormLabel,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { useToasts } from '@/composables/useToasts';
import type { PaymentRow, ReservationRow } from './types';

/**
 * Dinero de una reserva: registrar un abono, emitir un cobro en línea
 * (link de pasarela o transferencia) y reembolsar.
 *
 * Extraído de Index.vue, que juntaba esto con la lista, el calendario, el
 * alta, la edición, el folio y el check-out en un solo archivo de 5,000
 * líneas donde cada cambio arriesgaba lo demás.
 */
const props = withDefaults(
    defineProps<{
        /**
         * ¿El hotel tiene una pasarela que de verdad puede cobrar? (misma
         * puerta que el backend: PaymentMethodGate). Sin ella el cobro que
         * se genera es una transferencia, y el modal lo dice así en vez de
         * prometer un link de pago que nunca va a existir.
         */
        gatewayAvailable?: boolean;
        /**
         * Comprobantes que el huésped ya mandó por el chat: se pueden usar
         * aquí sin ir a buscarlos a la bandeja.
         */
        chatReceipts?: ChatReceipt[];
        /**
         * Por dónde se puede generar el cobro: la pasarela (con su nombre) y
         * la transferencia (método encendido y con cuentas). Con las dos, el
         * personal elige; antes el panel mandaba siempre la pasarela.
         */
        chargeOptions?: { gateway: string | null; transfer: boolean };
    }>(),
    {
        gatewayAvailable: false,
        chatReceipts: () => [],
        chargeOptions: undefined,
    },
);

interface ChatReceipt {
    media_id: number;
    name: string;
    is_image: boolean;
    url: string;
    at: string;
    verdict: string | null;
    summary: string | null;
    amount: number | null;
    reference: string | null;
}

const emit = defineEmits<{
    (e: 'saved'): void;
}>();

const toast = useToasts();

const payingReservation = ref<ReservationRow | null>(null);
// Cobro presencial: efectivo o terminal, según lo que acepte la recepción
// (/ajustes/metodos-pago → Políticas). La transferencia no se registra a mano
// aquí — tiene su propio flujo con comprobante.
const { subset } = useCounterMethods();
const chargeMethods = subset(['cash', 'card']);
// Más la transferencia YA VERIFICADA, con su folio: el dinero que llegó al
// banco sin un cobro vivo que aprobar en Pagos (caso real cabañas
// 2026-09-11: el cobro había vencido a los 20 minutos).
const paymentMethods = computed<{ key: string; label: string }[]>(() => [
    ...chargeMethods.value.map((m) => ({
        key: m.key,
        label: m.key === 'card' ? 'Tarjeta (terminal)' : m.label,
    })),
    { key: 'transfer', label: 'Transferencia ya verificada' },
]);

const paymentForm = reactive({
    amount: '' as string | number,
    method: 'cash',
    reference: '',
    notes: '',
    // El huésped recibe su comprobante por el mismo hilo (o WhatsApp/correo):
    // antes el mostrador registraba y él no se enteraba de nada.
    notify: true,
});
const paymentError = ref<string | null>(null);
const payingBusy = ref(false);

// El comprobante del huésped, pegado al abono: el archivo que suba el staff
// o el que ya llegó por el chat. Antes había que emitir un cobro y aprobarlo
// en /pagos solo para dejar la foto junto al dinero.
const receiptFile = ref<File | null>(null);
const receiptMediaId = ref<number | null>(null);
const receiptLabel = ref<string | null>(null);
const receiptReading = ref<{
    summary: string | null;
    warnings: string[];
} | null>(null);
const readingBusy = ref(false);

function clearReceipt() {
    receiptFile.value = null;
    receiptMediaId.value = null;
    receiptLabel.value = null;
    receiptReading.value = null;
}

/** Lo que diga la imagen llena el formulario; el staff corrige si hace falta. */
async function readReceipt(payload: FormData) {
    if (!payingReservation.value) return;
    readingBusy.value = true;
    receiptReading.value = null;
    try {
        const { data } = await axios.post(
            `/api/reservations/${payingReservation.value.id}/receipt-reading`,
            payload,
        );
        if (data.amount) paymentForm.amount = Number(data.amount);
        if (data.reference) paymentForm.reference = String(data.reference);
        if (data.kind !== 'not_receipt') paymentForm.method = 'transfer';
        receiptReading.value = {
            summary:
                data.kind === 'not_receipt'
                    ? 'Ese archivo no parece un comprobante de pago.'
                    : data.summary,
            warnings: data.warnings ?? [],
        };
    } catch (error: any) {
        receiptReading.value = {
            summary:
                error.response?.data?.message ??
                'No se pudo leer; captura el monto a mano.',
            warnings: [],
        };
    } finally {
        readingBusy.value = false;
    }
}

function onReceiptFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) return;
    clearReceipt();
    receiptFile.value = file;
    receiptLabel.value = file.name;
    const form = new FormData();
    form.append('receipt', file);
    readReceipt(form);
}

function useChatReceipt(receipt: ChatReceipt) {
    clearReceipt();
    receiptMediaId.value = receipt.media_id;
    receiptLabel.value = `${receipt.name} · del chat ${receipt.at}`;

    // Si ya se leyó al llegar, no se vuelve a gastar la lectura.
    if (receipt.amount || receipt.reference) {
        if (receipt.amount) paymentForm.amount = Number(receipt.amount);
        if (receipt.reference) paymentForm.reference = receipt.reference;
        paymentForm.method = 'transfer';
        receiptReading.value = { summary: receipt.summary, warnings: [] };
        return;
    }

    const form = new FormData();
    form.append('receipt_media_id', String(receipt.media_id));
    readReceipt(form);
}

function openPayment(r: ReservationRow) {
    payingReservation.value = r;
    // Default inteligente: primero el anticipo pendiente, luego el resto.
    const deposit = Number(r.deposit_amount);
    const suggested =
        deposit > 0 && r.paid_total < deposit
            ? Math.min(deposit - r.paid_total, r.pending_balance)
            : r.pending_balance;
    paymentForm.amount = Number(suggested.toFixed(2));
    paymentForm.method = chargeMethods.value[0]?.key ?? 'cash';
    paymentForm.reference = '';
    paymentForm.notes = '';
    paymentForm.notify = true;
    paymentError.value = null;
    clearReceipt();
    resetChargeForm();
}

async function submitPayment() {
    if (!payingReservation.value) return;
    payingBusy.value = true;
    paymentError.value = null;
    try {
        const form = new FormData();
        form.append('amount', String(paymentForm.amount));
        form.append('method', paymentForm.method);
        if (paymentForm.reference)
            form.append('reference', paymentForm.reference);
        if (paymentForm.notes) form.append('notes', paymentForm.notes);
        form.append('notify_guest', paymentForm.notify ? '1' : '0');
        if (receiptFile.value) form.append('receipt', receiptFile.value);
        else if (receiptMediaId.value)
            form.append('receipt_media_id', String(receiptMediaId.value));

        await axios.post(
            `/api/reservations/${payingReservation.value.id}/payments`,
            form,
        );
        payingReservation.value = null;
        emit('saved');
        toast.success('Pago registrado');
    } catch (error: any) {
        paymentError.value =
            error.response?.data?.message ?? 'No se pudo registrar el pago.';
    } finally {
        payingBusy.value = false;
    }
}

// ── Cobro en línea desde el panel (link de pasarela o transferencia) ──
const issuingLink = ref(false);

// Las páginas viejas solo mandaban gatewayAvailable: sin chargeOptions se
// conserva el comportamiento anterior (pasarela si hay, si no transferencia).
const gatewayName = computed<string | null>(() =>
    props.chargeOptions
        ? props.chargeOptions.gateway
        : props.gatewayAvailable
          ? 'Pasarela'
          : null,
);
const canTransfer = computed(() =>
    props.chargeOptions
        ? props.chargeOptions.transfer
        : !props.gatewayAvailable,
);

const chargeForm = reactive({
    scope: 'deposit' as 'deposit' | 'full',
    method: 'gateway' as 'gateway' | 'transfer',
});

// El anticipo que falta, solo cuando de verdad hay algo que elegir: anticipo
// menor al total y todavía sin cubrir. Mismo criterio que el servidor.
const depositDue = computed<number>(() => {
    const r = payingReservation.value;
    if (!r) return 0;
    const deposit = Number(r.deposit_amount);
    const total = Number(r.total_amount);
    if (deposit <= 0 || deposit >= total || r.paid_total >= deposit) return 0;
    return Math.min(deposit - r.paid_total, r.pending_balance);
});

const chargeAmount = computed<number>(() =>
    chargeForm.scope === 'deposit' && depositDue.value > 0
        ? depositDue.value
        : (payingReservation.value?.pending_balance ?? 0),
);

const chargeUsesGateway = computed(
    () => chargeForm.method === 'gateway' && gatewayName.value !== null,
);

function resetChargeForm() {
    chargeForm.scope = 'deposit';
    chargeForm.method = gatewayName.value ? 'gateway' : 'transfer';
}

function money(value: number): string {
    return `$${value.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

async function issuePaymentLink() {
    if (!payingReservation.value || issuingLink.value) return;
    issuingLink.value = true;
    paymentError.value = null;
    try {
        const { data } = await axios.post<ReservationRow>(
            `/api/reservations/${payingReservation.value.id}/payment-request`,
            {
                scope: chargeForm.scope,
                method:
                    gatewayName.value && canTransfer.value
                        ? chargeForm.method
                        : undefined,
            },
        );
        payingReservation.value = data;
        toast.success(
            'Cobro generado',
            data.payment_request?.checkout_url
                ? 'El link de pago ya se envió al huésped por sus canales de contacto.'
                : 'Las instrucciones de transferencia ya se enviaron al huésped por sus canales de contacto.',
        );
    } catch (error: any) {
        paymentError.value =
            error.response?.data?.message ?? 'No se pudo generar el cobro.';
    } finally {
        issuingLink.value = false;
    }
}

async function cancelPaymentLink() {
    if (!payingReservation.value?.payment_request) return;
    issuingLink.value = true;
    try {
        const { data } = await axios.delete<ReservationRow>(
            `/api/reservations/${payingReservation.value.id}/payment-request/${payingReservation.value.payment_request.id}`,
        );
        payingReservation.value = data;
        resetChargeForm();
        toast.success('Cobro cancelado');
    } catch (error: any) {
        toast.error(
            'No se pudo cancelar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        issuingLink.value = false;
    }
}

async function copyPaymentLink() {
    const url =
        payingReservation.value?.payment_request?.checkout_url ??
        payingReservation.value?.payment_request?.public_url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        toast.success('Link copiado', 'Pégalo en el chat del huésped.');
    } catch {
        toast.error('No se pudo copiar', url);
    }
}

// ── Reembolsos (F4): siempre decisión humana ──
const refundingPayment = ref<PaymentRow | null>(null);
const refundForm = reactive({
    amount: 0 as number | string,
    reason: '',
    manual: false,
});
const refundBusy = ref(false);

function openRefund(p: PaymentRow) {
    removingPayment.value = null;
    refundingPayment.value = p;
    // Default: la sugerencia de la política si cabe en este pago; si no, lo reembolsable.
    const suggested = payingReservation.value?.refund_suggestion?.amount;
    refundForm.amount =
        suggested !== undefined && suggested > 0 && suggested <= p.refundable
            ? suggested
            : p.refundable;
    refundForm.reason = '';
    refundForm.manual = false;
}

async function submitRefund() {
    if (!payingReservation.value || !refundingPayment.value || refundBusy.value)
        return;
    refundBusy.value = true;
    try {
        const { data } = await axios.post<ReservationRow>(
            `/api/reservations/${payingReservation.value.id}/payments/${refundingPayment.value.id}/refund`,
            {
                amount: refundForm.amount,
                reason: refundForm.reason || null,
                manual: refundForm.manual,
            },
        );
        payingReservation.value = data;
        refundingPayment.value = null;
        emit('saved');
        toast.success(
            'Reembolso registrado',
            'Se avisó al huésped por su canal.',
        );
    } catch (error: any) {
        toast.error(
            'No se pudo reembolsar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        refundBusy.value = false;
    }
}

// ── Pago registrado por error (el mismo dinero dos veces) ──
// No es un reembolso: no se avisa al huésped ni cuenta como devolución.
const removingPayment = ref<PaymentRow | null>(null);
const removeReason = ref('');
const removeBusy = ref(false);

function openRemove(p: PaymentRow) {
    refundingPayment.value = null;
    removingPayment.value = p;
    removeReason.value = '';
}

async function submitRemove() {
    if (
        !payingReservation.value ||
        !removingPayment.value ||
        removeBusy.value ||
        !removeReason.value.trim()
    )
        return;
    removeBusy.value = true;
    try {
        const { data } = await axios.delete<
            ReservationRow & { closed_cut_notice?: string | null }
        >(
            `/api/reservations/${payingReservation.value.id}/payments/${removingPayment.value.id}`,
            { data: { reason: removeReason.value.trim() } },
        );
        payingReservation.value = data;
        removingPayment.value = null;
        emit('saved');
        toast.success(
            'Pago quitado',
            data.closed_cut_notice ??
                'La reserva ya refleja solo el dinero que sí entró. No se avisó al huésped.',
        );
    } catch (error: any) {
        toast.error(
            'No se pudo quitar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        removeBusy.value = false;
    }
}

// El padre abre el modal con una referencia: así el estado del cobro vive
// aquí dentro y no vuelve a engordar la pantalla de reservas.
defineExpose({ open: openPayment });
</script>

<template>
    <!-- Modal: registrar pago -->
    <Dialog
        size="lg"
        :open="payingReservation !== null"
        @close="payingReservation = null"
    >
        <Dialog.Panel class="sm:w-[94vw] lg:w-[640px]">
            <form
                v-if="payingReservation"
                class="flex max-h-[calc(100dvh-6rem)] flex-col"
                @submit.prevent="submitPayment"
            >
                <!-- Header -->
                <div
                    class="flex items-center gap-4 border-b border-slate-200/70 px-6 py-5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                    >
                        <Lucide icon="Banknote" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-medium">Registrar pago</h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ payingReservation.code }} ·
                            {{ payingReservation.guest_name ?? 'Anónimo' }}
                            · Hab. {{ payingReservation.room ?? '—' }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        @click="payingReservation = null"
                    >
                        <Lucide icon="X" class="h-6 w-6" />
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 space-y-5 overflow-y-auto px-6 py-6">
                    <!-- Resumen de saldo -->
                    <div class="grid grid-cols-3 gap-3">
                        <div
                            class="rounded-lg border border-slate-200/70 p-3 text-center dark:border-darkmode-400"
                        >
                            <div class="text-xs text-slate-500">Total</div>
                            <div class="mt-1 text-sm font-medium">
                                ${{ payingReservation.total_amount }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-success/20 bg-success/5 p-3 text-center"
                        >
                            <div class="text-xs text-slate-500">Pagado</div>
                            <div class="mt-1 font-medium text-success">
                                ${{ payingReservation.paid_total.toFixed(2) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-danger/20 bg-danger/5 p-3 text-center"
                        >
                            <div class="text-xs text-slate-500">Pendiente</div>
                            <div class="mt-1 font-medium text-danger">
                                ${{
                                    payingReservation.pending_balance.toFixed(2)
                                }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="
                            Number(payingReservation.deposit_amount) > 0 ||
                            payingReservation.payment_due_at
                        "
                        class="space-y-1 rounded-lg border border-dashed border-slate-300/70 bg-slate-50/70 p-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <p
                            v-if="Number(payingReservation.deposit_amount) > 0"
                            class="flex items-center gap-1.5 text-slate-500"
                        >
                            <Lucide icon="PiggyBank" class="h-3.5 w-3.5" />
                            Anticipo requerido:
                            <span class="font-medium"
                                >${{ payingReservation.deposit_amount }}</span
                            >
                        </p>
                        <p
                            v-if="payingReservation.payment_due_at"
                            class="flex items-center gap-1.5"
                            :class="
                                payingReservation.payment_overdue
                                    ? 'text-danger'
                                    : 'text-slate-500'
                            "
                        >
                            <Lucide
                                :icon="
                                    payingReservation.payment_overdue
                                        ? 'TriangleAlert'
                                        : 'CalendarClock'
                                "
                                class="h-3.5 w-3.5"
                            />
                            {{
                                payingReservation.payment_overdue
                                    ? 'Venció el'
                                    : 'Liquidar antes de'
                            }}
                            {{ payingReservation.payment_due_at }}
                        </p>
                    </div>

                    <!-- Cobro en línea: link de pasarela o transferencia -->
                    <div
                        v-if="payingReservation.pending_balance > 0"
                        class="rounded-lg border border-primary/20 bg-primary/[0.03] p-3.5"
                    >
                        <div
                            class="flex items-center gap-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide
                                :icon="gatewayName ? 'Link' : 'Landmark'"
                                class="h-3.5 w-3.5 text-primary"
                            />
                            {{
                                gatewayName
                                    ? 'Cobrar en línea'
                                    : 'Cobrar por transferencia'
                            }}
                        </div>
                        <template v-if="payingReservation.payment_request">
                            <p
                                class="mt-2 text-sm text-slate-600 dark:text-slate-300"
                            >
                                {{ payingReservation.payment_request.concept }}
                                de
                                {{
                                    payingReservation.payment_request
                                        .amount_label
                                }}
                                ·
                                <span class="font-medium">{{
                                    payingReservation.payment_request
                                        .provider_label ?? 'Transferencia'
                                }}</span>
                                <span
                                    v-if="
                                        payingReservation.payment_request
                                            .expires_label
                                    "
                                    class="text-slate-400"
                                >
                                    · vence
                                    {{
                                        payingReservation.payment_request
                                            .expires_label
                                    }}</span
                                >
                            </p>
                            <div
                                class="mt-2.5 flex flex-wrap items-center gap-2"
                            >
                                <Button
                                    type="button"
                                    variant="primary"
                                    size="sm"
                                    class="rounded-[0.5rem]"
                                    @click="copyPaymentLink"
                                >
                                    <Lucide
                                        icon="Copy"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Copiar link
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline-secondary"
                                    size="sm"
                                    class="rounded-[0.5rem] bg-white"
                                    :disabled="issuingLink"
                                    title="Cancela este cobro para generar otro: anticipo o total, link o transferencia"
                                    @click="cancelPaymentLink"
                                >
                                    <Lucide
                                        icon="X"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Cancelar cobro
                                </Button>
                                <span
                                    v-if="
                                        !payingReservation.payment_request
                                            .checkout_url
                                    "
                                    class="text-xs text-slate-400"
                                    >El huésped ya recibió las cuentas del
                                    hotel; cuando mande su comprobante,
                                    confírmalo en Pagos.</span
                                >
                            </div>
                        </template>
                        <template v-else>
                            <!-- Cuánto: el anticipo que falta o todo de una vez -->
                            <div v-if="depositDue > 0" class="mt-2.5">
                                <div class="mb-1.5 text-xs text-slate-500">
                                    ¿Qué le cobras?
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        v-for="opt in [
                                            {
                                                key: 'deposit',
                                                label: 'Anticipo',
                                                amount: depositDue,
                                            },
                                            {
                                                key: 'full',
                                                label: 'Todo lo pendiente',
                                                amount: payingReservation.pending_balance,
                                            },
                                        ] as const"
                                        :key="opt.key"
                                        type="button"
                                        class="rounded-lg border px-3 py-2 text-left text-xs transition"
                                        :class="
                                            chargeForm.scope === opt.key
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-slate-200/70 bg-white text-slate-600 hover:border-primary/40 dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300'
                                        "
                                        @click="chargeForm.scope = opt.key"
                                    >
                                        <span class="block">{{
                                            opt.label
                                        }}</span>
                                        <span
                                            class="mt-0.5 block font-medium"
                                            >{{ money(opt.amount) }}</span
                                        >
                                    </button>
                                </div>
                            </div>

                            <!-- Por dónde: pasarela o transferencia -->
                            <div
                                v-if="gatewayName && canTransfer"
                                class="mt-2.5"
                            >
                                <div class="mb-1.5 text-xs text-slate-500">
                                    ¿Cómo va a pagar?
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        v-for="opt in [
                                            {
                                                key: 'gateway',
                                                icon: 'Link',
                                                label: `Link de ${gatewayName}`,
                                            },
                                            {
                                                key: 'transfer',
                                                icon: 'Landmark',
                                                label: 'Transferencia',
                                            },
                                        ] as const"
                                        :key="opt.key"
                                        type="button"
                                        class="flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-xs transition"
                                        :class="
                                            chargeForm.method === opt.key
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-slate-200/70 bg-white text-slate-600 hover:border-primary/40 dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300'
                                        "
                                        @click="chargeForm.method = opt.key"
                                    >
                                        <Lucide
                                            :icon="opt.icon"
                                            class="h-3.5 w-3.5 shrink-0"
                                        />
                                        {{ opt.label }}
                                    </button>
                                </div>
                            </div>

                            <p class="mt-2.5 text-xs text-slate-500">
                                <template v-if="chargeUsesGateway">
                                    Se genera un link de {{ gatewayName }} por
                                    {{ money(chargeAmount) }} y se le envía solo
                                    al huésped por WhatsApp o correo.
                                </template>
                                <template v-else>
                                    Se le mandan al huésped las cuentas del
                                    hotel por {{ money(chargeAmount) }}; cuando
                                    conteste con su comprobante lo confirmas en
                                    Pagos.
                                </template>
                            </p>
                            <Button
                                type="button"
                                variant="outline-primary"
                                size="sm"
                                class="mt-2.5 rounded-[0.5rem] bg-white"
                                :disabled="issuingLink"
                                @click="issuePaymentLink"
                            >
                                <Lucide
                                    :icon="chargeUsesGateway ? 'Link' : 'Send'"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    issuingLink
                                        ? 'Generando…'
                                        : chargeUsesGateway
                                          ? `Generar link por ${money(chargeAmount)}`
                                          : `Mandar datos de transferencia`
                                }}
                            </Button>
                        </template>
                    </div>

                    <div
                        class="relative flex items-center gap-3 text-xs text-slate-400"
                    >
                        <div
                            class="h-px flex-1 bg-slate-200/70 dark:bg-darkmode-400"
                        ></div>
                        O registra un pago recibido
                        <div
                            class="h-px flex-1 bg-slate-200/70 dark:bg-darkmode-400"
                        ></div>
                    </div>

                    <div
                        class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2"
                    >
                        <div>
                            <FormLabel htmlFor="pay-amount">Monto</FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="DollarSign"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    id="pay-amount"
                                    v-model.number="paymentForm.amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    :max="payingReservation.pending_balance"
                                    class="pl-9"
                                />
                            </div>
                            <div
                                v-if="depositDue > 0"
                                class="mt-1.5 flex flex-wrap gap-1.5"
                            >
                                <button
                                    v-for="opt in [
                                        {
                                            label: 'Anticipo',
                                            amount: depositDue,
                                        },
                                        {
                                            label: 'Todo',
                                            amount: payingReservation.pending_balance,
                                        },
                                    ]"
                                    :key="opt.label"
                                    type="button"
                                    class="rounded-full border px-2.5 py-0.5 text-[11px] transition"
                                    :class="
                                        Number(paymentForm.amount) ===
                                        Number(opt.amount.toFixed(2))
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-slate-200/70 text-slate-500 hover:border-primary/40 dark:border-darkmode-400'
                                    "
                                    @click="
                                        paymentForm.amount = Number(
                                            opt.amount.toFixed(2),
                                        )
                                    "
                                >
                                    {{ opt.label }} {{ money(opt.amount) }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="pay-method">Método</FormLabel>
                            <div class="relative">
                                <Lucide
                                    icon="CreditCard"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormSelect
                                    id="pay-method"
                                    v-model="paymentForm.method"
                                    class="pl-9"
                                >
                                    <option
                                        v-for="m in paymentMethods"
                                        :key="m.key"
                                        :value="m.key"
                                    >
                                        {{ m.label }}
                                    </option>
                                </FormSelect>
                            </div>
                        </div>
                        <p
                            class="flex items-start gap-1.5 text-xs text-slate-500 sm:col-span-2"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            <span>
                                Efectivo y terminal se registran al momento. Una
                                transferencia se registra aquí solo cuando ya la
                                viste reflejada en el banco, con su folio; si
                                todavía no llega, genera el cobro de arriba y
                                apruébala en Pagos cuando mande el comprobante.
                            </span>
                        </p>
                        <div
                            v-if="paymentForm.method !== 'cash'"
                            class="sm:col-span-2"
                        >
                            <FormLabel htmlFor="pay-ref"
                                >Referencia / folio</FormLabel
                            >
                            <div class="relative">
                                <Lucide
                                    icon="Hash"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400"
                                />
                                <FormInput
                                    id="pay-ref"
                                    v-model="paymentForm.reference"
                                    :required="
                                        paymentForm.method === 'transfer' &&
                                        !receiptLabel
                                    "
                                    type="text"
                                    class="pl-9"
                                    placeholder="Voucher de la terminal"
                                />
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <FormLabel htmlFor="pay-notes"
                                >Notas
                                <span class="text-slate-400"
                                    >(opcional)</span
                                ></FormLabel
                            >
                            <FormInput
                                id="pay-notes"
                                v-model="paymentForm.notes"
                                type="text"
                                placeholder="Ej. pago parcial, cambio pendiente…"
                            />
                        </div>

                        <!-- El comprobante, aquí mismo: el que suba el staff o
                             el que el huésped ya mandó por el chat. -->
                        <div class="sm:col-span-2">
                            <FormLabel htmlFor="pay-receipt"
                                >Comprobante
                                <span class="text-slate-400"
                                    >(opcional)</span
                                ></FormLabel
                            >
                            <div class="flex flex-wrap items-center gap-2">
                                <label
                                    class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-[0.5rem] border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                                >
                                    <Lucide
                                        icon="Paperclip"
                                        class="h-3.5 w-3.5"
                                    />
                                    Subir archivo
                                    <input
                                        id="pay-receipt"
                                        type="file"
                                        accept="image/*,application/pdf"
                                        class="hidden"
                                        @change="onReceiptFile"
                                    />
                                </label>
                                <span
                                    v-if="receiptLabel"
                                    class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-600 dark:bg-darkmode-400"
                                >
                                    {{ receiptLabel }}
                                    <button
                                        type="button"
                                        class="text-slate-400 hover:text-danger"
                                        @click="clearReceipt"
                                    >
                                        <Lucide icon="X" class="h-3 w-3" />
                                    </button>
                                </span>
                                <span
                                    v-if="readingBusy"
                                    class="inline-flex items-center gap-1.5 text-[11px] text-slate-500"
                                >
                                    <Lucide
                                        icon="RefreshCw"
                                        class="h-3 w-3 animate-spin"
                                    />
                                    Leyendo el comprobante…
                                </span>
                            </div>

                            <p
                                v-if="receiptReading?.summary"
                                class="mt-1.5 text-[11px] text-slate-500"
                            >
                                {{ receiptReading.summary }}
                            </p>
                            <p
                                v-for="(aviso, i) in receiptReading?.warnings ??
                                []"
                                :key="i"
                                class="mt-1 inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                            >
                                <Lucide icon="TriangleAlert" class="h-3 w-3" />
                                {{ aviso }}
                            </p>

                            <!-- Lo que ya llegó por el chat: un clic y queda
                                 pegado al abono, con su monto y su folio. -->
                            <div
                                v-if="props.chatReceipts.length"
                                class="mt-2 rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                            >
                                <div
                                    class="border-b border-slate-200/60 px-3 py-1.5 text-[11px] font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400"
                                >
                                    Llegó por el chat
                                </div>
                                <div
                                    v-for="c in props.chatReceipts"
                                    :key="c.media_id"
                                    class="flex flex-wrap items-center gap-2 px-3 py-2 text-xs"
                                >
                                    <a
                                        :href="c.url"
                                        target="_blank"
                                        class="inline-flex items-center gap-1.5 text-slate-600 hover:text-primary dark:text-slate-300"
                                    >
                                        <Lucide
                                            :icon="
                                                c.is_image
                                                    ? 'Image'
                                                    : 'FileText'
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ c.at }}
                                    </a>
                                    <span
                                        v-if="c.summary"
                                        class="truncate text-[11px] text-slate-500"
                                        >{{ c.summary }}</span
                                    >
                                    <Button
                                        type="button"
                                        variant="outline-secondary"
                                        class="ml-auto h-7 rounded-[0.5rem] bg-white text-[11px]"
                                        @click="useChatReceipt(c)"
                                    >
                                        Usar este
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex items-center gap-2.5 border-t border-dashed border-slate-200/70 pt-3 dark:border-darkmode-400"
                    >
                        <FormSwitch>
                            <FormSwitch.Input
                                v-model="paymentForm.notify"
                                type="checkbox"
                            />
                        </FormSwitch>
                        <div class="min-w-0">
                            <div class="text-xs font-medium">
                                Avisar al huésped
                            </div>
                            <p class="text-[11px] text-slate-500">
                                Recibe el monto, si queda confirmada y el saldo
                                pendiente por su propio canal.
                            </p>
                        </div>
                    </div>

                    <p
                        v-if="paymentError"
                        class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ paymentError }}
                    </p>

                    <!-- Pagos registrados y reembolsos (F4) -->
                    <div v-if="payingReservation.payments?.length">
                        <div
                            class="mb-2 flex items-center gap-1.5 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="History" class="h-3.5 w-3.5" />
                            Pagos registrados
                            <span
                                v-if="payingReservation.refunded_total > 0"
                                class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium tracking-normal text-pending normal-case"
                            >
                                Reembolsado ${{
                                    payingReservation.refunded_total.toFixed(2)
                                }}
                            </span>
                        </div>
                        <div
                            v-if="payingReservation.refund_suggestion"
                            class="mb-2 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <Lucide
                                icon="Scale"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span>
                                Según la política de la tarifa, correspondería
                                reembolsar
                                <span class="font-medium">{{
                                    payingReservation.refund_suggestion
                                        .amount_label
                                }}</span>
                                si se cancela ahora.
                                <template
                                    v-if="
                                        payingReservation.refund_suggestion
                                            .policy_label
                                    "
                                >
                                    {{
                                        payingReservation.refund_suggestion
                                            .policy_label
                                    }}</template
                                >
                                La decisión final es de tu equipo.
                            </span>
                        </div>
                        <div
                            class="divide-y divide-dashed divide-slate-300/70 rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                        >
                            <div
                                v-for="p in payingReservation.payments"
                                :key="p.id"
                                class="px-3.5 py-2.5"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium"
                                        >${{ p.amount }}</span
                                    >
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ p.method }}</span
                                    >
                                    <span class="text-xs text-slate-400">{{
                                        p.paid_at
                                    }}</span>
                                    <span
                                        v-if="p.refunded > 0"
                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[10px] font-medium text-pending"
                                    >
                                        Reembolsado ${{ p.refunded.toFixed(2) }}
                                    </span>
                                    <button
                                        v-if="
                                            p.refundable > 0 &&
                                            refundingPayment?.id !== p.id
                                        "
                                        type="button"
                                        class="ml-auto text-xs font-medium text-primary hover:underline"
                                        @click="openRefund(p)"
                                    >
                                        Reembolsar
                                    </button>
                                    <button
                                        v-if="
                                            p.removable &&
                                            removingPayment?.id !== p.id
                                        "
                                        type="button"
                                        class="text-xs font-medium text-slate-500 hover:text-danger hover:underline"
                                        :class="{
                                            'ml-auto': !(
                                                p.refundable > 0 &&
                                                refundingPayment?.id !== p.id
                                            ),
                                        }"
                                        @click="openRemove(p)"
                                    >
                                        Quitar, se registró por error
                                    </button>
                                </div>

                                <!-- Quitar un pago registrado por error -->
                                <div
                                    v-if="removingPayment?.id === p.id"
                                    class="mt-3 space-y-3 rounded-lg bg-slate-50 p-3 dark:bg-darkmode-700"
                                >
                                    <p class="text-xs text-slate-500">
                                        Úsalo cuando el mismo dinero quedó
                                        registrado dos veces. El pago de ${{
                                            p.amount
                                        }}
                                        desaparece de la reserva, no se avisa
                                        al huésped y no cuenta como devolución.
                                        Queda anotado en la bitácora.
                                    </p>
                                    <div>
                                        <label
                                            class="mb-1 block text-xs text-slate-500"
                                            >Motivo</label
                                        >
                                        <FormInput
                                            v-model="removeReason"
                                            type="text"
                                            maxlength="255"
                                            placeholder="Anticipo capturado a mano y aprobado otra vez en Pagos"
                                        />
                                    </div>
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <Button
                                            type="button"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="rounded-[0.5rem] bg-white"
                                            @click="removingPayment = null"
                                            >Cancelar</Button
                                        >
                                        <Button
                                            type="button"
                                            variant="danger"
                                            size="sm"
                                            class="rounded-[0.5rem]"
                                            :disabled="
                                                removeBusy ||
                                                !removeReason.trim()
                                            "
                                            @click="submitRemove"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            {{
                                                removeBusy
                                                    ? 'Quitando…'
                                                    : 'Quitar pago'
                                            }}
                                        </Button>
                                    </div>
                                </div>

                                <!-- Formulario inline de reembolso -->
                                <div
                                    v-if="refundingPayment?.id === p.id"
                                    class="mt-3 space-y-3 rounded-lg bg-slate-50 p-3 dark:bg-darkmode-700"
                                >
                                    <div
                                        class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                                    >
                                        <div>
                                            <label
                                                class="mb-1 block text-xs text-slate-500"
                                                >Monto a devolver (máx. ${{
                                                    p.refundable.toFixed(2)
                                                }})</label
                                            >
                                            <FormInput
                                                v-model.number="
                                                    refundForm.amount
                                                "
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                :max="p.refundable"
                                            />
                                        </div>
                                        <div>
                                            <label
                                                class="mb-1 block text-xs text-slate-500"
                                                >Motivo</label
                                            >
                                            <FormInput
                                                v-model="refundForm.reason"
                                                type="text"
                                                placeholder="Cancelación dentro de la ventana…"
                                            />
                                        </div>
                                    </div>
                                    <label
                                        v-if="p.via_gateway"
                                        class="flex items-start gap-2 text-xs text-slate-500"
                                    >
                                        <input
                                            v-model="refundForm.manual"
                                            type="checkbox"
                                            class="mt-0.5"
                                        />
                                        <span
                                            >Solo registrar (ya lo devolví en el
                                            dashboard del proveedor). Sin
                                            marcar, el reembolso se envía a la
                                            pasarela automáticamente.</span
                                        >
                                    </label>
                                    <p v-else class="text-xs text-slate-400">
                                        Devolución manual del hotel
                                        (efectivo/transferencia); aquí solo
                                        queda registrada.
                                    </p>
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <Button
                                            type="button"
                                            variant="outline-secondary"
                                            size="sm"
                                            class="rounded-[0.5rem] bg-white"
                                            @click="refundingPayment = null"
                                            >Cancelar</Button
                                        >
                                        <Button
                                            type="button"
                                            variant="danger"
                                            size="sm"
                                            class="rounded-[0.5rem]"
                                            :disabled="refundBusy"
                                            @click="submitRefund"
                                        >
                                            <Lucide
                                                icon="Undo2"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            {{
                                                refundBusy
                                                    ? 'Procesando…'
                                                    : 'Reembolsar'
                                            }}
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                >
                    <Button
                        type="button"
                        variant="outline-secondary"
                        @click="payingReservation = null"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        variant="primary"
                        class="shadow-md shadow-primary/20"
                        :disabled="payingBusy"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ payingBusy ? 'Registrando…' : 'Registrar pago' }}
                    </Button>
                </div>
            </form>
        </Dialog.Panel>
    </Dialog>
</template>
