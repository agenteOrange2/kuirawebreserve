<script setup lang="ts">
import axios from 'axios';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDateTime,
    FormInput,
    FormLabel,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import BackButton from '@/components/Booking/BackButton.vue';
import BookingCard from '@/components/Booking/BookingCard.vue';
import BookingModal from '@/components/Booking/BookingModal.vue';
import DateRangePicker from '@/components/Booking/DateRangePicker.vue';
import PaymentPanel from '@/components/Booking/PaymentPanel.vue';
import PhoneField from '@/components/Booking/PhoneField.vue';
import PublicShell from '@/components/Booking/PublicShell.vue';
import QtyStepper from '@/components/Booking/QtyStepper.vue';
import type { SummaryLine } from '@/components/Booking/StaySummary.vue';
import StaySummary from '@/components/Booking/StaySummary.vue';
import type { PublicContact } from '@/components/Booking/theme';
import type { DonutPart } from '@/components/Booking/TotalDonut.vue';
import { revealElement } from '@/composables/useEmbedResize';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import {
    downloadIcs,
    emailError,
    formatFriendlyDateTime,
    formatLongDay,
    formatMoney,
    formatStayRange,
    formatTime,
    humanizeDurationLabel,
    humanizeMinutes,
    internationalPhone,
    nightsBetween,
    phoneError,
} from '@/lib/bookingFormat';
import type { LongStayOffer, PriceLine } from '@/lib/bookingPricing';
import {
    breakdownPercent,
    breakdownSavings,
    longStayHint,
} from '@/lib/bookingPricing';

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
    price_breakdown?: PriceLine[];
    long_stay_offers?: LongStayOffer[];
    included_occupancy?: number | null;
    extra_guest_fee?: number | null;
    units?: number;
    available: boolean;
    rooms_count: number;
    advance_error: string | null;
}

// Experiencia (tour) con sesiones en las fechas del grupo: suma al total.
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

const props = defineProps<{
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
        check_in_time?: string;
        check_out_time?: string;
    };
    hasNightRates: boolean;
    hasBlockRates: boolean;
    holdMinutes: number;
    hasWizard: boolean;
    hasLookup: boolean;
    hasExperiences: boolean;
    cancellationPolicy?: { label: string | null; text: string | null };
    guarantee?: {
        amount: number;
        label: string;
        tiers: { from: number; amount: number }[];
        tiers_label: string | null;
    } | null;
    contact: PublicContact;
}>();

const adultsOnly = computed(
    () => props.property.guest_policy === 'adults_only',
);
const bothModesAvailable = computed(
    () => props.hasNightRates && props.hasBlockRates,
);
const money = (n: number) => formatMoney(n, props.property.currency);

function localDateInput(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function roundedNowInput(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() + (30 - (d.getMinutes() % 30)), 0, 0);
    return `${localDateInput(d)}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

type StepKey = 'search' | 'guest' | 'confirm';
const STEPS: { key: StepKey; label: string }[] = [
    { key: 'search', label: 'Fechas y habitaciones' },
    { key: 'guest', label: 'Responsable' },
    { key: 'confirm', label: 'Listo' },
];
const step = ref<StepKey>('search');
const stepTop = ref<HTMLElement | null>(null);
watch(step, async () => {
    await nextTick();
    revealElement(stepTop.value);
});

const mode = ref<'block' | 'night'>(
    props.hasBlockRates || !props.hasNightRates ? 'block' : 'night',
);
const arriveDate = ref(localDateInput(new Date()));
const departDate = ref(localDateInput(new Date(Date.now() + 86400000)));
const arriveAt = ref(roundedNowInput());

const searching = ref(false);
const searchError = ref<string | null>(null);
const searched = ref(false);
const staleSearch = ref(false);
const options = ref<Option[]>([]);
const resultsEl = ref<HTMLElement | null>(null);

const nights = computed(() =>
    mode.value === 'night'
        ? nightsBetween(arriveDate.value, departDate.value)
        : null,
);

const blockTimeError = computed(() => {
    if (mode.value !== 'block') return null;
    const at = Date.parse(arriveAt.value);
    if (Number.isNaN(at)) return 'Elige el día y la hora de llegada.';
    return at < Date.now() - 5 * 60000
        ? 'Esa hora ya pasó; elige una más adelante.'
        : null;
});

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

// Cambiar fechas después de buscar deja la lista vieja sin validez: se
// pide buscar otra vez (si no, el grupo se apartaba con fechas que la
// pantalla no mostraba).
watch([mode, arriveDate, departDate, arriveAt], () => {
    if (step.value !== 'search' || !searched.value) return;
    searched.value = false;
    options.value = [];
    lines.value = {};
    staleSearch.value = true;
});

async function search() {
    if (blockTimeError.value) return;
    searching.value = true;
    searchError.value = null;
    searched.value = false;
    staleSearch.value = false;
    lines.value = {};
    try {
        const { data } = await axios.get('/api/grupos/availability', {
            params: dateParams(),
        });
        options.value = data.options;
        searched.value = true;
        await nextTick();
        revealElement(resultsEl.value);
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
        adults: Math.min(2, option.effective_capacity),
        children: 0,
    });
}

function setRooms(option: Option, n: number) {
    lineFor(option).rooms = Math.max(
        0,
        Math.min(n, Math.min(option.rooms_count, 15)),
    );
}

const availableOptions = computed(() =>
    options.value.filter((o) => o.available),
);
const unavailableOptions = computed(() =>
    options.value.filter((o) => !o.available),
);

const totalRooms = computed(() =>
    Object.values(lines.value).reduce((sum, l) => sum + l.rooms, 0),
);
const totalPeople = computed(() =>
    Object.values(lines.value).reduce(
        (sum, l) => sum + l.rooms * (l.adults + l.children),
        0,
    ),
);
const selectedOptions = computed(() =>
    options.value.filter((o) => (lines.value[o.room_type_id]?.rooms ?? 0) > 0),
);
// Precio de UNA habitación de esa línea con sus personas: el "desde" viene
// cotizado para 1 adulto, así que la persona extra se suma aquí con la
// ficha del cuarto ofrecido (misma fórmula que Room::extraChargeLines).
// Antes el estimado nunca la incluía y el total subía al apartar.
function roomPrice(o: Option): number {
    const line = lines.value[o.room_type_id];
    const people = line ? line.adults + line.children : 1;
    const included = o.included_occupancy ?? 0;
    const fee = o.extra_guest_fee ?? 0;
    const extra =
        included > 0 && fee > 0 && people > included
            ? (people - included) * fee * Math.max(1, o.units ?? 1)
            : 0;
    return Math.round((o.total + extra) * 100) / 100;
}

function hasExtraGuests(o: Option): boolean {
    return roomPrice(o) > o.total;
}

// Descuento por semana/mes ya incluido en `total` (por habitación).
const savingsFor = (o: Option) => breakdownSavings(o.price_breakdown);
const percentFor = (o: Option) => breakdownPercent(o.price_breakdown);
const offerFor = (o: Option) =>
    longStayHint(o.long_stay_offers, props.property.currency);

const roomsSubtotal = computed(() =>
    selectedOptions.value.reduce(
        (sum, o) => sum + lines.value[o.room_type_id].rooms * roomPrice(o),
        0,
    ),
);
// Estimado con la habitación que hoy se ofrece de cada tipo: el total REAL
// lo recalcula el servidor al apartar y es el que se cobra.
const estimatedTotal = computed(
    () => roomsSubtotal.value + experiencesSubtotal.value,
);

function occupancyLabel(line: { adults: number; children: number }): string {
    const adults = `${line.adults} ${line.adults === 1 ? 'adulto' : 'adultos'}`;
    const children = line.children
        ? ` + ${line.children} ${line.children === 1 ? 'niño' : 'niños'}`
        : '';
    return `${adults}${children} por habitación`;
}

// ── Experiencias en las fechas del grupo ──
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
let experiencesRange = '';

async function loadExperiences() {
    const start =
        mode.value === 'night' ? arriveDate.value : arriveAt.value.slice(0, 10);
    const end = mode.value === 'night' ? departDate.value : start;
    // Volver a "Responsable" con las mismas fechas no borra los tours ya
    // agregados (antes se perdían al ir y volver).
    const range = `${start}|${end}`;
    if (range === experiencesRange && experiencesCatalog.value) return;
    experiencesRange = range;
    experiencePicks.value = [];
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

function experienceCap(exp: ExperienceOption): number {
    const draft = expDraft.value[exp.id];
    const session = exp.sessions.find(
        (s) => s.id === Number(draft?.session_id),
    );
    return Math.max(
        exp.min_people,
        Math.min(session?.remaining ?? 100, exp.max_people ?? 100),
    );
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
    const people = Math.max(
        exp.min_people,
        Math.min(draft.people, experienceCap(exp)),
    );
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

// ── Responsable ──
const guestName = ref('');
const guestPhone = ref('');
const phoneCountry = ref<'52' | '1' | 'otro'>('52');
const phoneLada = ref('');
const guestEmail = ref('');
const notes = ref('');
const honeypot = ref('');
const renderedAt = ref('');
const submitting = ref(false);
const submitError = ref<string | null>(null);
const guestAttempted = ref(false);
const hold = ref<GroupHold | null>(null);
const payLater = ref(false);
const paymentPanel = ref<InstanceType<typeof PaymentPanel> | null>(null);

const nameError = computed(() =>
    guestName.value.trim().length >= 3 ? null : 'Escribe el nombre completo.',
);
const guestPhoneError = computed(() =>
    phoneError(phoneCountry.value, guestPhone.value, phoneLada.value),
);
const guestEmailError = computed(() => emailError(guestEmail.value));
const guestValid = computed(
    () => !nameError.value && !guestPhoneError.value && !guestEmailError.value,
);

function continueToGuest() {
    if (totalRooms.value < 2) return;
    renderedAt.value = new Date().toISOString();
    submitError.value = null;
    loadExperiences();
    step.value = 'guest';
}

async function submitHold() {
    guestAttempted.value = true;
    if (!guestValid.value) return;
    submitting.value = true;
    submitError.value = null;
    try {
        const payload = {
            ...dateParams(),
            guest_name: guestName.value.trim(),
            guest_phone: internationalPhone(
                phoneCountry.value,
                guestPhone.value,
                phoneLada.value,
            ),
            guest_email: guestEmail.value.trim() || null,
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
        payLater.value = false;
        step.value = 'confirm';
        if (data.requires_prepayment) {
            await nextTick();
            paymentPanel.value?.start();
        }
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            | Record<string, string[]>
            | undefined;
        submitError.value =
            error.response?.data?.message ??
            (errors ? Object.values(errors)[0]?.[0] : null) ??
            'No se pudo apartar el grupo. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

function onPayLater(expiresAt: string | null) {
    payLater.value = true;
    if (hold.value && expiresAt) hold.value.hold_expires_at = expiresAt;
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

const holdExpired = computed(
    () =>
        !!hold.value?.hold_expires_at &&
        Date.parse(hold.value.hold_expires_at) <= nowMs.value,
);
const holdCountdown = computed(() => {
    if (!hold.value?.hold_expires_at || holdExpired.value) return null;
    const diff = Date.parse(hold.value.hold_expires_at) - nowMs.value;
    const m = Math.floor(diff / 60000);
    if (m >= 60) return `${Math.floor(m / 60)} h ${m % 60} min`;
    return `${m}:${String(Math.floor((diff % 60000) / 1000)).padStart(2, '0')}`;
});

function stayLabel(startIso: string, endIso: string): string {
    if (mode.value === 'night') return formatStayRange(startIso, endIso);
    return `Llegan ${formatFriendlyDateTime(startIso)} · salen ${formatFriendlyDateTime(endIso)}`;
}

const searchedStay = computed(() =>
    mode.value === 'night'
        ? formatStayRange(arriveDate.value, departDate.value)
        : `Llegan ${formatFriendlyDateTime(arriveAt.value)}`,
);

const codeCopied = ref(false);
async function copyCode() {
    if (!hold.value) return;
    try {
        await navigator.clipboard.writeText(hold.value.code);
        codeCopied.value = true;
        window.setTimeout(() => (codeCopied.value = false), 2000);
    } catch {
        // El código ya está a la vista.
    }
}

function addToCalendar() {
    if (!hold.value?.starts_at || !hold.value.ends_at) return;
    downloadIcs({
        uid: hold.value.code,
        title: `Grupo ${hold.value.code} · ${props.property.name}`,
        start: hold.value.starts_at,
        end: hold.value.ends_at,
        description: `Folio de grupo: ${hold.value.code}`,
        location: props.property.name,
    });
}

// ── "Tu grupo": vivo antes de apartar, el del servidor después ──
const summaryOpen = ref(false);

// La dona: cuánto pesa cada tipo de habitación en el total (y los tours).
// Más de tres tipos se juntan en "Otras habitaciones".
function partsFrom(
    rooms: { label: string; amount: number }[],
    experiences: number,
): DonutPart[] {
    const sorted = [...rooms].sort((a, b) => b.amount - a.amount);
    const head = sorted.slice(0, experiences > 0 ? 2 : 3);
    const rest = sorted.slice(head.length);
    return [
        ...head.map((r, i) => ({
            key: `r-${i}`,
            label: r.label,
            amount: r.amount,
        })),
        ...(rest.length
            ? [
                  {
                      key: 'r-rest',
                      label: 'Otras habitaciones',
                      amount: rest.reduce((s, r) => s + r.amount, 0),
                  },
              ]
            : []),
        { key: 'exp', label: 'Experiencias', amount: experiences },
    ];
}

const summary = computed(() => {
    if (hold.value) {
        const h = hold.value;
        const roomCount = h.rooms.reduce((s, r) => s + r.rooms, 0);
        return {
            photo: selectedOptions.value[0]?.photos[0]?.thumb_url ?? null,
            name: `Grupo · ${roomCount} habitaciones`,
            stay:
                h.starts_at && h.ends_at
                    ? stayLabel(h.starts_at, h.ends_at)
                    : searchedStay.value,
            occupancy: `${totalPeople.value} personas`,
            lines: [
                ...h.rooms.map((r, i) => ({
                    id: `r-${i}`,
                    label: `${r.rooms}× ${r.room_type}`,
                    amount: r.total,
                })),
                ...h.experiences.map((e, i) => ({
                    id: `e-${i}`,
                    label: `${e.people}× ${e.name ?? 'Experiencia'}`,
                    detail: e.starts_at
                        ? formatFriendlyDateTime(e.starts_at)
                        : null,
                    amount: e.total,
                })),
            ] as SummaryLine[],
            total: h.total,
            parts: partsFrom(
                h.rooms.map((r) => ({ label: r.room_type, amount: r.total })),
                h.experiences_total,
            ),
        };
    }
    if (!totalRooms.value) return null;
    return {
        photo: selectedOptions.value[0]?.photos[0]?.thumb_url ?? null,
        name: `Grupo · ${totalRooms.value} ${totalRooms.value === 1 ? 'habitación' : 'habitaciones'}`,
        stay: searchedStay.value,
        occupancy: `${totalPeople.value} personas`,
        lines: [
            ...selectedOptions.value.map((o) => ({
                id: `o-${o.room_type_id}`,
                label: `${lines.value[o.room_type_id].rooms}× ${o.name}`,
                detail:
                    occupancyLabel(lines.value[o.room_type_id]) +
                    (hasExtraGuests(o) ? ' · con persona extra' : ''),
                amount: lines.value[o.room_type_id].rooms * roomPrice(o),
                regularAmount: savingsFor(o)
                    ? lines.value[o.room_type_id].rooms *
                      (roomPrice(o) + savingsFor(o))
                    : null,
            })),
            ...experienceLines.value.map((l) => ({
                id: l.id,
                label: `${l.people}× ${l.name}`,
                detail: formatFriendlyDateTime(l.starts_at),
                amount: l.total,
                removable: step.value === 'guest',
            })),
        ] as SummaryLine[],
        total: estimatedTotal.value,
        parts: partsFrom(
            selectedOptions.value.map((o) => ({
                label: o.name,
                amount: lines.value[o.room_type_id].rooms * roomPrice(o),
            })),
            experiencesSubtotal.value,
        ),
    };
});

function removeSummaryLine(id: string) {
    if (id.startsWith('s-')) removeExperiencePick(Number(id.slice(2)));
}

const sectionBox = 'rounded-xl border border-slate-200';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary';
const groupLabel =
    'mb-2 text-xs font-medium tracking-wide text-slate-500 uppercase';
const primaryBtn = 'h-11 rounded-full px-6 text-sm shadow-md shadow-primary/20';
const linkPill =
    'inline-flex h-10 items-center gap-2 rounded-full border border-current/25 bg-current/10 px-4 text-sm font-medium transition hover:bg-current/20';
</script>

<template>
    <PublicShell
        title="Reserva grupal"
        tagline="Reserva grupal · varias habitaciones, un solo folio"
        :appearance="appearance"
        :property="property"
        :contact="contact"
        :steps="STEPS"
        :current-step="step"
    >
        <div ref="stepTop" class="scroll-mt-4" />

        <!-- ═══ Fechas y habitaciones ═══ -->
        <div v-if="step === 'search'" class="space-y-5">
            <BookingCard
                icon="UsersRound"
                title="¿Vienen en grupo? Arma su reserva completa"
                subtitle="Elige las fechas y cuántas habitaciones de cada tipo: un solo folio y un solo pago."
            >
                <div
                    v-if="bothModesAvailable"
                    class="mb-4 inline-flex rounded-full bg-slate-100 p-1 text-sm"
                    role="tablist"
                >
                    <button
                        v-for="m in ['night', 'block'] as const"
                        :key="m"
                        type="button"
                        role="tab"
                        class="inline-flex h-10 items-center gap-1.5 rounded-full px-4 font-medium transition"
                        :class="
                            mode === m
                                ? 'bg-white text-primary shadow-sm'
                                : 'text-slate-500 hover:text-slate-700'
                        "
                        :aria-selected="mode === m"
                        @click="mode = m"
                    >
                        <Lucide
                            :icon="m === 'night' ? 'Moon' : 'Clock'"
                            class="h-4 w-4"
                        />
                        {{
                            m === 'night'
                                ? 'Por noches'
                                : property.block_mode_label
                        }}
                    </button>
                </div>

                <div
                    v-if="mode === 'night'"
                    class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_250px]"
                >
                    <div :class="sectionBox" class="p-3 sm:p-4">
                        <p class="mb-2 px-1 text-sm text-slate-500">
                            Toca el día que llegan y luego el día que se van.
                        </p>
                        <DateRangePicker
                            v-model:start="arriveDate"
                            v-model:end="departDate"
                        />
                    </div>
                    <div class="rounded-xl bg-slate-50 p-4">
                        <div
                            class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                        >
                            Su estancia
                        </div>
                        <div class="mt-3 space-y-3">
                            <div class="flex items-start gap-2.5">
                                <Lucide
                                    icon="LogIn"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                />
                                <div>
                                    <div class="text-xs text-slate-500">
                                        Llegan
                                    </div>
                                    <div
                                        class="text-sm font-medium text-slate-800"
                                    >
                                        {{ formatLongDay(arriveDate) }}
                                    </div>
                                    <div
                                        v-if="property.check_in_time"
                                        class="text-xs text-slate-500"
                                    >
                                        desde las
                                        {{ formatTime(property.check_in_time) }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-start gap-2.5">
                                <Lucide
                                    icon="LogOut"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                />
                                <div>
                                    <div class="text-xs text-slate-500">
                                        Se van
                                    </div>
                                    <div
                                        class="text-sm font-medium text-slate-800"
                                    >
                                        {{ formatLongDay(departDate) }}
                                    </div>
                                    <div
                                        v-if="property.check_out_time"
                                        class="text-xs text-slate-500"
                                    >
                                        antes de las
                                        {{
                                            formatTime(property.check_out_time)
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            class="mt-3 flex items-center gap-2 border-t border-slate-200 pt-3 text-sm text-slate-700"
                        >
                            <Lucide icon="Moon" class="h-4 w-4 text-primary" />
                            {{ nights }} {{ nights === 1 ? 'noche' : 'noches' }}
                        </div>
                    </div>
                </div>
                <div v-else class="max-w-md">
                    <FormLabel for="group-arrive"
                        >Día y hora de llegada</FormLabel
                    >
                    <FormDateTime
                        id="group-arrive"
                        v-model="arriveAt"
                        input-class="h-11 text-sm"
                    />
                    <p v-if="blockTimeError" class="mt-1.5 text-xs text-danger">
                        {{ blockTimeError }}
                    </p>
                </div>

                <template #footer>
                    <p
                        v-if="searchError"
                        class="flex w-full items-start gap-2 text-sm text-danger sm:w-auto sm:flex-1"
                    >
                        <Lucide
                            icon="CircleAlert"
                            class="mt-0.5 h-4 w-4 shrink-0"
                        />
                        {{ searchError }}
                    </p>
                    <p
                        v-else-if="staleSearch"
                        class="flex w-full items-start gap-2 text-sm text-slate-600 sm:w-auto sm:flex-1"
                    >
                        <Lucide
                            icon="RefreshCw"
                            class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                        />
                        Cambiaron las fechas: busquen de nuevo para ver
                        disponibilidad real.
                    </p>
                    <Button
                        variant="primary"
                        class="w-full sm:ml-auto sm:w-auto"
                        :class="primaryBtn"
                        :disabled="searching || !!blockTimeError"
                        @click="search"
                    >
                        <Lucide
                            :icon="searching ? 'LoaderCircle' : 'Search'"
                            class="mr-2 h-4 w-4"
                            :class="searching && 'animate-spin'"
                        />
                        {{ searching ? 'Buscando…' : 'Ver disponibilidad' }}
                    </Button>
                </template>
            </BookingCard>

            <!-- Habitaciones del grupo -->
            <div v-if="searched" ref="resultsEl" class="scroll-mt-4">
                <BookingCard
                    v-if="availableOptions.length"
                    icon="BedDouble"
                    title="¿Cuántas habitaciones de cada tipo?"
                    :subtitle="searchedStay"
                    flush
                >
                    <div class="divide-y divide-slate-200">
                        <div
                            v-for="option in availableOptions"
                            :key="option.room_type_id"
                            class="px-5 py-4 sm:px-6"
                            :class="
                                (lines[option.room_type_id]?.rooms ?? 0) > 0
                                    ? 'bg-slate-50'
                                    : ''
                            "
                        >
                            <div class="flex flex-wrap items-center gap-4">
                                <img
                                    v-if="option.photos.length"
                                    :src="option.photos[0].thumb_url"
                                    :alt="option.name"
                                    class="h-16 w-24 shrink-0 rounded-lg object-cover"
                                    loading="lazy"
                                />
                                <div
                                    v-else
                                    class="flex h-16 w-24 shrink-0 items-center justify-center rounded-lg bg-slate-100"
                                >
                                    <Lucide
                                        icon="BedDouble"
                                        class="h-6 w-6 text-slate-300"
                                    />
                                </div>
                                <div class="min-w-0 flex-1 basis-48">
                                    <div
                                        class="text-base font-medium text-slate-800"
                                    >
                                        {{ option.name }}
                                    </div>
                                    <div
                                        class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-slate-500"
                                    >
                                        <span
                                            v-if="savingsFor(option)"
                                            class="text-slate-400 line-through"
                                            >{{
                                                money(
                                                    option.total +
                                                        savingsFor(option),
                                                )
                                            }}</span
                                        >
                                        <span
                                            >{{ money(option.total) }} c/u</span
                                        >
                                        <span
                                            v-if="savingsFor(option)"
                                            class="rounded-full bg-success/10 px-2 text-xs leading-5 font-medium text-success"
                                            >{{ percentFor(option) }}%
                                            menos</span
                                        >
                                        <span
                                            >Hasta
                                            {{ option.effective_capacity }}
                                            personas</span
                                        >
                                        <span
                                            :class="
                                                option.rooms_count <= 3 &&
                                                'font-medium text-warning'
                                            "
                                            >{{
                                                option.rooms_count === 1
                                                    ? 'Queda 1'
                                                    : `Quedan ${option.rooms_count}`
                                            }}</span
                                        >
                                    </div>
                                    <p
                                        v-if="option.advance_error"
                                        class="mt-1 text-xs text-warning"
                                    >
                                        {{ option.advance_error }}
                                    </p>
                                    <p
                                        v-else-if="offerFor(option)"
                                        class="mt-1 flex items-start gap-1.5 text-xs text-success"
                                    >
                                        <Lucide
                                            icon="BadgePercent"
                                            class="mt-px h-3.5 w-3.5 shrink-0"
                                        />
                                        {{ offerFor(option) }}
                                    </p>
                                </div>
                                <div class="ml-auto flex items-center gap-3">
                                    <span class="text-xs text-slate-500"
                                        >Habitaciones</span
                                    >
                                    <QtyStepper
                                        :model-value="
                                            lines[option.room_type_id]?.rooms ??
                                            0
                                        "
                                        :max="Math.min(option.rooms_count, 15)"
                                        label="habitación"
                                        @update:model-value="
                                            setRooms(option, $event)
                                        "
                                    />
                                </div>
                            </div>
                            <!-- Personas por habitación: solo si eligieron -->
                            <div
                                v-if="
                                    (lines[option.room_type_id]?.rooms ?? 0) > 0
                                "
                                class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg bg-white px-4 py-3 shadow-sm"
                            >
                                <span class="text-sm text-slate-600"
                                    >Por habitación:</span
                                >
                                <div class="flex items-center gap-3">
                                    <span class="text-sm text-slate-700"
                                        >Adultos</span
                                    >
                                    <QtyStepper
                                        v-model="
                                            lines[option.room_type_id].adults
                                        "
                                        :min="1"
                                        :max="
                                            option.effective_capacity -
                                            lines[option.room_type_id].children
                                        "
                                        label="adulto"
                                        size="sm"
                                    />
                                </div>
                                <div
                                    v-if="!adultsOnly"
                                    class="flex items-center gap-3"
                                >
                                    <span class="text-sm text-slate-700"
                                        >Niños</span
                                    >
                                    <QtyStepper
                                        v-model="
                                            lines[option.room_type_id].children
                                        "
                                        :min="0"
                                        :max="
                                            option.effective_capacity -
                                            lines[option.room_type_id].adults
                                        "
                                        label="niño"
                                        size="sm"
                                    />
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="unavailableOptions.length"
                            class="px-5 py-3 text-sm text-slate-500 sm:px-6"
                        >
                            Sin lugar en esas fechas:
                            {{
                                unavailableOptions
                                    .map((o) => o.name)
                                    .join(', ')
                            }}.
                        </div>
                    </div>

                    <template #footer>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-slate-800">
                                {{ totalRooms }}
                                {{
                                    totalRooms === 1
                                        ? 'habitación'
                                        : 'habitaciones'
                                }}
                                · {{ totalPeople }} personas
                            </div>
                            <div
                                v-if="totalRooms === 1"
                                class="text-xs text-warning"
                            >
                                Un grupo son dos habitaciones o más; para una
                                sola usa la
                                <a
                                    href="/reservar"
                                    class="font-medium underline"
                                    >reserva normal</a
                                >.
                            </div>
                            <div v-else class="text-xs text-slate-500">
                                <template v-if="totalRooms"
                                    >Aprox. {{ money(estimatedTotal) }}; el
                                    total exacto se confirma al
                                    apartar.</template
                                >
                                <template v-else
                                    >Agrega al menos dos habitaciones.</template
                                >
                            </div>
                        </div>
                        <Button
                            variant="primary"
                            class="w-full sm:w-auto"
                            :class="primaryBtn"
                            :disabled="totalRooms < 2"
                            @click="continueToGuest"
                        >
                            Continuar
                            <Lucide icon="ArrowRight" class="ml-2 h-4 w-4" />
                        </Button>
                    </template>
                </BookingCard>

                <BookingCard
                    v-else
                    icon="CalendarX2"
                    tone="warning"
                    title="Sin disponibilidad para esas fechas"
                    subtitle="Prueba con otras fechas, o escríbenos y armamos el grupo contigo."
                >
                    <div class="flex flex-wrap gap-2.5">
                        <a
                            href="/chat"
                            class="inline-flex h-11 items-center gap-2 rounded-full border border-slate-200 px-5 text-sm font-medium text-slate-700 transition hover:border-primary/40"
                        >
                            <Lucide
                                icon="MessageCircle"
                                class="h-4 w-4 text-primary"
                            />
                            Escríbenos
                        </a>
                        <a
                            v-if="property.phone"
                            :href="`tel:${property.phone}`"
                            class="inline-flex h-11 items-center gap-2 rounded-full border border-slate-200 px-5 text-sm font-medium text-slate-700 transition hover:border-primary/40"
                        >
                            <Lucide icon="Phone" class="h-4 w-4 text-primary" />
                            {{ property.phone }}
                        </a>
                    </div>
                </BookingCard>
            </div>
        </div>

        <!-- ═══ Responsable / listo, con "Tu grupo" al lado ═══ -->
        <div
            v-else
            class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_330px]"
        >
            <div class="min-w-0 space-y-4">
                <button
                    v-if="summary && step === 'guest'"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-2xl bg-white px-4 py-3 text-left shadow-xl shadow-black/10 lg:hidden"
                    @click="summaryOpen = true"
                >
                    <span :class="sectionIcon">
                        <Lucide icon="ReceiptText" class="h-4 w-4" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm font-medium text-slate-800"
                            >{{ summary.name }}</span
                        >
                        <span class="block truncate text-xs text-slate-500">{{
                            summary.stay
                        }}</span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span
                            class="block text-sm font-semibold text-slate-800"
                            >{{ money(summary.total) }}</span
                        >
                        <span class="block text-xs font-medium text-primary"
                            >Ver detalle</span
                        >
                    </span>
                </button>

                <!-- ═══ Responsable ═══ -->
                <BookingCard
                    v-if="step === 'guest'"
                    icon="UserRound"
                    title="¿Quién es el responsable del grupo?"
                    subtitle="A esta persona le confirmamos el folio y le escribimos cualquier cambio."
                >
                    <div class="space-y-6">
                        <!-- Experiencias en las fechas del grupo -->
                        <section
                            v-if="
                                experiencesCatalog?.enabled &&
                                experiencesCatalog.experiences.length
                            "
                        >
                            <div :class="groupLabel">
                                ¿Agregan una experiencia? (opcional)
                            </div>
                            <div class="space-y-3">
                                <div
                                    v-for="exp in experiencesCatalog.experiences"
                                    :key="exp.id"
                                    :class="sectionBox"
                                    class="p-4"
                                >
                                    <div class="flex items-start gap-3">
                                        <img
                                            v-if="exp.photos.length"
                                            :src="exp.photos[0].thumb_url"
                                            :alt="exp.name"
                                            class="h-16 w-20 shrink-0 rounded-lg object-cover"
                                            loading="lazy"
                                        />
                                        <div class="min-w-0 flex-1">
                                            <div
                                                class="text-sm font-medium text-slate-800"
                                            >
                                                {{ exp.name }}
                                            </div>
                                            <div class="text-sm text-slate-500">
                                                {{ exp.price_label
                                                }}<template
                                                    v-if="exp.duration_label"
                                                >
                                                    ·
                                                    {{
                                                        humanizeDurationLabel(
                                                            exp.duration_label,
                                                        )
                                                    }}</template
                                                >
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="mt-3 grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_auto_auto]"
                                    >
                                        <div>
                                            <FormLabel
                                                class="text-xs"
                                                :for="`gexp-${exp.id}`"
                                                >Fecha y horario</FormLabel
                                            >
                                            <FormSelect
                                                :id="`gexp-${exp.id}`"
                                                v-model="
                                                    expDraft[exp.id].session_id
                                                "
                                                class="h-11 text-sm"
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
                                                        formatFriendlyDateTime(
                                                            session.starts_at,
                                                        )
                                                    }}
                                                    ·
                                                    {{
                                                        session.remaining === 1
                                                            ? 'queda 1 lugar'
                                                            : `quedan ${session.remaining} lugares`
                                                    }}
                                                </option>
                                            </FormSelect>
                                        </div>
                                        <div
                                            class="flex items-center justify-between gap-3 sm:block"
                                        >
                                            <div
                                                class="text-xs text-slate-500 sm:mb-1"
                                            >
                                                Personas
                                            </div>
                                            <QtyStepper
                                                v-model="
                                                    expDraft[exp.id].people
                                                "
                                                :min="exp.min_people"
                                                :max="experienceCap(exp)"
                                                label="persona"
                                            />
                                        </div>
                                        <Button
                                            variant="outline-primary"
                                            class="h-11 rounded-full px-5 text-sm"
                                            :disabled="
                                                expDraft[exp.id].session_id ===
                                                ''
                                            "
                                            @click="addExperiencePick(exp)"
                                        >
                                            <Lucide
                                                icon="Plus"
                                                class="mr-1.5 h-4 w-4"
                                            />
                                            Agregar
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <form
                            class="space-y-5"
                            novalidate
                            @submit.prevent="submitHold"
                        >
                            <div>
                                <FormLabel for="g-name"
                                    >Nombre completo</FormLabel
                                >
                                <FormInput
                                    id="g-name"
                                    v-model="guestName"
                                    type="text"
                                    autocomplete="name"
                                    class="h-11 text-sm"
                                    :class="
                                        guestAttempted && nameError
                                            ? 'border-danger'
                                            : ''
                                    "
                                    placeholder="Quien coordina al grupo"
                                />
                                <p
                                    v-if="guestAttempted && nameError"
                                    class="mt-1.5 text-xs text-danger"
                                >
                                    {{ nameError }}
                                </p>
                            </div>
                            <div>
                                <FormLabel for="g-phone"
                                    >Teléfono (WhatsApp)</FormLabel
                                >
                                <PhoneField
                                    id="g-phone"
                                    v-model:country="phoneCountry"
                                    v-model:number="guestPhone"
                                    v-model:lada="phoneLada"
                                    :show-errors="guestAttempted"
                                    hint="Aquí le confirmamos el folio del grupo."
                                />
                            </div>
                            <div>
                                <FormLabel for="g-email">Correo</FormLabel>
                                <FormInput
                                    id="g-email"
                                    v-model="guestEmail"
                                    type="email"
                                    autocomplete="email"
                                    class="h-11 text-sm"
                                    :class="
                                        guestAttempted && guestEmailError
                                            ? 'border-danger'
                                            : ''
                                    "
                                    placeholder="tu@correo.com"
                                />
                                <p
                                    v-if="guestAttempted && guestEmailError"
                                    class="mt-1.5 text-xs text-danger"
                                >
                                    {{ guestEmailError }}
                                </p>
                            </div>
                            <div>
                                <FormLabel for="g-notes"
                                    >Algo que debamos saber</FormLabel
                                >
                                <FormTextarea
                                    id="g-notes"
                                    v-model="notes"
                                    rows="2"
                                    class="text-sm"
                                    placeholder="Motivo del viaje, hora de llegada, habitaciones juntas…"
                                />
                            </div>
                            <div
                                class="h-px w-px overflow-hidden opacity-0"
                                style="clip: rect(0, 0, 0, 0)"
                                aria-hidden="true"
                            >
                                <label for="g-website">No llenar</label>
                                <input
                                    id="g-website"
                                    v-model="honeypot"
                                    type="text"
                                    tabindex="-1"
                                    autocomplete="off"
                                />
                            </div>
                            <p
                                v-if="submitError"
                                class="flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger"
                            >
                                <Lucide
                                    icon="CircleAlert"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                />
                                {{ submitError }}
                            </p>
                            <p
                                v-else-if="guestAttempted && !guestValid"
                                class="flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                            >
                                <Lucide
                                    icon="CircleAlert"
                                    class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                                />
                                Revisa los campos marcados para continuar.
                            </p>
                        </form>
                    </div>

                    <template #footer>
                        <BackButton
                            label="Ajustar habitaciones"
                            @click="step = 'search'"
                        />
                        <Button
                            variant="primary"
                            class="ml-auto flex-1 sm:flex-none"
                            :class="primaryBtn"
                            :disabled="submitting"
                            @click="submitHold"
                        >
                            <Lucide
                                :icon="
                                    submitting ? 'LoaderCircle' : 'ShieldCheck'
                                "
                                class="mr-2 h-4 w-4"
                                :class="submitting && 'animate-spin'"
                            />
                            {{ submitting ? 'Apartando…' : 'Apartar el grupo' }}
                        </Button>
                        <p
                            class="w-full text-center text-xs text-slate-500 sm:text-right"
                        >
                            Quedan apartadas {{ humanizeMinutes(holdMinutes) }}
                            mientras terminan. Todo o nada: si una no se puede,
                            no se aparta ninguna.
                        </p>
                    </template>
                </BookingCard>

                <!-- ═══ Listo ═══ -->
                <template v-else-if="step === 'confirm' && hold">
                    <BookingCard
                        v-if="!hold.requires_prepayment || payLater"
                        icon="CircleCheck"
                        tone="success"
                        title="¡El grupo quedó apartado!"
                        subtitle="El hotel lo confirma directamente. Guarden el folio: lo pueden pedir en recepción."
                    >
                        <div class="rounded-xl bg-slate-50 px-4 py-3.5">
                            <div class="text-xs text-slate-500">
                                Folio del grupo
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="font-mono text-2xl font-semibold tracking-wide text-slate-800"
                                    >{{ hold.code }}</span
                                >
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 text-xs font-medium text-slate-600 transition hover:border-primary/40 hover:text-primary"
                                    @click="copyCode"
                                >
                                    <Lucide
                                        :icon="codeCopied ? 'Check' : 'Copy'"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ codeCopied ? 'Copiado' : 'Copiar' }}
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="
                                payLater && hold.hold_expires_at && !holdExpired
                            "
                            class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                        >
                            <Lucide
                                icon="Clock"
                                class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                            />
                            Tienen hasta
                            {{ formatFriendlyDateTime(hold.hold_expires_at) }}
                            para pagar en recepción; si no, el apartado se
                            libera.
                        </div>
                        <div
                            v-else-if="holdCountdown"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600"
                        >
                            <Lucide
                                icon="Clock"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            Si el hotel no lo confirma antes, se libera solo en
                            {{ holdCountdown }}.
                        </div>
                        <div
                            v-else-if="holdExpired"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                        >
                            <Lucide
                                icon="TimerOff"
                                class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                            />
                            El plazo del apartado terminó. Si el hotel aún no
                            les confirmó, escríbanle con su folio.
                        </div>
                        <template #footer>
                            <button
                                v-if="hold.starts_at && hold.ends_at"
                                type="button"
                                class="inline-flex h-11 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 text-sm font-medium text-slate-600 shadow-sm transition hover:border-slate-300"
                                @click="addToCalendar"
                            >
                                <Lucide icon="CalendarPlus" class="h-4 w-4" />
                                Agregar a mi calendario
                            </button>
                        </template>
                    </BookingCard>

                    <BookingCard
                        v-else
                        icon="Wallet"
                        title="Completa el pago del grupo"
                        :subtitle="
                            holdCountdown
                                ? `Folio ${hold.code} · se libera en ${holdCountdown} si no se paga`
                                : `Folio ${hold.code}`
                        "
                    >
                        <PaymentPanel
                            ref="paymentPanel"
                            :code="hold.code"
                            :total="hold.total"
                            :deposit="hold.deposit"
                            :currency="property.currency"
                            :payment-optional="hold.payment_optional"
                            :expired="holdExpired"
                            :phone="property.phone"
                            contact-notice="El grupo quedó apartado; te escribimos para completar el pago."
                            :urls="{
                                options: '/api/grupos/payment-options',
                                payment: `/api/grupos/holds/${hold.code}/payment`,
                                payLater: `/api/grupos/holds/${hold.code}/pay-later`,
                            }"
                            @pay-later="onPayLater"
                        />
                    </BookingCard>
                </template>
            </div>

            <!-- "Tu grupo" al costado -->
            <aside
                v-if="summary"
                :class="
                    step === 'confirm'
                        ? ''
                        : 'hidden lg:sticky lg:top-6 lg:block'
                "
            >
                <BookingCard icon="ReceiptText" title="Tu grupo">
                    <StaySummary
                        v-bind="summary"
                        :currency="property.currency"
                        :secondary-currency="property.currency_secondary"
                        :exchange-rate="property.exchange_rate"
                        @remove="removeSummaryLine"
                    >
                        <p v-if="!hold" class="mt-2 text-xs text-slate-500">
                            Estimado con las tarifas de hoy: el total exacto se
                            confirma al apartar.
                        </p>
                    </StaySummary>
                </BookingCard>
                <div
                    v-if="
                        guarantee ||
                        cancellationPolicy?.label ||
                        cancellationPolicy?.text
                    "
                    class="mt-4 space-y-3 rounded-2xl bg-white p-5 text-xs leading-relaxed text-slate-500 shadow-xl shadow-black/10"
                >
                    <div v-if="guarantee">
                        <div
                            class="mb-1 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                        >
                            <Lucide
                                icon="ShieldCheck"
                                class="h-4 w-4 text-primary"
                            />
                            Depósito en garantía
                        </div>
                        {{ guarantee.label }}
                        <template v-if="guarantee.tiers_label">
                            Por volumen: {{ guarantee.tiers_label }}.</template
                        >
                    </div>
                    <div
                        v-if="
                            cancellationPolicy?.label ||
                            cancellationPolicy?.text
                        "
                    >
                        <div
                            class="mb-1 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                        >
                            <Lucide icon="Undo2" class="h-4 w-4 text-primary" />
                            Política de cancelación
                        </div>
                        <p v-if="cancellationPolicy?.label">
                            {{ cancellationPolicy.label }}
                        </p>
                        <p v-if="cancellationPolicy?.text" class="mt-1">
                            {{ cancellationPolicy.text }}
                        </p>
                    </div>
                </div>
            </aside>
        </div>

        <BookingModal
            :open="summaryOpen"
            title="Tu grupo"
            icon="ReceiptText"
            width="md"
            @close="summaryOpen = false"
        >
            <StaySummary
                v-if="summary"
                v-bind="summary"
                :currency="property.currency"
                :secondary-currency="property.currency_secondary"
                :exchange-rate="property.exchange_rate"
                @remove="removeSummaryLine"
            >
                <p v-if="!hold" class="mt-2 text-xs text-slate-500">
                    Estimado con las tarifas de hoy: el total exacto se confirma
                    al apartar.
                </p>
                <div
                    v-if="guarantee"
                    class="mt-3 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500"
                >
                    <div class="mb-1 font-medium text-slate-700">
                        Depósito en garantía
                    </div>
                    {{ guarantee.label }}
                </div>
            </StaySummary>
        </BookingModal>

        <template #links>
            <a v-if="hasWizard" href="/reservar" :class="linkPill">
                <Lucide icon="BedDouble" class="h-4 w-4" />
                ¿Solo una habitación?
            </a>
            <a v-if="hasLookup" href="/reserva" :class="linkPill">
                <Lucide icon="TicketCheck" class="h-4 w-4" />
                Consultar una reserva
            </a>
            <a
                v-if="hasExperiences"
                href="/reservar/experiencias"
                :class="linkPill"
            >
                <Lucide icon="Compass" class="h-4 w-4" />
                Experiencias
            </a>
        </template>
    </PublicShell>
</template>
