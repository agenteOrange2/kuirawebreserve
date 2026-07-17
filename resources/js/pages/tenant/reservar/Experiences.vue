<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useEmbedResize } from '@/composables/useEmbedResize';

interface Photo {
    id: number;
    url: string;
    thumb_url: string;
}

interface SessionOption {
    id: number;
    starts_at: string;
    remaining: number;
}

interface ExperienceOption {
    id: number;
    name: string;
    description: string | null;
    includes: string[];
    duration_label: string | null;
    pricing_mode: 'per_person' | 'flat';
    price: number;
    price_label: string;
    min_people: number;
    max_people: number | null;
    photos: Photo[];
    // Fechas con cupo (todo el año). Los horarios de cada día se piden
    // aparte al elegir fecha — mandarlos todos sería un muro de sesiones.
    available_dates: string[];
}

interface BookingResult {
    code: string;
    experience: string;
    starts_at: string;
    people: number;
    total: number;
}

interface GatewayOption {
    provider: 'stripe' | 'mercadopago' | 'paypal';
    label: string;
}

interface PaymentOptions {
    gateways: GatewayOption[];
    transfer: { available: boolean; accounts_count: number };
}

interface PaymentResult {
    method: 'gateway' | 'transfer';
    provider?: string;
    amount: number;
    amount_label: string;
    checkout_url?: string;
    bank_accounts?: { banco: string; titular: string; cuenta: string }[];
    valid_hours?: number;
    return_url: string;
}

const props = defineProps<{
    property: { name: string; phone: string | null; currency: string };
}>();

// Widget incrustado: reporta su alto al iframe padre.
useEmbedResize();

const money = (n: number) => `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2 })} ${props.property.currency}`;
const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', { weekday: 'long', day: '2-digit', month: 'long', hour: '2-digit', minute: '2-digit' });
// 'T00:00:00' fuerza hora local: sin él, 'YYYY-MM-DD' se interpreta UTC y
// el día de la semana se corre en México.
const formatDateChip = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('es-MX', { weekday: 'short', day: '2-digit', month: 'short' });
const formatDateLong = (date: string) =>
    new Date(`${date}T00:00:00`).toLocaleDateString('es-MX', { weekday: 'long', day: '2-digit', month: 'long' });
const formatTime = (iso: string) => new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });

// ── Catálogo ──
const loading = ref(true);
const loadError = ref<string | null>(null);
const experiences = ref<ExperienceOption[]>([]);

onMounted(async () => {
    try {
        const { data } = await axios.get<{ experiences: ExperienceOption[] }>('/api/experiencias/list');
        experiences.value = data.experiences;
    } catch {
        loadError.value = 'No se pudieron cargar las experiencias. Intenta de nuevo en un momento.';
    } finally {
        loading.value = false;
    }
});

// ── Reservar ──
const selected = ref<ExperienceOption | null>(null);
const sessionId = ref<number | null>(null);
// Fecha elegida y los horarios de ESE día (se piden al servidor al elegir).
const selectedDate = ref('');
const daySessions = ref<SessionOption[]>([]);
const sessionsLoading = ref(false);
const people = ref(1);
const guestName = ref('');
const guestPhone = ref('');
const guestEmail = ref('');
const notes = ref('');
const honeypot = ref('');
const renderedAt = ref('');
const galleryIndex = ref(0);
const submitting = ref(false);
const submitError = ref<string | null>(null);
const result = ref<BookingResult | null>(null);

function choose(experience: ExperienceOption) {
    selected.value = experience;
    payment.value = null;
    paymentChoice.value = null;
    paymentError.value = null;
    sessionId.value = null;
    selectedDate.value = '';
    daySessions.value = [];
    people.value = experience.min_people;
    galleryIndex.value = 0;
    guestName.value = '';
    guestPhone.value = '';
    guestEmail.value = '';
    notes.value = '';
    honeypot.value = '';
    renderedAt.value = new Date().toISOString();
    submitError.value = null;
    // La fecha más próxima con lugares entra precargada — un tap menos.
    if (experience.available_dates.length) selectDate(experience.available_dates[0]);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Fechas rápidas (las 6 más próximas) — para más adelante está el calendario.
const quickDates = computed(() => selected.value?.available_dates.slice(0, 6) ?? []);

const dateAvailable = computed(
    () => !!selected.value && !!selectedDate.value && selected.value.available_dates.includes(selectedDate.value),
);

// La fecha elegida en el calendario no opera: las 3 con lugares más cercanas.
const nearestDates = computed(() => {
    if (!selected.value || !selectedDate.value || dateAvailable.value) return [];
    const target = new Date(`${selectedDate.value}T00:00:00`).getTime();
    return [...selected.value.available_dates]
        .sort((a, b) => Math.abs(new Date(`${a}T00:00:00`).getTime() - target) - Math.abs(new Date(`${b}T00:00:00`).getTime() - target))
        .slice(0, 3);
});

async function selectDate(date: string) {
    if (!selected.value || !date) return;
    selectedDate.value = date;
    sessionId.value = null;
    daySessions.value = [];
    if (!selected.value.available_dates.includes(date)) return;
    sessionsLoading.value = true;
    try {
        const { data } = await axios.get<{ sessions: SessionOption[] }>('/api/experiencias/sessions', {
            params: { experience_id: selected.value.id, date },
        });
        daySessions.value = data.sessions;
        if (data.sessions.length === 1) sessionId.value = data.sessions[0].id;
    } catch {
        daySessions.value = [];
    } finally {
        sessionsLoading.value = false;
    }
}

function onDateInput(event: Event) {
    const value = (event.target as HTMLInputElement).value;
    if (value) selectDate(value);
}

const currentSession = computed(() => daySessions.value.find((s) => s.id === sessionId.value) ?? null);

// Tope de personas: máximo por reserva de la experiencia Y cupo restante
// de la sesión elegida — el servidor lo vuelve a validar bajo lock.
const maxPeople = computed(() => {
    if (!selected.value) return 1;
    const byExperience = selected.value.max_people ?? 100;
    const bySession = currentSession.value?.remaining ?? byExperience;
    return Math.max(1, Math.min(byExperience, bySession));
});

function setPeople(n: number) {
    if (!selected.value) return;
    people.value = Math.max(selected.value.min_people, Math.min(n, maxPeople.value));
}

const total = computed(() => {
    if (!selected.value) return 0;
    return selected.value.pricing_mode === 'flat' ? selected.value.price : selected.value.price * people.value;
});

async function submit() {
    if (!selected.value || !sessionId.value) return;
    submitting.value = true;
    submitError.value = null;
    try {
        const { data } = await axios.post<BookingResult>('/api/experiencias/bookings', {
            experience_session_id: sessionId.value,
            people: people.value,
            guest_name: guestName.value,
            guest_phone: guestPhone.value,
            guest_email: guestEmail.value || null,
            notes: notes.value || null,
            website: honeypot.value,
            rendered_at: renderedAt.value,
        });
        result.value = data;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        preparePayment();
    } catch (error: any) {
        const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
        submitError.value = error.response?.data?.message ?? (errors ? Object.values(errors)[0]?.[0] : null) ?? 'No se pudo apartar. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

// ── Pago (mismo patrón que el wizard de habitaciones): si el hotel tiene
// métodos en línea se ofrecen aquí mismo; si no, el hotel contacta. ──
const paymentChoice = ref<PaymentOptions | null>(null);
const payment = ref<PaymentResult | null>(null);
const paymentLoading = ref(false);
const paymentError = ref<string | null>(null);

async function preparePayment() {
    paymentLoading.value = true;
    paymentError.value = null;
    payment.value = null;
    paymentChoice.value = null;
    try {
        const { data } = await axios.get<PaymentOptions>('/api/experiencias/payment-options');
        const optionsCount = data.gateways.length + Number(data.transfer.available);

        if (optionsCount >= 2) {
            paymentChoice.value = data;
        } else if (data.gateways.length === 1) {
            await requestPayment('gateway', data.gateways[0].provider);
        } else if (data.transfer.available) {
            await requestPayment('transfer');
        }
        // Sin métodos: se queda el mensaje "el hotel te contactará".
    } catch {
        paymentChoice.value = null;
    } finally {
        paymentLoading.value = false;
    }
}

async function requestPayment(method?: 'gateway' | 'transfer', provider?: GatewayOption['provider']) {
    if (!result.value) return;
    paymentLoading.value = true;
    paymentError.value = null;
    paymentChoice.value = null;
    try {
        const { data } = await axios.post<PaymentResult>(`/api/experiencias/bookings/${result.value.code}/payment`, { method, provider });
        payment.value = data;
        if (data.method === 'gateway' && data.checkout_url) {
            window.location.href = data.checkout_url;
        }
    } catch (error: any) {
        paymentError.value = error.response?.data?.message ?? 'No se pudo generar el cobro. Tu lugar sigue apartado; el hotel te contactará.';
    } finally {
        paymentLoading.value = false;
    }
}
</script>

<template>
    <Head :title="`Experiencias · ${property.name}`" />
    <div class="flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-8 sm:px-8">
        <div class="m-auto w-full max-w-4xl">
            <div class="mb-5 flex items-center gap-3 px-1 text-white">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10">
                    <Lucide icon="Compass" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-lg font-medium">{{ property.name }}</div>
                    <div class="text-xs text-white/70">Experiencias y recorridos · cupo en vivo</div>
                </div>
                <a v-if="property.phone" :href="`tel:${property.phone}`" class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20">
                    <Lucide icon="Phone" class="h-4 w-4" />
                </a>
            </div>

            <div class="overflow-hidden rounded-2xl bg-white shadow-2xl">
                <!-- ═══ Confirmación / pago ═══ -->
                <div v-if="result" class="p-5 text-center sm:p-7">
                    <div class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success">
                        <Lucide icon="Check" class="h-7 w-7" />
                    </div>
                    <h2 class="text-lg font-medium text-slate-800">¡Tu lugar quedó apartado!</h2>

                    <div class="mx-auto mt-4 max-w-xs rounded-xl bg-slate-50 p-4 text-left">
                        <div class="text-2xl font-semibold tracking-wide text-slate-800">{{ result.code }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ result.experience }}</div>
                        <div class="mt-1 text-xs text-slate-500">{{ formatDateTime(result.starts_at) }}</div>
                        <div class="mt-3 flex items-center justify-between border-t border-slate-200 pt-3 text-sm">
                            <span class="text-slate-500">{{ result.people }} persona{{ result.people === 1 ? '' : 's' }}</span>
                            <span class="text-base font-semibold text-slate-800">{{ money(result.total) }}</span>
                        </div>
                    </div>

                    <!-- Pago en línea: mismo flujo que el wizard de habitaciones -->
                    <div v-if="paymentLoading" class="mt-5">
                        <Lucide icon="RefreshCw" class="mx-auto h-7 w-7 animate-spin text-primary" />
                        <p class="mt-2 text-sm text-slate-500">Preparando tu cobro…</p>
                    </div>

                    <template v-else-if="paymentChoice && !payment">
                        <h3 class="mt-5 text-base font-medium text-slate-800">¿Cómo prefieres pagar?</h3>
                        <div class="mx-auto mt-3 max-w-sm space-y-2.5">
                            <button
                                v-for="gw in paymentChoice.gateways"
                                :key="gw.provider"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-primary/40 hover:bg-primary/5"
                                @click="requestPayment('gateway', gw.provider)"
                            >
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <Lucide icon="CreditCard" class="h-5 w-5" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-slate-800">Pagar con {{ gw.label }}</div>
                                    <div class="text-xs text-slate-500">Confirmación inmediata</div>
                                </div>
                            </button>
                            <button
                                v-if="paymentChoice.transfer.available"
                                type="button"
                                class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-primary/40 hover:bg-primary/5"
                                @click="requestPayment('transfer')"
                            >
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-info/10 text-info">
                                    <Lucide icon="Landmark" class="h-5 w-5" />
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-slate-800">Transferencia bancaria</div>
                                    <div class="text-xs text-slate-500">El hotel verifica tu comprobante</div>
                                </div>
                            </button>
                        </div>
                    </template>

                    <template v-else-if="payment?.method === 'transfer'">
                        <p class="mt-5 text-sm text-slate-600">
                            Transfiere <span class="font-medium">{{ payment.amount_label }}</span> y envía tu comprobante al hotel:
                        </p>
                        <div class="mx-auto mt-3 max-w-sm space-y-2 text-left">
                            <div v-for="acc in payment.bank_accounts" :key="acc.cuenta" class="rounded-xl border border-slate-200 p-3.5 text-sm">
                                <div class="font-medium text-slate-700">{{ acc.banco }}</div>
                                <div class="text-slate-500">{{ acc.titular }}</div>
                                <div class="mt-1 font-mono text-slate-700">{{ acc.cuenta }}</div>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-slate-400">Vigente por {{ payment.valid_hours }} horas.</p>
                    </template>

                    <template v-else-if="paymentError">
                        <p class="mt-5 rounded-lg bg-warning/10 px-3 py-2 text-sm text-slate-600">{{ paymentError }}</p>
                        <Button variant="primary" class="mt-3 shadow-md shadow-primary/20" @click="preparePayment">
                            <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" /> Intentar el pago de nuevo
                        </Button>
                    </template>

                    <template v-else-if="payment?.method === 'gateway'">
                        <Lucide icon="RefreshCw" class="mx-auto mt-5 h-7 w-7 animate-spin text-primary" />
                        <p class="mt-2 text-sm text-slate-500">Te estamos llevando a la página de pago segura…</p>
                    </template>

                    <p v-else class="mt-4 text-sm text-slate-500">
                        El hotel te contactará para confirmar y coordinar el pago. Guarda tu folio.
                    </p>

                    <button type="button" class="mt-5 text-sm font-medium text-primary hover:underline" @click="result = null; selected = null; payment = null; paymentChoice = null; paymentError = null">
                        Reservar otra experiencia
                    </button>
                </div>

                <!-- ═══ Detalle + reserva ═══ -->
                <div v-else-if="selected" class="p-5 sm:p-7">
                    <button type="button" class="mb-4 flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700" @click="selected = null">
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Todas las experiencias
                    </button>

                    <div v-if="selected.photos.length" class="mb-4">
                        <div class="h-56 w-full overflow-hidden rounded-xl bg-slate-100 sm:h-72">
                            <img :src="selected.photos[Math.min(galleryIndex, selected.photos.length - 1)].url" :alt="selected.name" class="h-full w-full object-cover" />
                        </div>
                        <div v-if="selected.photos.length > 1" class="mt-2 flex gap-2 overflow-x-auto pb-1">
                            <button
                                v-for="(photo, index) in selected.photos"
                                :key="photo.id"
                                type="button"
                                class="h-14 w-20 shrink-0 overflow-hidden rounded-lg border-2 transition"
                                :class="index === galleryIndex ? 'border-primary' : 'border-transparent opacity-70 hover:opacity-100'"
                                @click="galleryIndex = index"
                            >
                                <img :src="photo.thumb_url" alt="" class="h-full w-full object-cover" loading="lazy" />
                            </button>
                        </div>
                    </div>

                    <h2 class="text-lg font-medium text-slate-800">{{ selected.name }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span class="font-medium text-slate-700">{{ selected.price_label }}</span>
                        <span v-if="selected.duration_label" class="flex items-center gap-1"><Lucide icon="Clock" class="h-3.5 w-3.5" /> {{ selected.duration_label }}</span>
                    </div>
                    <p v-if="selected.description" class="mt-2 text-sm text-slate-500">{{ selected.description }}</p>
                    <div v-if="selected.includes.length" class="mt-2 flex flex-wrap gap-1.5">
                        <span v-for="item in selected.includes" :key="item" class="flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-600">
                            <Lucide icon="Check" class="h-3 w-3 text-success" /> {{ item }}
                        </span>
                    </div>

                    <!-- Fecha primero, horarios de ese día después: la venta
                         está abierta todo el año y listar cada sesión sería
                         un muro interminable. -->
                    <h3 class="mt-6 text-sm font-medium text-slate-800">Elige la fecha</h3>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button
                            v-for="date in quickDates"
                            :key="date"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs font-medium capitalize transition"
                            :class="selectedDate === date ? 'border-primary bg-primary/5 text-primary' : 'border-slate-200 text-slate-600 hover:border-primary/40'"
                            @click="selectDate(date)"
                        >
                            {{ formatDateChip(date) }}
                        </button>
                        <label class="flex items-center gap-1.5 rounded-full border border-dashed border-slate-300 px-3 py-1 text-xs text-slate-500">
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                            <input
                                type="date"
                                class="border-0 bg-transparent p-0 text-xs text-slate-600 focus:outline-none focus:ring-0"
                                :value="selectedDate"
                                :min="selected.available_dates[0]"
                                :max="selected.available_dates[selected.available_dates.length - 1]"
                                @change="onDateInput"
                            />
                        </label>
                    </div>

                    <!-- La fecha del calendario no opera: fechas cercanas con lugares -->
                    <template v-if="selectedDate && !dateAvailable">
                        <p class="mt-3 rounded-lg bg-warning/10 px-3 py-2 text-xs text-slate-600">
                            El {{ formatDateLong(selectedDate) }} no hay salidas. Fechas cercanas con lugares:
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="date in nearestDates"
                                :key="date"
                                type="button"
                                class="rounded-full border border-slate-200 px-3 py-1.5 text-xs font-medium capitalize text-slate-600 transition hover:border-primary/40"
                                @click="selectDate(date)"
                            >
                                {{ formatDateChip(date) }}
                            </button>
                        </div>
                    </template>

                    <!-- Horarios del día elegido -->
                    <template v-else-if="selectedDate">
                        <h3 class="mt-5 text-sm font-medium capitalize text-slate-800">{{ formatDateLong(selectedDate) }}</h3>
                        <div v-if="sessionsLoading" class="mt-3 py-3 text-center">
                            <Lucide icon="RefreshCw" class="mx-auto h-6 w-6 animate-spin text-primary" />
                        </div>
                        <div v-else-if="daySessions.length" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                            <button
                                v-for="session in daySessions"
                                :key="session.id"
                                type="button"
                                class="flex items-center justify-between gap-2 rounded-xl border p-3.5 text-left text-sm transition"
                                :class="sessionId === session.id ? 'border-primary bg-primary/5' : 'border-slate-200 hover:border-primary/40'"
                                @click="sessionId = session.id; setPeople(people)"
                            >
                                <span class="font-medium text-slate-700">{{ formatTime(session.starts_at) }}</span>
                                <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">{{ session.remaining }} lugares</span>
                            </button>
                        </div>
                        <p v-else class="mt-3 rounded-lg bg-warning/10 px-3 py-2 text-xs text-slate-600">
                            Los horarios de ese día se acaban de llenar; elige otra fecha.
                        </p>
                    </template>

                    <!-- Personas -->
                    <h3 class="mt-6 text-sm font-medium text-slate-800">¿Cuántas personas?</h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Mínimo {{ selected.min_people }}<template v-if="selected.max_people">, máximo {{ selected.max_people }} por reserva</template>.
                        <template v-if="selected.pricing_mode === 'flat'">El precio es por grupo: no cambia con las personas.</template>
                    </p>
                    <div class="mt-3 flex items-center gap-2">
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                            :disabled="people <= selected.min_people"
                            @click="setPeople(people - 1)"
                        >
                            <Lucide icon="Minus" class="h-3.5 w-3.5" />
                        </button>
                        <span class="w-6 text-center text-sm font-medium">{{ people }}</span>
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                            :disabled="people >= maxPeople"
                            @click="setPeople(people + 1)"
                        >
                            <Lucide icon="Plus" class="h-3.5 w-3.5" />
                        </button>
                    </div>

                    <!-- Datos -->
                    <h3 class="mt-6 text-sm font-medium text-slate-800">Tus datos</h3>
                    <div class="mt-3 space-y-4">
                        <div>
                            <FormLabel>Nombre completo *</FormLabel>
                            <FormInput v-model="guestName" type="text" placeholder="Como aparece en tu identificación" />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <FormLabel>Teléfono *</FormLabel>
                                <FormInput v-model="guestPhone" type="tel" placeholder="10 dígitos" />
                            </div>
                            <div>
                                <FormLabel>Email (opcional)</FormLabel>
                                <FormInput v-model="guestEmail" type="email" placeholder="tu@correo.com" />
                            </div>
                        </div>
                        <div>
                            <FormLabel>Notas (opcional)</FormLabel>
                            <FormTextarea v-model="notes" rows="2" placeholder="Alguna condición, alergia, petición…" />
                        </div>
                        <div class="h-px w-px overflow-hidden opacity-0" style="clip: rect(0, 0, 0, 0)" aria-hidden="true">
                            <label for="website">No llenar</label>
                            <input id="website" v-model="honeypot" type="text" tabindex="-1" autocomplete="off" />
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3">
                        <span class="text-sm text-slate-500">Total</span>
                        <span class="text-lg font-semibold text-slate-800">{{ money(total) }}</span>
                    </div>

                    <p v-if="submitError" class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ submitError }}</p>

                    <Button
                        variant="primary"
                        class="mt-4 w-full shadow-md shadow-primary/20"
                        :disabled="submitting || !sessionId || !guestName.trim() || !guestPhone.trim()"
                        @click="submit"
                    >
                        <Lucide :icon="submitting ? 'RefreshCw' : 'ShieldCheck'" class="mr-2 h-4 w-4" :class="submitting && 'animate-spin'" />
                        {{ submitting ? 'Apartando…' : 'Apartar mi lugar' }}
                    </Button>
                    <p class="mt-2.5 text-center text-[11px] text-slate-400">
                        No se pide ningún dato de tarjeta: el hotel te contacta para confirmar y coordinar el pago.
                    </p>
                </div>

                <!-- ═══ Lista ═══ -->
                <div v-else class="p-5 sm:p-7">
                    <h1 class="text-lg font-medium text-slate-800">Vive la experiencia completa</h1>
                    <p class="mt-1 text-sm text-slate-500">Recorridos y actividades con cupo limitado — aparta tu lugar en línea.</p>

                    <div v-if="loading" class="py-10 text-center">
                        <Lucide icon="RefreshCw" class="mx-auto h-8 w-8 animate-spin text-primary" />
                    </div>
                    <p v-else-if="loadError" class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ loadError }}</p>
                    <div v-else-if="!experiences.length" class="mt-6 flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 py-10 text-center">
                        <Lucide icon="Compass" class="h-8 w-8 text-slate-300" />
                        <p class="text-sm text-slate-500">Por ahora no hay experiencias con fechas disponibles. Vuelve pronto.</p>
                    </div>

                    <div v-else class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div
                            v-for="experience in experiences"
                            :key="experience.id"
                            class="overflow-hidden rounded-xl border border-slate-200 transition hover:border-primary/40 hover:shadow-lg hover:shadow-slate-200/60"
                        >
                            <div v-if="experience.photos.length" class="relative h-40 w-full bg-slate-100">
                                <img :src="experience.photos[0].thumb_url" :alt="experience.name" class="h-full w-full cursor-pointer object-cover" loading="lazy" @click="choose(experience)" />
                                <span v-if="experience.photos.length > 1" class="absolute bottom-2 right-2 flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-medium text-white">
                                    <Lucide icon="Image" class="h-3 w-3" /> {{ experience.photos.length }}
                                </span>
                            </div>
                            <div class="p-4">
                                <div class="font-medium text-slate-800">{{ experience.name }}</div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ experience.price_label }}<template v-if="experience.duration_label"> · {{ experience.duration_label }}</template>
                                </div>
                                <p v-if="experience.description" class="mt-2 line-clamp-2 text-xs text-slate-500">{{ experience.description }}</p>
                                <div class="mt-3 flex items-center justify-between gap-2">
                                    <span class="text-[11px] text-slate-400">{{ experience.available_dates.length }} fecha(s) disponible(s)</span>
                                    <Button variant="primary" size="sm" @click="choose(experience)">Reservar</Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p class="mt-6 text-center text-xs text-slate-400">
                        ¿Buscas habitación?
                        <a href="/reservar" class="font-medium text-primary hover:underline">Reserva tu estancia aquí</a>
                    </p>
                </div>
            </div>

            <p class="mt-4 text-center text-[11px] text-white/60">Impulsado por KuiraWebReserve · tus datos de pago nunca pasan por este sitio</p>
        </div>
    </div>
</template>
