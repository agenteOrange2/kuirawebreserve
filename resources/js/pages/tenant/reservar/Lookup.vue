<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';

interface PendingRequest {
    method: 'gateway' | 'transfer';
    amount: number;
    amount_label: string;
    checkout_url: string | null;
    expires_at: string | null;
    bank_accounts: { banco: string; titular: string; cuenta: string }[];
}

interface LookupResult {
    code: string;
    status: string;
    status_label: string;
    room_type: string | null;
    starts_at: string;
    ends_at: string;
    adults: number;
    children: number;
    total: number;
    paid: number;
    pending_balance: number;
    payment_status_label: string;
    payment_due_at: string | null;
    hold_expires_at: string | null;
    pending_request: PendingRequest | null;
    can_cancel: boolean;
    cancellation_policy: string | null;
}

const props = defineProps<{
    property: { name: string; phone: string | null; currency: string };
}>();

const money = (n: number) => `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2 })} ${props.property.currency}`;

const code = ref('');
const phone = ref('');
const searching = ref(false);
const error = ref<string | null>(null);
const result = ref<LookupResult | null>(null);

async function search() {
    searching.value = true;
    error.value = null;
    result.value = null;
    confirmingCancel.value = false;
    try {
        const { data } = await axios.get<LookupResult>('/api/booking/reservation', {
            params: { code: code.value.trim(), phone: phone.value.trim() },
        });
        result.value = data;
    } catch (e: any) {
        error.value = e.response?.data?.message ?? 'No se pudo consultar. Intenta de nuevo en un momento.';
    } finally {
        searching.value = false;
    }
}

// Cancelación en dos pasos: primero se muestra la política, luego se confirma.
const confirmingCancel = ref(false);
const canceling = ref(false);
const cancelError = ref<string | null>(null);
const canceled = ref(false);

async function cancelReservation() {
    canceling.value = true;
    cancelError.value = null;
    try {
        const { data } = await axios.post<LookupResult>('/api/booking/reservation/cancel', {
            code: code.value.trim(),
            phone: phone.value.trim(),
        });
        result.value = data;
        confirmingCancel.value = false;
        canceled.value = true;
    } catch (e: any) {
        cancelError.value = e.response?.data?.message ?? 'No se pudo cancelar. Intenta de nuevo o contacta al hotel.';
    } finally {
        canceling.value = false;
    }
}

// Semáforo de estados: mismo lenguaje visual que el panel.
const statusClass = computed(() => {
    switch (result.value?.status) {
        case 'confirmed':
            return 'bg-success/10 text-success';
        case 'pending':
            return 'bg-warning/10 text-warning';
        case 'checked_in':
            return 'bg-primary/10 text-primary';
        case 'completed':
            return 'bg-slate-100 text-slate-500';
        default:
            return 'bg-danger/10 text-danger';
    }
});

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', { weekday: 'short', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

// Cuenta regresiva del apartado pendiente, igual que en el wizard.
const nowMs = ref(Date.now());
let clock: number | null = null;
onMounted(() => {
    clock = window.setInterval(() => (nowMs.value = Date.now()), 1000);
});
onBeforeUnmount(() => {
    if (clock) window.clearInterval(clock);
});

const holdCountdown = computed(() => {
    if (!result.value?.hold_expires_at || result.value.status !== 'pending') return null;
    const diff = Date.parse(result.value.hold_expires_at) - nowMs.value;
    if (diff <= 0) return null;
    const totalMinutes = Math.floor(diff / 60000);
    if (totalMinutes >= 60) {
        const h = Math.floor(totalMinutes / 60);
        return `${h} h ${totalMinutes % 60} min`;
    }
    return `${totalMinutes}:${String(Math.floor((diff % 60000) / 1000)).padStart(2, '0')} min`;
});
</script>

<template>
    <Head :title="`Mi reserva · ${property.name}`" />
    <div class="flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-8 sm:px-8">
        <div class="m-auto w-full max-w-2xl">
            <div class="mb-5 flex items-center gap-3 px-1 text-white">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10">
                    <Lucide icon="Building2" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-lg font-medium">{{ property.name }}</div>
                    <div class="text-xs text-white/70">Consulta el estado de tu reserva</div>
                </div>
                <a v-if="property.phone" :href="`tel:${property.phone}`" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20">
                    <Lucide icon="Phone" class="h-4 w-4" />
                </a>
            </div>

            <div class="overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="p-5 sm:p-7">
                    <h1 class="text-lg font-medium text-slate-800">Encuentra tu reserva</h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Con tu código (te lo dimos al reservar) y el teléfono con el que reservaste.
                    </p>

                    <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <FormLabel>Código de reserva</FormLabel>
                            <FormInput v-model="code" type="text" placeholder="RES-2026-0019" class="uppercase" @keyup.enter="search" />
                        </div>
                        <div>
                            <FormLabel>Teléfono</FormLabel>
                            <FormInput v-model="phone" type="tel" placeholder="10 dígitos" @keyup.enter="search" />
                        </div>
                    </div>

                    <Button variant="primary" class="mt-5 w-full shadow-md shadow-primary/20" :disabled="searching || !code.trim() || phone.trim().length < 4" @click="search">
                        <Lucide :icon="searching ? 'RefreshCw' : 'Search'" class="mr-2 h-4 w-4" :class="searching && 'animate-spin'" />
                        {{ searching ? 'Buscando…' : 'Buscar mi reserva' }}
                    </Button>

                    <p v-if="error" class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ error }}</p>

                    <!-- Resultado -->
                    <div v-if="result" class="mt-6 rounded-xl border border-slate-200 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-xl font-semibold tracking-wide text-slate-800">{{ result.code }}</div>
                            <span class="rounded-full px-3 py-1 text-xs font-medium" :class="statusClass">{{ result.status_label }}</span>
                        </div>

                        <div class="mt-3 space-y-1.5 text-sm text-slate-600">
                            <div class="flex items-center gap-2">
                                <Lucide icon="BedDouble" class="h-4 w-4 shrink-0 text-slate-400" /> {{ result.room_type ?? 'Habitación' }}
                            </div>
                            <div class="flex items-center gap-2">
                                <Lucide icon="Calendar" class="h-4 w-4 shrink-0 text-slate-400" />
                                {{ formatDateTime(result.starts_at) }} → {{ formatDateTime(result.ends_at) }}
                            </div>
                            <div class="flex items-center gap-2">
                                <Lucide icon="Users" class="h-4 w-4 shrink-0 text-slate-400" />
                                {{ result.adults }} adulto{{ result.adults === 1 ? '' : 's' }}<template v-if="result.children"> y {{ result.children }} niño{{ result.children === 1 ? '' : 's' }}</template>
                            </div>
                        </div>

                        <div class="mt-4 space-y-1 border-t border-slate-100 pt-3 text-sm">
                            <div class="flex justify-between text-slate-500"><span>Total</span><span>{{ money(result.total) }}</span></div>
                            <div class="flex justify-between text-slate-500"><span>Pagado</span><span>{{ money(result.paid) }}</span></div>
                            <div class="flex justify-between font-medium" :class="result.pending_balance > 0 ? 'text-slate-800' : 'text-success'">
                                <span>{{ result.pending_balance > 0 ? 'Por pagar' : 'Estado de pago' }}</span>
                                <span>{{ result.pending_balance > 0 ? money(result.pending_balance) : result.payment_status_label }}</span>
                            </div>
                            <p v-if="result.payment_due_at && result.pending_balance > 0" class="pt-1 text-xs text-slate-400">
                                Fecha límite de pago: {{ formatDateTime(result.payment_due_at) }}
                            </p>
                        </div>

                        <p v-if="holdCountdown" class="mt-3 rounded-lg bg-warning/10 px-3 py-2 text-xs font-medium text-warning">
                            Tu apartado se libera solo en {{ holdCountdown }} si no se confirma o paga antes.
                        </p>

                        <p v-if="canceled" class="mt-3 rounded-lg bg-success/10 px-3 py-2 text-xs font-medium text-success">
                            Tu reserva quedó cancelada. Si tenías pagos registrados, el hotel se pondrá en contacto contigo.
                        </p>

                        <!-- Cobro vigente: pagar aquí mismo o repetir las cuentas -->
                        <template v-if="result.pending_request">
                            <a
                                v-if="result.pending_request.method === 'gateway' && result.pending_request.checkout_url"
                                :href="result.pending_request.checkout_url"
                                class="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-medium text-white shadow-md shadow-primary/20 transition hover:bg-primary/90"
                            >
                                <Lucide icon="CreditCard" class="h-4 w-4" /> Pagar {{ result.pending_request.amount_label }} ahora
                            </a>
                            <div v-else-if="result.pending_request.method === 'transfer'" class="mt-4">
                                <div class="text-sm font-medium text-slate-700">
                                    Transfiere {{ result.pending_request.amount_label }} a cualquiera de estas cuentas y envía tu comprobante al hotel:
                                </div>
                                <div class="mt-2 space-y-2">
                                    <div v-for="acc in result.pending_request.bank_accounts" :key="acc.cuenta" class="rounded-xl border border-slate-200 p-3 text-sm">
                                        <div class="font-medium text-slate-700">{{ acc.banco }}</div>
                                        <div class="text-slate-500">{{ acc.titular }}</div>
                                        <div class="mt-1 font-mono text-slate-700">{{ acc.cuenta }}</div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- Cancelación autoservicio: dos pasos, con la política a la vista -->
                        <template v-if="result.can_cancel && !canceled">
                            <div v-if="confirmingCancel" class="mt-4 rounded-xl border border-danger/20 bg-danger/5 p-4 text-left">
                                <div class="text-sm font-medium text-slate-800">¿Seguro que quieres cancelar tu reserva?</div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ result.cancellation_policy ?? 'La habitación se libera de inmediato y tu código dejará de ser válido.' }}
                                </p>
                                <p v-if="cancelError" class="mt-2 text-xs text-danger">{{ cancelError }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <Button variant="danger" size="sm" :disabled="canceling" @click="cancelReservation">
                                        {{ canceling ? 'Cancelando…' : 'Sí, cancelar mi reserva' }}
                                    </Button>
                                    <Button variant="outline-secondary" size="sm" @click="confirmingCancel = false">Conservarla</Button>
                                </div>
                            </div>
                            <button
                                v-else
                                type="button"
                                class="mt-4 text-xs font-medium text-danger underline-offset-2 hover:underline"
                                @click="confirmingCancel = true"
                            >
                                Necesito cancelar esta reserva
                            </button>
                        </template>
                    </div>

                    <p class="mt-5 text-center text-xs text-slate-400">
                        ¿Aún no tienes reserva?
                        <a href="/reservar" class="font-medium text-primary hover:underline">Reserva en línea aquí</a>
                    </p>
                </div>
            </div>

            <p class="mt-4 text-center text-[11px] text-white/60">Impulsado por KuiraWebReserve · tus datos de pago nunca pasan por este sitio</p>
        </div>
    </div>
</template>
