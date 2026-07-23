<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useEmbedResize } from '@/composables/useEmbedResize';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import { useWizardAppearance } from '@/composables/useWizardAppearance';

interface TypePhoto {
    id: number;
    url: string;
    thumb_url: string;
}

interface Option {
    room_type_id: number;
    name: string;
    description: string | null;
    capacity: number;
    effective_capacity: number;
    photos: TypePhoto[];
    duration_label: string;
    total: number;
    available: boolean;
    rooms_count: number;
    advance_error: string | null;
}

// Experiencia (tour/recorrido) con sesiones en las fechas del grupo: se
// agrega como plus y suma al total consolidado.
interface ExperienceSessionOption {
    id: number;
    starts_at: string;
    remaining: number;
}

interface ExperienceOption {
    id: number;
    name: string;
    description: string | null;
    duration_label: string | null;
    pricing_mode: string;
    price: number;
    price_label: string;
    min_people: number;
    max_people: number | null;
    photos: TypePhoto[];
    sessions: ExperienceSessionOption[];
}

interface GroupHold {
    code: string;
    rooms: { room_type: string; rooms: number; total: number }[];
    experiences: {
        name: string | null;
        starts_at: string | null;
        people: number;
        total: number;
    }[];
    experiences_total: number;
    starts_at: string | null;
    ends_at: string | null;
    total: number;
    deposit: number;
    requires_prepayment: boolean;
    // Modo "ambos": pagar en línea es oferta, no obligación.
    payment_optional: boolean;
    hold_expires_at: string | null;
    hold_minutes: number;
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
    amount: number;
    amount_label: string;
    checkout_url?: string;
    bank_accounts?: { banco: string; titular: string; cuenta: string }[];
    whatsapps?: string[];
    valid_hours?: number;
    return_url: string;
}

const props = defineProps<{
    // Misma apariencia que el wizard (/reservas/ajustes): una sola
    // configuración para todas las páginas públicas.
    appearance: WizardAppearance;
    property: {
        name: string;
        logo_url: string | null;
        phone: string | null;
        currency: string;
        currency_secondary: string | null;
        exchange_rate: number | null;
        guest_policy: 'family' | 'adults_only';
        block_mode_label: string;
    };
    hasNightRates: boolean;
    hasBlockRates: boolean;
    holdMinutes: number;
    hasWizard: boolean;
    hasLookup: boolean;
    hasExperiences: boolean;
}>();

// Widget incrustado: reporta su alto al iframe padre.
useEmbedResize();
const { isDark, rootStyle } = useWizardAppearance(props.appearance);

const adultsOnly = computed(
    () => props.property.guest_policy === 'adults_only',
);
const bothModesAvailable = computed(
    () => props.hasNightRates && props.hasBlockRates,
);
const money = (n: number) =>
    `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2 })} ${props.property.currency}`;

// Doble moneda: referencia "aprox" en la segunda divisa (cobro en la primaria).
const secondaryMoney = (n: number): string => {
    const rate = props.property.exchange_rate;
    const sec = props.property.currency_secondary;
    if (!sec || !rate || rate <= 0) return '';
    const converted = n / rate;
    return `≈ $${converted.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${sec}`;
};

function localDateInput(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function roundedNowInput(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() + (30 - (d.getMinutes() % 30)), 0, 0);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

const mode = ref<'block' | 'night'>(
    props.hasBlockRates || !props.hasNightRates ? 'block' : 'night',
);
const arriveDate = ref(localDateInput(new Date()));
const departDate = ref(localDateInput(new Date(Date.now() + 86400000)));
const arriveAt = ref(roundedNowInput());

const searching = ref(false);
const searchError = ref<string | null>(null);
const searched = ref(false);
const options = ref<Option[]>([]);

// Por tipo: cuántas habitaciones y cuántas personas POR habitación.
interface LineState {
    rooms: number;
    adults: number;
    children: number;
}
const lines = ref<Record<number, LineState>>({});

function dateParams(): Record<string, unknown> {
    const params: Record<string, unknown> = { mode: mode.value };
    if (mode.value === 'night') {
        params.arrive_date = arriveDate.value;
        params.depart_date = departDate.value;
    } else {
        params.arrive_at = arriveAt.value;
    }
    return params;
}

async function search() {
    searching.value = true;
    searchError.value = null;
    searched.value = false;
    lines.value = {};
    try {
        const { data } = await axios.get('/api/grupos/availability', {
            params: dateParams(),
        });
        options.value = data.options;
        searched.value = true;
    } catch (error: any) {
        searchError.value =
            error.response?.data?.message ??
            'No se pudo consultar disponibilidad. Intenta de nuevo.';
    } finally {
        searching.value = false;
    }
}

function lineFor(option: Option): LineState {
    return (lines.value[option.room_type_id] ??= {
        rooms: 0,
        adults: 2,
        children: 0,
    });
}

function setRooms(option: Option, n: number) {
    const line = lineFor(option);
    line.rooms = Math.max(0, Math.min(n, Math.min(option.rooms_count, 15)));
}

function setAdults(option: Option, n: number) {
    const line = lineFor(option);
    line.adults = Math.max(
        1,
        Math.min(n, option.effective_capacity - line.children),
    );
}

function setChildren(option: Option, n: number) {
    const line = lineFor(option);
    line.children = Math.max(
        0,
        Math.min(n, option.effective_capacity - line.adults),
    );
}

const totalRooms = computed(() =>
    Object.values(lines.value).reduce((sum, l) => sum + l.rooms, 0),
);
const totalPeople = computed(() =>
    Object.values(lines.value).reduce(
        (sum, l) => sum + l.rooms * (l.adults + l.children),
        0,
    ),
);
// Estimado con el precio "desde" por habitación + experiencias elegidas;
// el total REAL (con cargos por persona extra) lo calcula el servidor al
// apartar y es el que se cobra.
const estimatedTotal = computed(
    () =>
        options.value.reduce(
            (sum, option) =>
                sum +
                (lines.value[option.room_type_id]?.rooms ?? 0) * option.total,
            0,
        ) + experiencesSubtotal.value,
);

// Resumen del grupo (paso de datos): solo los tipos con habitaciones pedidas.
const selectedOptions = computed(() =>
    options.value.filter((o) => (lines.value[o.room_type_id]?.rooms ?? 0) > 0),
);

function occupancyLabel(line: { adults: number; children: number }): string {
    const adults = `${line.adults} ${line.adults === 1 ? 'adulto' : 'adultos'}`;
    const children = line.children
        ? ` + ${line.children} ${line.children === 1 ? 'niño' : 'niños'}`
        : '';
    return `${adults}${children} por habitación`;
}

// ── Experiencias (módulo `experiencias`): tours con sesiones en las
// fechas del grupo, se agregan como plus del GRP- ──
const experiencesCatalog = ref<{
    enabled: boolean;
    experiences: ExperienceOption[];
} | null>(null);

interface ExperiencePick {
    session_id: number;
    experience_id: number;
    people: number;
}

const experiencePicks = ref<ExperiencePick[]>([]);
const expDraft = ref<
    Record<number, { session_id: number | ''; people: number }>
>({});

async function loadExperiences() {
    const start =
        mode.value === 'night' ? arriveDate.value : arriveAt.value.slice(0, 10);
    const end = mode.value === 'night' ? departDate.value : start;
    try {
        const { data } = await axios.get('/api/grupos/experiences', {
            params: { start, end },
        });
        experiencesCatalog.value = data;
        const drafts: Record<
            number,
            { session_id: number | ''; people: number }
        > = {};
        (data.experiences as ExperienceOption[]).forEach((exp) => {
            drafts[exp.id] = {
                session_id: '',
                people: Math.max(1, exp.min_people),
            };
        });
        expDraft.value = drafts;
    } catch {
        experiencesCatalog.value = { enabled: false, experiences: [] };
    }
}

function experienceById(id: number): ExperienceOption | undefined {
    return experiencesCatalog.value?.experiences.find((e) => e.id === id);
}

const experienceLines = computed(() =>
    experiencePicks.value.flatMap((pick) => {
        const exp = experienceById(pick.experience_id);
        const session = exp?.sessions.find((s) => s.id === pick.session_id);
        if (!exp || !session) return [];
        return [
            {
                id: `s-${pick.session_id}`,
                session_id: pick.session_id,
                name: exp.name,
                starts_at: session.starts_at,
                people: pick.people,
                total:
                    exp.pricing_mode === 'flat'
                        ? exp.price
                        : exp.price * pick.people,
            },
        ];
    }),
);

const experiencesSubtotal = computed(() =>
    experienceLines.value.reduce((sum, line) => sum + line.total, 0),
);

function addExperiencePick(exp: ExperienceOption) {
    const draft = expDraft.value[exp.id];
    if (!draft || draft.session_id === '') return;
    const session = exp.sessions.find((s) => s.id === Number(draft.session_id));
    if (!session) return;
    const cap = Math.min(
        session.remaining,
        exp.max_people ?? session.remaining,
    );
    const people = Math.max(exp.min_people, Math.min(draft.people, cap));
    experiencePicks.value = [
        ...experiencePicks.value.filter((p) => p.session_id !== session.id),
        { session_id: session.id, experience_id: exp.id, people },
    ];
    draft.session_id = '';
    draft.people = Math.max(1, exp.min_people);
}

function removeExperiencePick(sessionId: number) {
    experiencePicks.value = experiencePicks.value.filter(
        (p) => p.session_id !== sessionId,
    );
}

// ── Datos y confirmación ──
const step = ref<'search' | 'guest' | 'confirm'>('search');
const guestName = ref('');
const guestPhone = ref('');
const guestEmail = ref('');
const notes = ref('');
const honeypot = ref('');
const renderedAt = ref('');
const submitting = ref(false);
const submitError = ref<string | null>(null);
const hold = ref<GroupHold | null>(null);

function continueToGuest() {
    if (totalRooms.value < 2) return;
    renderedAt.value = new Date().toISOString();
    submitError.value = null;
    experiencePicks.value = [];
    // Con fechas firmes: qué tours tienen sesiones en la estancia del grupo.
    loadExperiences();
    step.value = 'guest';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function submitHold() {
    submitting.value = true;
    submitError.value = null;
    try {
        const payload = {
            ...dateParams(),
            guest_name: guestName.value,
            guest_phone: guestPhone.value,
            guest_email: guestEmail.value || null,
            notes: notes.value || null,
            website: honeypot.value,
            rendered_at: renderedAt.value,
            lines: Object.entries(lines.value)
                .filter(([, line]) => line.rooms > 0)
                .map(([roomTypeId, line]) => ({
                    room_type_id: Number(roomTypeId),
                    rooms: line.rooms,
                    adults: line.adults,
                    children: adultsOnly.value ? 0 : line.children,
                })),
            experiences: experiencePicks.value.map((pick) => ({
                session_id: pick.session_id,
                people: pick.people,
            })),
        };
        const { data } = await axios.post<GroupHold>(
            '/api/grupos/holds',
            payload,
        );
        hold.value = data;
        step.value = 'confirm';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (data.requires_prepayment) preparePayment();
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            Record<string, string[]> | undefined;
        submitError.value =
            error.response?.data?.message ??
            (errors ? Object.values(errors)[0]?.[0] : null) ??
            'No se pudo apartar el grupo. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

// ── Pago consolidado: un solo cobro por todo el grupo ──
const paymentChoice = ref<PaymentOptions | null>(null);
const payment = ref<PaymentResult | null>(null);
const paymentLoading = ref(false);
const paymentError = ref<string | null>(null);

// Con anticipos configurados el responsable elige: pagar lo mínimo para
// apartar (anticipos + tours) o liquidar todo el grupo de una vez.
const payAmountChoice = ref<'deposit' | 'full'>('deposit');
const depositApplies = computed(
    () => !!hold.value && hold.value.deposit > 0 && hold.value.deposit < hold.value.total,
);
// Modo "ambos": el responsable eligió pagar al llegar.
const payLater = ref(false);

// Avisa al backend para que el apartado del grupo pase del hold corto al
// plazo de efectivo del hotel (default 24 h, tope: la llegada de cada
// reserva). Si falla, la confirmación se muestra igual con el plazo corto.
async function choosePayLater() {
    payLater.value = true;
    if (!hold.value) return;
    try {
        const { data } = await axios.post<{ hold_expires_at: string | null }>(
            `/api/grupos/holds/${hold.value.code}/pay-later`,
        );
        if (data.hold_expires_at) {
            hold.value.hold_expires_at = data.hold_expires_at;
        }
    } catch {
        // Sin extensión: el plazo corto del hold sigue mandando.
    }
}

async function preparePayment() {
    paymentLoading.value = true;
    paymentError.value = null;
    payment.value = null;
    paymentChoice.value = null;
    payAmountChoice.value = 'deposit';
    payLater.value = false;
    try {
        const { data } = await axios.get<PaymentOptions>(
            '/api/grupos/payment-options',
        );
        const optionsCount =
            data.gateways.length + Number(data.transfer.available);

        // Efectivo activo sin ningún método en línea listo: nada que
        // elegir — se confirma directo y se paga en el hotel.
        if (hold.value?.payment_optional && optionsCount === 0) {
            await choosePayLater();
            return;
        }

        // Con anticipo o con efectivo activo SIEMPRE se muestra la
        // elección, aunque solo haya un método disponible.
        if (
            optionsCount >= 2 ||
            ((depositApplies.value || hold.value?.payment_optional) &&
                optionsCount >= 1)
        ) {
            paymentChoice.value = data;
        } else if (data.gateways.length === 1) {
            await requestPayment('gateway', data.gateways[0].provider);
        } else if (data.transfer.available) {
            await requestPayment('transfer');
        }
    } catch {
        paymentChoice.value = null;
    } finally {
        paymentLoading.value = false;
    }
}

async function requestPayment(
    method?: 'gateway' | 'transfer',
    provider?: GatewayOption['provider'],
) {
    if (!hold.value) return;
    paymentLoading.value = true;
    paymentError.value = null;
    paymentChoice.value = null;
    try {
        const { data } = await axios.post<PaymentResult>(
            `/api/grupos/holds/${hold.value.code}/payment`,
            {
                method,
                provider,
                pay: depositApplies.value ? payAmountChoice.value : undefined,
            },
        );
        payment.value = data;
        if (data.method === 'gateway' && data.checkout_url) {
            window.location.href = data.checkout_url;
        }
    } catch (error: any) {
        paymentError.value =
            error.response?.data?.message ??
            'No se pudo generar el cobro. Tu grupo sigue apartado; el hotel te contactará.';
    } finally {
        paymentLoading.value = false;
    }
}

// ── Cuenta regresiva del apartado ──
const nowMs = ref(Date.now());
let clock: number | null = null;
onMounted(() => {
    clock = window.setInterval(() => (nowMs.value = Date.now()), 1000);
});
onBeforeUnmount(() => {
    if (clock) window.clearInterval(clock);
});

const holdCountdown = computed(() => {
    if (!hold.value?.hold_expires_at) return null;
    const diff = Date.parse(hold.value.hold_expires_at) - nowMs.value;
    if (diff <= 0) return 'Expiró';
    const m = Math.floor(diff / 60000);
    return `${m}:${String(Math.floor((diff % 60000) / 1000)).padStart(2, '0')}`;
});

const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
</script>

<template>
    <Head :title="`Reserva grupal · ${property.name}`" />
    <div
        class="flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-8 sm:px-8"
        :style="rootStyle"
    >
        <div class="m-auto w-full max-w-4xl">
            <div class="mb-5 flex items-center gap-3 px-1 text-white">
                <img
                    v-if="property.logo_url"
                    :src="property.logo_url"
                    :alt="`Logo de ${property.name}`"
                    class="h-11 w-11 shrink-0 rounded-full bg-white object-contain p-1"
                />
                <div
                    v-else
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10"
                >
                    <Lucide icon="UsersRound" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-lg font-medium">
                        {{ property.name }}
                    </div>
                    <div class="text-xs text-white/70">
                        Reserva grupal · varias habitaciones de un jalón
                    </div>
                </div>
                <a
                    v-if="property.phone"
                    :href="`tel:${property.phone}`"
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                >
                    <Lucide icon="Phone" class="h-4 w-4" />
                </a>
            </div>

            <div
                class="overflow-hidden rounded-2xl bg-white shadow-2xl"
                :class="isDark && 'booking-dark'"
            >
                <!-- ═══ Confirmación / pago ═══ -->
                <div
                    v-if="step === 'confirm' && hold"
                    class="p-5 text-center sm:p-7"
                >
                    <div
                        class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success"
                    >
                        <Lucide icon="Check" class="h-7 w-7" />
                    </div>
                    <h2 class="text-lg font-medium text-slate-800">
                        ¡Tu grupo quedó apartado!
                    </h2>

                    <div
                        class="mx-auto mt-4 max-w-sm rounded-xl bg-slate-50 p-4 text-left"
                    >
                        <div
                            class="text-2xl font-semibold tracking-wide text-slate-800"
                        >
                            {{ hold.code }}
                        </div>
                        <div
                            v-if="hold.starts_at"
                            class="mt-1 text-xs text-slate-500"
                        >
                            {{ formatDateTime(hold.starts_at)
                            }}<template v-if="hold.ends_at">
                                → {{ formatDateTime(hold.ends_at) }}</template
                            >
                        </div>
                        <div
                            class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-xs text-slate-500"
                        >
                            <div
                                v-for="line in hold.rooms"
                                :key="line.room_type"
                                class="flex justify-between"
                            >
                                <span
                                    >{{ line.rooms }}×
                                    {{ line.room_type }}</span
                                ><span>{{ money(line.total) }}</span>
                            </div>
                            <div
                                v-for="(line, i) in hold.experiences ?? []"
                                :key="`e-${i}`"
                                class="flex justify-between"
                            >
                                <span
                                    >{{ line.people }}× {{ line.name
                                    }}<template v-if="line.starts_at">
                                        ·
                                        {{
                                            formatDateTime(line.starts_at)
                                        }}</template
                                    ></span
                                >
                                <span>{{ money(line.total) }}</span>
                            </div>
                        </div>
                        <div
                            class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-base font-medium text-slate-800"
                        >
                            <span>Total del grupo</span
                            ><span>{{ money(hold.total) }}</span>
                        </div>
                        <div
                            v-if="secondaryMoney(hold.total)"
                            class="mt-0.5 text-right text-xs text-slate-400"
                        >
                            {{ secondaryMoney(hold.total) }}
                        </div>
                    </div>

                    <template v-if="hold.requires_prepayment && !payLater">
                        <div v-if="paymentLoading" class="mt-5">
                            <Lucide
                                icon="RefreshCw"
                                class="mx-auto h-7 w-7 animate-spin text-primary"
                            />
                            <p class="mt-2 text-sm text-slate-500">
                                Preparando tu cobro…
                            </p>
                        </div>

                        <template v-else-if="paymentChoice && !payment">
                            <h3
                                class="mt-5 text-base font-medium text-slate-800"
                            >
                                ¿Cómo prefieres pagar? Un solo pago por todo el
                                grupo.
                            </h3>

                            <!-- Con anticipo: cuánto pagar hoy lo decide el
                                 responsable, no el sistema -->
                            <div
                                v-if="depositApplies && hold"
                                class="mx-auto mt-4 max-w-sm text-left"
                            >
                                <div
                                    class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    ¿Cuánto pagan hoy?
                                </div>
                                <!-- Apiladas en celular: lado a lado quedan
                                     angostas y con el texto amontonado -->
                                <div
                                    class="grid grid-cols-1 gap-2.5 sm:grid-cols-2"
                                >
                                    <button
                                        type="button"
                                        class="rounded-xl border p-3.5 text-left transition"
                                        :class="
                                            payAmountChoice === 'deposit'
                                                ? 'border-primary bg-primary/5'
                                                : 'border-slate-200 hover:border-primary/40'
                                        "
                                        @click="payAmountChoice = 'deposit'"
                                    >
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            Solo el anticipo
                                        </div>
                                        <div
                                            class="mt-0.5 text-base font-semibold text-slate-800"
                                        >
                                            {{ money(hold.deposit) }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            El resto se paga después, por link o
                                            transferencia.
                                        </div>
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-xl border p-3.5 text-left transition"
                                        :class="
                                            payAmountChoice === 'full'
                                                ? 'border-primary bg-primary/5'
                                                : 'border-slate-200 hover:border-primary/40'
                                        "
                                        @click="payAmountChoice = 'full'"
                                    >
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            Todo de una vez
                                        </div>
                                        <div
                                            class="mt-0.5 text-base font-semibold text-slate-800"
                                        >
                                            {{ money(hold.total) }}
                                        </div>
                                        <div
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            Liquidan hoy y se olvidan de saldos.
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <div class="mx-auto mt-3 max-w-sm space-y-2.5">
                                <button
                                    v-for="gw in paymentChoice.gateways"
                                    :key="gw.provider"
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-primary/40 hover:bg-primary/5"
                                    @click="
                                        requestPayment('gateway', gw.provider)
                                    "
                                >
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                    >
                                        <Lucide
                                            icon="CreditCard"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            Pagar con {{ gw.label }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Confirmación inmediata
                                        </div>
                                    </div>
                                </button>
                                <button
                                    v-if="paymentChoice.transfer.available"
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-primary/40 hover:bg-primary/5"
                                    @click="requestPayment('transfer')"
                                >
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-info/10 text-info"
                                    >
                                        <Lucide
                                            icon="Landmark"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            Transferencia bancaria
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            El hotel verifica tu comprobante
                                        </div>
                                    </div>
                                </button>
                                <!-- Efectivo activo: pagar en el hotel también es opción -->
                                <button
                                    v-if="hold.payment_optional"
                                    type="button"
                                    class="flex w-full items-center gap-3 rounded-xl border border-slate-200 p-4 text-left transition hover:border-primary/40 hover:bg-primary/5"
                                    @click="choosePayLater()"
                                >
                                    <div
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500"
                                    >
                                        <Lucide
                                            icon="Banknote"
                                            class="h-5 w-5"
                                        />
                                    </div>
                                    <div class="min-w-0">
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            Preferimos pagar en el hotel
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Pagan al llegar, en recepción; el
                                            hotel confirma el grupo.
                                        </div>
                                    </div>
                                </button>
                            </div>
                            <p
                                v-if="holdCountdown"
                                class="mt-4 text-xs text-warning"
                            >
                                Tu grupo se libera solo en
                                {{ holdCountdown }} si no se completa el pago.
                            </p>
                        </template>

                        <template v-else-if="payment?.method === 'transfer'">
                            <div
                                class="mx-auto mt-5 max-w-sm space-y-3 text-left"
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                        >1</span
                                    >
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-slate-700">
                                            Transfiere
                                            <span class="font-medium">{{
                                                payment.amount_label
                                            }}</span>
                                            (un solo pago por el grupo) a una de
                                            estas cuentas:
                                        </p>
                                        <div class="mt-2 space-y-2">
                                            <div
                                                v-for="acc in payment.bank_accounts"
                                                :key="acc.cuenta"
                                                class="rounded-xl border border-slate-200 p-3.5 text-sm"
                                            >
                                                <div
                                                    class="font-medium text-slate-700"
                                                >
                                                    {{ acc.banco }}
                                                </div>
                                                <div class="text-slate-500">
                                                    {{ acc.titular }}
                                                </div>
                                                <div
                                                    class="mt-1 font-mono text-slate-700"
                                                >
                                                    {{ acc.cuenta }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span
                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                        >2</span
                                    >
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-slate-700">
                                            Manda tu comprobante con el folio
                                            <span class="font-medium">{{
                                                hold?.code
                                            }}</span
                                            >:
                                        </p>
                                        <template
                                            v-if="payment.whatsapps?.length"
                                        >
                                            <a
                                                v-for="wa in payment.whatsapps"
                                                :key="wa"
                                                :href="`https://wa.me/${wa}?text=${encodeURIComponent(`Hola, envío el comprobante de mi grupo ${hold?.code}`)}`"
                                                target="_blank"
                                                class="mt-2 flex items-center gap-3 rounded-xl border border-success/30 bg-success/5 p-3.5 text-left transition hover:bg-success/10"
                                            >
                                                <div
                                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                                >
                                                    <Lucide
                                                        icon="MessageCircle"
                                                        class="h-5 w-5"
                                                    />
                                                </div>
                                                <div class="min-w-0">
                                                    <div
                                                        class="text-sm font-medium text-slate-800"
                                                    >
                                                        Enviar por WhatsApp
                                                    </div>
                                                    <div
                                                        class="text-xs text-slate-500"
                                                    >
                                                        +{{ wa }}
                                                    </div>
                                                </div>
                                            </a>
                                        </template>
                                        <p
                                            v-else
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            El hotel te contactará para recibir
                                            tu comprobante.
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-start gap-3">
                                    <span
                                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                        >3</span
                                    >
                                    <p class="text-sm text-slate-700">
                                        En cuanto el hotel verifique el pago, el
                                        grupo queda confirmado y les avisamos.
                                    </p>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-slate-400">
                                Vigente por {{ payment.valid_hours }} horas.
                            </p>
                        </template>

                        <template v-else-if="paymentError">
                            <p
                                class="mt-5 rounded-lg bg-warning/10 px-3 py-2 text-sm text-slate-600"
                            >
                                {{ paymentError }}
                            </p>
                            <Button
                                variant="primary"
                                class="mt-3 shadow-md shadow-primary/20"
                                @click="preparePayment"
                            >
                                <Lucide icon="RefreshCw" class="mr-2 h-4 w-4" />
                                Intentar el pago de nuevo
                            </Button>
                        </template>

                        <template v-else-if="payment?.method === 'gateway'">
                            <Lucide
                                icon="RefreshCw"
                                class="mx-auto mt-5 h-7 w-7 animate-spin text-primary"
                            />
                            <p class="mt-2 text-sm text-slate-500">
                                Te estamos llevando a la página de pago segura…
                            </p>
                        </template>
                    </template>
                    <template v-else>
                        <p class="mt-3 text-sm text-slate-500">
                            El hotel confirmará tu grupo directamente. Guarda tu
                            folio.
                        </p>
                        <p
                            v-if="payLater && hold.hold_expires_at"
                            class="mt-2 text-xs text-warning"
                        >
                            Tienen hasta
                            {{ formatDateTime(hold.hold_expires_at) }} para
                            pagar en recepción; si no, el grupo se libera.
                        </p>
                        <p
                            v-else-if="holdCountdown"
                            class="mt-2 text-xs text-warning"
                        >
                            Se libera solo en {{ holdCountdown }} si el hotel no
                            confirma antes.
                        </p>
                    </template>
                </div>

                <!-- ═══ Datos del responsable ═══ -->
                <div v-else-if="step === 'guest'" class="p-5 sm:p-7">
                    <button
                        type="button"
                        class="mb-4 flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                        @click="step = 'search'"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Ajustar
                        habitaciones
                    </button>

                    <div class="rounded-xl bg-slate-50 p-4 sm:p-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="text-sm font-medium text-slate-800">
                                Tu grupo
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 shadow-sm"
                                >
                                    {{ totalRooms }} habitaciones
                                </span>
                                <span
                                    class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 shadow-sm"
                                >
                                    {{ totalPeople }} personas
                                </span>
                            </div>
                        </div>
                        <div class="mt-2 divide-y divide-slate-200/80">
                            <div
                                v-for="option in selectedOptions"
                                :key="option.room_type_id"
                                class="flex items-center justify-between gap-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate text-sm text-slate-700"
                                    >
                                        <span class="font-medium text-slate-800"
                                            >{{
                                                lines[option.room_type_id]
                                                    .rooms
                                            }}×</span
                                        >
                                        {{ option.name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-400">
                                        {{
                                            occupancyLabel(
                                                lines[option.room_type_id],
                                            )
                                        }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-sm text-slate-600">
                                    {{
                                        money(
                                            lines[option.room_type_id].rooms *
                                                option.total,
                                        )
                                    }}
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="experienceLines.length"
                            class="mt-2 divide-y divide-slate-200/80 border-t border-slate-200"
                        >
                            <div
                                v-for="line in experienceLines"
                                :key="line.id"
                                class="flex items-center justify-between gap-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate text-sm text-slate-700"
                                    >
                                        <span class="font-medium text-slate-800"
                                            >{{ line.people }}×</span
                                        >
                                        {{ line.name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-400">
                                        {{ formatDateTime(line.starts_at) }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span class="text-sm text-slate-600">{{
                                        money(line.total)
                                    }}</span>
                                    <button
                                        type="button"
                                        class="flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                        @click="
                                            removeExperiencePick(
                                                line.session_id,
                                            )
                                        "
                                    >
                                        <Lucide icon="X" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between border-t border-slate-200 pt-3"
                        >
                            <span class="text-sm text-slate-500">Estimado</span>
                            <span
                                class="text-base font-semibold text-slate-800"
                                >{{ money(estimatedTotal) }}</span
                            >
                        </div>
                        <p
                            v-if="secondaryMoney(estimatedTotal)"
                            class="text-right text-[11px] text-slate-400"
                        >
                            {{ secondaryMoney(estimatedTotal) }}
                        </p>
                        <p class="mt-2 text-[11px] text-slate-400">
                            El total final lo calcula el sistema al apartar
                            (incluye cargos por persona extra si aplican).
                        </p>
                    </div>

                    <!-- Experiencias en las fechas del grupo: un solo cobro con todo -->
                    <div v-if="experiencesCatalog?.enabled" class="mt-5">
                        <h2 class="text-base font-medium text-slate-800">
                            ¿Agregan un recorrido?
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            Opcional — se suma al mismo apartado y al mismo pago
                            del grupo.
                        </p>
                        <div class="mt-3 space-y-3">
                            <div
                                v-for="exp in experiencesCatalog.experiences"
                                :key="exp.id"
                                class="rounded-lg border border-slate-200/70 p-3"
                            >
                                <div class="flex items-start gap-3">
                                    <img
                                        v-if="exp.photos.length"
                                        :src="exp.photos[0].thumb_url"
                                        :alt="exp.name"
                                        class="h-14 w-20 shrink-0 rounded-lg object-cover"
                                        loading="lazy"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            {{ exp.name }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ exp.price_label
                                            }}<template
                                                v-if="exp.duration_label"
                                            >
                                                ·
                                                {{
                                                    exp.duration_label
                                                }}</template
                                            >
                                        </div>
                                        <p
                                            v-if="exp.description"
                                            class="mt-1 line-clamp-2 text-xs text-slate-500"
                                        >
                                            {{ exp.description }}
                                        </p>
                                    </div>
                                </div>
                                <div
                                    class="mt-3 grid grid-cols-12 items-end gap-2"
                                >
                                    <div class="col-span-12 sm:col-span-6">
                                        <label
                                            class="mb-1 block text-xs text-slate-500"
                                            >Fecha y horario</label
                                        >
                                        <select
                                            v-model="
                                                expDraft[exp.id].session_id
                                            "
                                            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 focus:border-primary/40 focus:outline-none"
                                        >
                                            <option value="" disabled>
                                                Elige una sesión
                                            </option>
                                            <option
                                                v-for="session in exp.sessions.filter(
                                                    (s) =>
                                                        !experiencePicks.some(
                                                            (p) =>
                                                                p.session_id ===
                                                                s.id,
                                                        ),
                                                )"
                                                :key="session.id"
                                                :value="session.id"
                                            >
                                                {{
                                                    formatDateTime(
                                                        session.starts_at,
                                                    )
                                                }}
                                                ·
                                                {{ session.remaining }}
                                                lugar(es)
                                            </option>
                                        </select>
                                    </div>
                                    <div class="col-span-6 sm:col-span-3">
                                        <label
                                            class="mb-1 block text-xs text-slate-500"
                                            >Personas</label
                                        >
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                :disabled="
                                                    expDraft[exp.id].people <=
                                                    exp.min_people
                                                "
                                                @click="
                                                    expDraft[exp.id].people =
                                                        Math.max(
                                                            exp.min_people,
                                                            expDraft[exp.id]
                                                                .people - 1,
                                                        )
                                                "
                                            >
                                                <Lucide
                                                    icon="Minus"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                            <span
                                                class="w-5 text-center text-sm font-medium"
                                                >{{
                                                    expDraft[exp.id].people
                                                }}</span
                                            >
                                            <button
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50"
                                                @click="
                                                    expDraft[exp.id].people =
                                                        Math.min(
                                                            exp.max_people ??
                                                                100,
                                                            expDraft[exp.id]
                                                                .people + 1,
                                                        )
                                                "
                                            >
                                                <Lucide
                                                    icon="Plus"
                                                    class="h-3.5 w-3.5"
                                                />
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-span-6 sm:col-span-3">
                                        <Button
                                            type="button"
                                            variant="outline-primary"
                                            class="w-full"
                                            :disabled="
                                                expDraft[exp.id].session_id ===
                                                ''
                                            "
                                            @click="addExperiencePick(exp)"
                                        >
                                            Agregar
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h2 class="mt-5 text-base font-medium text-slate-800">
                        Responsable del grupo
                    </h2>
                    <div class="mt-3 space-y-4">
                        <div>
                            <FormLabel>Nombre completo *</FormLabel>
                            <FormInput
                                v-model="guestName"
                                type="text"
                                placeholder="Quien responde por el grupo"
                            />
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <FormLabel>Teléfono *</FormLabel>
                                <FormInput
                                    v-model="guestPhone"
                                    type="tel"
                                    placeholder="10 dígitos"
                                />
                            </div>
                            <div>
                                <FormLabel>Email (opcional)</FormLabel>
                                <FormInput
                                    v-model="guestEmail"
                                    type="email"
                                    placeholder="tu@correo.com"
                                />
                            </div>
                        </div>
                        <div>
                            <FormLabel>Notas (opcional)</FormLabel>
                            <FormTextarea
                                v-model="notes"
                                rows="2"
                                placeholder="Boda, evento, hora de llegada del grupo…"
                            />
                        </div>
                        <div
                            class="h-px w-px overflow-hidden opacity-0"
                            style="clip: rect(0, 0, 0, 0)"
                            aria-hidden="true"
                        >
                            <label for="website">No llenar</label>
                            <input
                                id="website"
                                v-model="honeypot"
                                type="text"
                                tabindex="-1"
                                autocomplete="off"
                            />
                        </div>
                    </div>

                    <p
                        v-if="submitError"
                        class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ submitError }}
                    </p>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        :disabled="
                            submitting ||
                            !guestName.trim() ||
                            !guestPhone.trim()
                        "
                        @click="submitHold"
                    >
                        <Lucide
                            :icon="submitting ? 'RefreshCw' : 'ShieldCheck'"
                            class="mr-2 h-4 w-4"
                            :class="submitting && 'animate-spin'"
                        />
                        {{
                            submitting
                                ? 'Apartando…'
                                : `Apartar ${totalRooms} habitaciones`
                        }}
                    </Button>
                    <p class="mt-2.5 text-center text-[11px] text-slate-400">
                        Todo o nada: si alguna habitación ya no está disponible,
                        no se aparta ninguna. Se aparta por
                        {{ holdMinutes }} minutos mientras confirmas.
                    </p>
                </div>

                <!-- ═══ Fechas + habitaciones ═══ -->
                <div v-else class="p-5 sm:p-7">
                    <h1 class="text-lg font-medium text-slate-800">
                        ¿Vienen en grupo? Arma tu reserva completa
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        Elige cuántas habitaciones de cada tipo — un solo folio
                        y un solo pago.
                    </p>

                    <div
                        v-if="bothModesAvailable"
                        class="mt-5 inline-flex rounded-xl bg-slate-100 p-1 text-sm"
                    >
                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 font-medium transition"
                            :class="
                                mode === 'block'
                                    ? 'bg-white text-theme-1 shadow-sm'
                                    : 'text-slate-500'
                            "
                            @click="mode = 'block'"
                        >
                            {{ property.block_mode_label }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-4 py-2 font-medium transition"
                            :class="
                                mode === 'night'
                                    ? 'bg-white text-theme-1 shadow-sm'
                                    : 'text-slate-500'
                            "
                            @click="mode = 'night'"
                        >
                            Por noche(s)
                        </button>
                    </div>

                    <!-- En celular las fechas se apilan: dos columnas dejan
                         los date pickers al ras y se ve amontonado -->
                    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <template v-if="mode === 'night'">
                            <div>
                                <FormLabel>Llegada</FormLabel>
                                <FormInput
                                    v-model="arriveDate"
                                    type="date"
                                    :min="localDateInput(new Date())"
                                />
                            </div>
                            <div>
                                <FormLabel>Salida</FormLabel>
                                <FormInput
                                    v-model="departDate"
                                    type="date"
                                    :min="arriveDate"
                                />
                            </div>
                        </template>
                        <div v-else class="sm:col-span-2">
                            <FormLabel>Fecha y hora de llegada</FormLabel>
                            <FormInput
                                v-model="arriveAt"
                                type="datetime-local"
                            />
                        </div>
                    </div>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        :disabled="searching"
                        @click="search"
                    >
                        <Lucide
                            :icon="searching ? 'RefreshCw' : 'CalendarCheck2'"
                            class="mr-2 h-4 w-4"
                            :class="searching && 'animate-spin'"
                        />
                        {{ searching ? 'Buscando…' : 'Ver disponibilidad' }}
                    </Button>
                    <p v-if="searchError" class="mt-3 text-sm text-danger">
                        {{ searchError }}
                    </p>

                    <div v-if="searched" class="mt-6 space-y-3">
                        <div
                            v-for="option in options"
                            :key="option.room_type_id"
                            class="overflow-hidden rounded-xl border transition"
                            :class="
                                option.available
                                    ? 'border-slate-200'
                                    : 'border-slate-100 opacity-60'
                            "
                        >
                            <div class="flex flex-wrap items-start gap-4 p-4">
                                <img
                                    v-if="option.photos.length"
                                    :src="option.photos[0].thumb_url"
                                    :alt="option.name"
                                    class="h-20 w-28 shrink-0 rounded-lg object-cover"
                                    loading="lazy"
                                />
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium text-slate-800">
                                        {{ option.name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-500">
                                        {{ money(option.total) }} por habitación
                                        · hasta
                                        {{ option.effective_capacity }} personas
                                        ·
                                        {{
                                            option.available
                                                ? `quedan ${option.rooms_count}`
                                                : 'sin disponibilidad'
                                        }}
                                    </div>
                                    <p
                                        v-if="option.advance_error"
                                        class="mt-1 text-[11px] text-warning"
                                    >
                                        {{ option.advance_error }}
                                    </p>

                                    <div
                                        v-if="option.available"
                                        class="mt-3 flex flex-wrap items-end gap-5"
                                    >
                                        <div>
                                            <div
                                                class="mb-1 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                            >
                                                Habitaciones
                                            </div>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                    :disabled="
                                                        !lines[
                                                            option.room_type_id
                                                        ]?.rooms
                                                    "
                                                    @click="
                                                        setRooms(
                                                            option,
                                                            (lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.rooms ?? 0) - 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Minus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                                <span
                                                    class="w-5 text-center text-sm font-medium"
                                                    >{{
                                                        lines[
                                                            option.room_type_id
                                                        ]?.rooms ?? 0
                                                    }}</span
                                                >
                                                <button
                                                    type="button"
                                                    class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                    :disabled="
                                                        (lines[
                                                            option.room_type_id
                                                        ]?.rooms ?? 0) >=
                                                        Math.min(
                                                            option.rooms_count,
                                                            15,
                                                        )
                                                    "
                                                    @click="
                                                        setRooms(
                                                            option,
                                                            (lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.rooms ?? 0) + 1,
                                                        )
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Plus"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                </button>
                                            </div>
                                        </div>
                                        <template
                                            v-if="
                                                (lines[option.room_type_id]
                                                    ?.rooms ?? 0) > 0
                                            "
                                        >
                                            <div>
                                                <div
                                                    class="mb-1 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                                >
                                                    Adultos c/u
                                                </div>
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <button
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                        :disabled="
                                                            (lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.adults ?? 1) <= 1
                                                        "
                                                        @click="
                                                            setAdults(
                                                                option,
                                                                lines[
                                                                    option
                                                                        .room_type_id
                                                                ].adults - 1,
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Minus"
                                                            class="h-3.5 w-3.5"
                                                        />
                                                    </button>
                                                    <span
                                                        class="w-5 text-center text-sm font-medium"
                                                        >{{
                                                            lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.adults ?? 2
                                                        }}</span
                                                    >
                                                    <button
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                        :disabled="
                                                            (lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.adults ?? 0) +
                                                                (lines[
                                                                    option
                                                                        .room_type_id
                                                                ]?.children ??
                                                                    0) >=
                                                            option.effective_capacity
                                                        "
                                                        @click="
                                                            setAdults(
                                                                option,
                                                                lines[
                                                                    option
                                                                        .room_type_id
                                                                ].adults + 1,
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Plus"
                                                            class="h-3.5 w-3.5"
                                                        />
                                                    </button>
                                                </div>
                                            </div>
                                            <div v-if="!adultsOnly">
                                                <div
                                                    class="mb-1 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                                >
                                                    Niños c/u
                                                </div>
                                                <div
                                                    class="flex items-center gap-2"
                                                >
                                                    <button
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                        :disabled="
                                                            !(
                                                                lines[
                                                                    option
                                                                        .room_type_id
                                                                ]?.children ?? 0
                                                            )
                                                        "
                                                        @click="
                                                            setChildren(
                                                                option,
                                                                lines[
                                                                    option
                                                                        .room_type_id
                                                                ].children - 1,
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Minus"
                                                            class="h-3.5 w-3.5"
                                                        />
                                                    </button>
                                                    <span
                                                        class="w-5 text-center text-sm font-medium"
                                                        >{{
                                                            lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.children ?? 0
                                                        }}</span
                                                    >
                                                    <button
                                                        type="button"
                                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                                        :disabled="
                                                            (lines[
                                                                option
                                                                    .room_type_id
                                                            ]?.adults ?? 0) +
                                                                (lines[
                                                                    option
                                                                        .room_type_id
                                                                ]?.children ??
                                                                    0) >=
                                                            option.effective_capacity
                                                        "
                                                        @click="
                                                            setChildren(
                                                                option,
                                                                lines[
                                                                    option
                                                                        .room_type_id
                                                                ].children + 1,
                                                            )
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="Plus"
                                                            class="h-3.5 w-3.5"
                                                        />
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="totalRooms > 0"
                            class="rounded-xl bg-slate-50 px-4 py-3"
                        >
                            <div
                                class="flex items-center justify-between text-sm"
                            >
                                <span class="text-slate-500"
                                    >{{ totalRooms }} habitaciones ·
                                    {{ totalPeople }} personas</span
                                >
                                <span class="font-semibold text-slate-800">{{
                                    money(estimatedTotal)
                                }}</span>
                            </div>
                            <p
                                v-if="totalRooms === 1"
                                class="mt-1 text-[11px] text-warning"
                            >
                                Un grupo son dos habitaciones o más — para una
                                sola usa la
                                <a
                                    href="/reservar"
                                    class="font-medium underline"
                                    >reserva normal</a
                                >.
                            </p>
                        </div>

                        <Button
                            variant="primary"
                            class="w-full shadow-md shadow-primary/20"
                            :disabled="totalRooms < 2"
                            @click="continueToGuest"
                        >
                            <Lucide icon="ArrowRight" class="mr-2 h-4 w-4" />
                            Continuar con {{ totalRooms }} habitaciones
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Accesos relacionados: misma botonera que /reservar -->
            <div
                class="mt-5 flex flex-wrap items-center justify-center gap-2.5"
            >
                <a
                    v-if="hasWizard"
                    href="/reservar"
                    class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
                >
                    <Lucide icon="BedDouble" class="h-4 w-4" /> ¿Solo una
                    habitación? Reserva individual
                </a>
                <a
                    v-if="hasLookup"
                    href="/reserva"
                    class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
                >
                    <Lucide icon="TicketCheck" class="h-4 w-4" /> ¿Ya tienes una
                    reserva? Consulta su estado
                </a>
                <a
                    v-if="hasExperiences"
                    href="/reservar/experiencias"
                    class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
                >
                    <Lucide icon="Compass" class="h-4 w-4" /> Conoce nuestras
                    experiencias
                </a>
            </div>
            <p class="mt-3 text-center text-[11px] text-white/60">
                Impulsado por KuiraWebReserve · tus datos de pago nunca pasan
                por este sitio
            </p>
        </div>
    </div>
</template>
