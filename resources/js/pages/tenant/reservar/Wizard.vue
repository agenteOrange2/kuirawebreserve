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
import NightlyBars from '@/components/Booking/NightlyBars.vue';
import PaymentPanel from '@/components/Booking/PaymentPanel.vue';
import PhoneField from '@/components/Booking/PhoneField.vue';
import PhotoGallery from '@/components/Booking/PhotoGallery.vue';
import PublicShell from '@/components/Booking/PublicShell.vue';
import QtyStepper from '@/components/Booking/QtyStepper.vue';
import type { RoomOption } from '@/components/Booking/RoomOptionCard.vue';
import RoomOptionCard from '@/components/Booking/RoomOptionCard.vue';
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
import type { PriceLine } from '@/lib/bookingPricing';
import {
    breakdownDetail,
    breakdownLabel,
    breakdownPercent,
    breakdownSavings,
    longStayHint,
} from '@/lib/bookingPricing';
import { randomUuid } from '@/lib/uuid';

interface TypePhoto {
    id: number;
    url: string;
    thumb_url: string;
}

type Option = RoomOption;

interface ExtraProduct {
    id: number;
    name: string;
    category: string | null;
    unit: string;
    price: number;
    photo: TypePhoto | null;
}

// Add-on del módulo `extras` (decoración, desayuno, late checkout):
// sin stock, puro cargo que suma al total.
interface AddonOption {
    id: number;
    name: string;
    description: string | null;
    price: number;
}

interface ProductLine {
    product_id: number;
    name: string;
    qty: number;
    unit_price: number;
    total: number;
}

interface AddonLine {
    extra_id: number;
    name: string;
    qty: number;
    unit_price: number;
    total: number;
}

// Experiencia (tour/recorrido) con sesiones dentro de la estancia: se
// agrega como plus de la reserva y suma al total.
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

interface ExperienceLine {
    experience_booking_id?: number;
    session_id?: number;
    name: string;
    starts_at: string;
    people: number;
    unit_price: number;
    pricing_mode: string;
    total: number;
}

interface HoldResult {
    code: string;
    room_type: string;
    room_photo?: string | null;
    starts_at: string;
    ends_at: string;
    room_total: number;
    price_breakdown: PriceLine[];
    products: ProductLine[];
    products_total: number;
    extras: AddonLine[];
    extras_total: number;
    experiences: ExperienceLine[];
    experiences_total: number;
    // Cupón aplicado (módulo cupones): el descuento ya vive en total.
    coupon_code: string | null;
    discount: number;
    total: number;
    requires_prepayment: boolean;
    // Modo "ambos": el pago en línea se ofrece pero el huésped puede
    // elegir pagar al llegar.
    payment_optional: boolean;
    deposit: number;
    balance_notice?: string | null;
    hold_expires_at: string | null;
    hold_minutes: number;
}

// Pasos (restructurado 2026-07-11, a pedido explícito): "Fechas" ya NO
// pide personas — solo cuándo. Elegido el tipo, "Tu habitación" pregunta
// cuántos son con un tope duro (la ocupación real de ESE cuarto). "Extras"
// solo si el hotel lo activó Y tiene algo que ofrecer. "Datos" y
// "Confirmación" siempre cierran.
type StepKey = 'dates' | 'room' | 'extras' | 'guest' | 'confirm';
const STEP_LABELS: Record<StepKey, string> = {
    dates: 'Cuándo',
    room: 'Tu habitación',
    extras: 'Complementos',
    guest: 'Tus datos',
    confirm: 'Listo',
};

const props = defineProps<{
    // Apariencia elegida por el hotel en /reservas/ajustes (o defaults del
    // theme): colores del fondo, acento, modo claro/oscuro y logo.
    appearance: WizardAppearance;
    property: {
        name: string;
        logo_url: string | null;
        phone: string | null;
        currency: string;
        currency_secondary: string | null;
        exchange_rate: number | null;
        check_in_time: string;
        check_out_time: string;
        guest_policy: 'family' | 'adults_only';
        block_mode_label: string;
    };
    // Las modalidades NO son un toggle fijo: se detectan de las tarifas
    // activas reales del catálogo. Un motel 100% por bloque nunca ve la
    // pestaña "Por noche", y viceversa.
    hasNightRates: boolean;
    hasBlockRates: boolean;
    holdMinutes: number;
    // Política de cancelación default del hotel: se enseña ANTES de apartar.
    cancellationPolicy: { label: string | null; text: string | null };
    // Fianza que se cobra AL LLEGAR, aparte del precio.
    guarantee: {
        amount: number;
        label: string;
        tiers: { from: number; amount: number }[];
        tiers_label: string | null;
    } | null;
    hasExperiences: boolean;
    hasGroups: boolean;
    hasWaitlist: boolean;
    hasCoupons: boolean;
    // Embed de UNA sola habitación (?habitacion=<id> desde /integracion).
    lockedRoomType: { id: number; name: string } | null;
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

// ── Catálogo de extras (decide si el paso existe): products = POS con
// stock; addons = módulo extras (solo cargo) ──
const extrasCatalog = ref<{
    enabled: boolean;
    products: ExtraProduct[];
    addons: AddonOption[];
} | null>(null);

onMounted(async () => {
    try {
        const { data } = await axios.get('/api/booking/products');
        extrasCatalog.value = data;
    } catch {
        extrasCatalog.value = { enabled: false, products: [], addons: [] };
    }
});

const extrasByCategory = computed(() => {
    const groups = new Map<string, ExtraProduct[]>();
    (extrasCatalog.value?.products ?? []).forEach((p) => {
        const key = p.category ?? 'Otros';
        if (!groups.has(key)) groups.set(key, []);
        groups.get(key)!.push(p);
    });
    return [...groups.entries()];
});

const selectedProducts = ref<Record<number, number>>({});
const selectedAddons = ref<Record<number, number>>({});

function setProductQty(productId: number, qty: number) {
    selectedProducts.value = {
        ...selectedProducts.value,
        [productId]: Math.max(0, Math.min(20, qty)),
    };
}

function setAddonQty(addonId: number, qty: number) {
    selectedAddons.value = {
        ...selectedAddons.value,
        [addonId]: Math.max(0, Math.min(20, qty)),
    };
}

const productsSubtotal = computed(() =>
    (extrasCatalog.value?.products ?? []).reduce(
        (sum, p) => sum + (selectedProducts.value[p.id] ?? 0) * p.price,
        0,
    ),
);
const addonsSubtotal = computed(() =>
    (extrasCatalog.value?.addons ?? []).reduce(
        (sum, a) => sum + (selectedAddons.value[a.id] ?? 0) * a.price,
        0,
    ),
);

// Add-ons y productos en una sola lista para el resumen.
const selectedProductLines = computed(() => [
    ...(extrasCatalog.value?.addons ?? [])
        .filter((a) => (selectedAddons.value[a.id] ?? 0) > 0)
        .map((a) => ({
            id: `a-${a.id}`,
            name: a.name,
            qty: selectedAddons.value[a.id],
            total: selectedAddons.value[a.id] * a.price,
        })),
    ...(extrasCatalog.value?.products ?? [])
        .filter((p) => (selectedProducts.value[p.id] ?? 0) > 0)
        .map((p) => ({
            id: `p-${p.id}`,
            name: p.name,
            qty: selectedProducts.value[p.id],
            total: selectedProducts.value[p.id] * p.price,
        })),
]);

// ── Experiencias (módulo `experiencias`): tours con sesiones durante la
// estancia, se cargan al elegir habitación (ya hay fechas firmes) ──
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
    if (!props.hasExperiences || !selected.value) {
        experiencesCatalog.value = { enabled: false, experiences: [] };
        return;
    }
    try {
        const { data } = await axios.get('/api/booking/experiences', {
            params: {
                start: selected.value.starts_at,
                end: selected.value.ends_at,
            },
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

// Tope real de personas para el borrador: el cupo de la sesión elegida y
// el máximo del tour. Antes el + subía sin límite y se recortaba en
// silencio al agregar.
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
    // Una línea por sesión: volver a agregar la misma sesión la reemplaza.
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

// ── Orden de pasos (dinámico: "Complementos" solo si aplica) ──
const step = ref<StepKey>('dates');

const stepOrder = computed<StepKey[]>(() => [
    'dates',
    'room',
    ...(extrasCatalog.value?.enabled || experiencesCatalog.value?.enabled
        ? (['extras'] as StepKey[])
        : []),
    'guest',
    'confirm',
]);
const shellSteps = computed(() =>
    stepOrder.value.map((key) => ({ key, label: STEP_LABELS[key] })),
);

// Cada paso nuevo arranca arriba: antes se quedaba la vista donde estaba
// el botón del paso anterior (en el celular, a media página).
const stepTop = ref<HTMLElement | null>(null);
watch(step, async () => {
    await nextTick();
    revealElement(stepTop.value);
});

// Modo inicial: el que de verdad tenga tarifas; si hay ambas, bloque
// primero por ser el caso más común hoy.
const mode = ref<'block' | 'night'>(
    props.hasBlockRates || !props.hasNightRates ? 'block' : 'night',
);
const arriveDate = ref(localDateInput(new Date()));
const departDate = ref(localDateInput(new Date(Date.now() + 86400000)));
const arriveAt = ref(roundedNowInput());
const adults = ref(1);
const children = ref(0);

const searching = ref(false);
const searchError = ref<string | null>(null);
const searched = ref(false);
const options = ref<Option[]>([]);
const anyAvailable = ref(false);
const resultsEl = ref<HTMLElement | null>(null);
// Se buscó y luego se cambiaron las fechas: la lista vieja ya no aplica.
const staleSearch = ref(false);

// Por horas: una llegada que ya pasó se rechazaba en el servidor; se dice
// antes de buscar.
const blockTimeError = computed(() => {
    if (mode.value !== 'block') return null;
    const at = Date.parse(arriveAt.value);
    if (Number.isNaN(at)) return 'Elige el día y la hora de llegada.';
    return at < Date.now() - 5 * 60000
        ? 'Esa hora ya pasó; elige una más adelante.'
        : null;
});

const searchedNights = computed(() =>
    mode.value === 'night'
        ? nightsBetween(arriveDate.value, departDate.value)
        : null,
);

// Bug real (oct-2026): se buscaba, se cambiaban las fechas y se elegía de
// la lista vieja — el apartado se creaba con las fechas NUEVAS pero la
// pantalla mostraba el precio de las viejas. Cambiar fechas o modalidad
// borra los resultados y pide buscar otra vez.
watch([mode, arriveDate, departDate, arriveAt], () => {
    if (step.value !== 'dates' || !searched.value) return;
    searched.value = false;
    options.value = [];
    staleSearch.value = true;
});

// Orden de los resultados: disponibles primero, y el precio a elección.
const sortBy = ref<'recommended' | 'price_asc' | 'price_desc'>('recommended');
const sortedOptions = computed(() => {
    const list = [...options.value];
    if (sortBy.value === 'price_asc') list.sort((a, b) => a.total - b.total);
    if (sortBy.value === 'price_desc') list.sort((a, b) => b.total - a.total);
    return list.sort((a, b) => Number(b.available) - Number(a.available));
});
const availableCount = computed(
    () => options.value.filter((o) => o.available).length,
);

async function searchAvailability() {
    if (blockTimeError.value) return;
    searching.value = true;
    searchError.value = null;
    searched.value = false;
    staleSearch.value = false;
    waitlistDone.value = false;
    waitlistError.value = null;
    try {
        // Sin adults/children: el servidor cotiza con 1 de anclaje (nunca
        // dispara cargo por persona extra) — "desde", no el total real.
        const params: Record<string, unknown> = { mode: mode.value };
        if (mode.value === 'night') {
            params.arrive_date = arriveDate.value;
            params.depart_date = departDate.value;
        } else {
            params.arrive_at = arriveAt.value;
        }
        if (props.lockedRoomType) {
            params.room_type_id = props.lockedRoomType.id;
        }
        const { data } = await axios.get('/api/booking/availability', {
            params,
        });
        options.value = data.options;
        anyAvailable.value = data.any_available;
        searched.value = true;
        // Con la habitación fija no hay nada que elegir: si está libre se
        // pasa derecho a confirmarla.
        const onlyOption = props.lockedRoomType ? data.options[0] : null;
        if (onlyOption?.available) {
            chooseOption(onlyOption);
            return;
        }
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

// ── Detalle de una habitación (modal): galería, todo lo que incluye y de
// qué se compone el precio ──
const detailsOption = ref<Option | null>(null);

// La gráfica por noche solo dice algo si el precio cambia entre noches.
function nightlyVaries(option: Option | null): boolean {
    const n = option?.nightly ?? [];
    return n.length > 1 && new Set(n.map((x) => x.price)).size > 1;
}

// Descuento por semana/mes de la habitación elegida: el servidor ya lo
// cobra; aquí solo se hace visible (precio normal tachado + ahorro).
const selectedSavings = computed(() =>
    breakdownSavings(selected.value?.price_breakdown),
);
const selectedDiscount = computed(() => {
    const line = selected.value?.price_breakdown.find(
        (l) => l.regular_amount && l.regular_amount > l.amount,
    );
    if (!line || !selectedSavings.value) return null;
    return {
        name: line.concept.replace(/\s*\(\d+ noches?\)$/, ''),
        percent: breakdownPercent(selected.value?.price_breakdown),
        unit: line.unit_price ?? 0,
        regular: line.regular_unit_price ?? 0,
    };
});
const selectedOfferHint = computed(() =>
    selected.value?.available
        ? longStayHint(selected.value.long_stay_offers, props.property.currency)
        : null,
);

// ── Lista de espera: sin disponibilidad, dejar contacto para el aviso ──
const waitlistName = ref('');
const waitlistCountry = ref<'52' | '1' | 'otro'>('52');
const waitlistPhone = ref('');
const waitlistLada = ref('');
const waitlistEmail = ref('');
const waitlistSending = ref(false);
const waitlistDone = ref(false);
const waitlistError = ref<string | null>(null);
const waitlistAttempted = ref(false);

function searchedDateRange(): { starts_at: string; ends_at: string } {
    if (mode.value === 'night') {
        return { starts_at: arriveDate.value, ends_at: departDate.value };
    }
    const start = arriveAt.value.slice(0, 10);
    const next = new Date(`${start}T00:00`);
    next.setDate(next.getDate() + 1);
    return { starts_at: start, ends_at: localDateInput(next) };
}

const waitlistPhoneError = computed(() =>
    waitlistPhone.value.trim()
        ? phoneError(
              waitlistCountry.value,
              waitlistPhone.value,
              waitlistLada.value,
          )
        : null,
);

async function submitWaitlist() {
    waitlistAttempted.value = true;
    if (
        !waitlistName.value.trim() ||
        (!waitlistPhone.value.trim() && !waitlistEmail.value.trim()) ||
        waitlistPhoneError.value ||
        emailError(waitlistEmail.value)
    ) {
        return;
    }
    waitlistSending.value = true;
    waitlistError.value = null;
    try {
        await axios.post('/api/booking/waitlist', {
            guest_name: waitlistName.value,
            // Con lada, igual que la reserva: antes se mandaba el número
            // pelón y uno de USA se volvía mexicano.
            guest_phone: waitlistPhone.value.trim()
                ? internationalPhone(
                      waitlistCountry.value,
                      waitlistPhone.value,
                      waitlistLada.value,
                  )
                : null,
            guest_email: waitlistEmail.value || null,
            room_type_id: props.lockedRoomType?.id ?? null,
            ...searchedDateRange(),
        });
        waitlistDone.value = true;
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            | Record<string, string[]>
            | undefined;
        waitlistError.value =
            error.response?.data?.message ??
            (errors ? Object.values(errors)[0]?.[0] : null) ??
            'No se pudo registrar tu aviso. Intenta de nuevo.';
    } finally {
        waitlistSending.value = false;
    }
}

// ── Paso "Tu habitación": cuántos son, con tope real del cuarto ──
const selected = ref<Option | null>(null);

const grandTotal = computed(
    () =>
        (selected.value?.total ?? 0) +
        productsSubtotal.value +
        addonsSubtotal.value +
        experiencesSubtotal.value,
);

// ── Cupón: se valida contra el servidor para mostrar el descuento; la
// verdad se revalida al crear el apartado ──
const couponInput = ref('');
const couponApplying = ref(false);
const couponError = ref<string | null>(null);
const appliedCoupon = ref<{ code: string; discount: number } | null>(null);
const couponOpen = ref(false);

const discountedTotal = computed(() =>
    Math.max(0, grandTotal.value - (appliedCoupon.value?.discount ?? 0)),
);

async function applyCoupon() {
    const code = couponInput.value.trim().toUpperCase();
    if (!code) return;
    couponApplying.value = true;
    couponError.value = null;
    try {
        const { data } = await axios.post<{
            code: string;
            discount: number;
        }>('/api/booking/coupons/check', {
            code,
            subtotal: grandTotal.value,
            room_type_id: selected.value?.room_type_id ?? null,
            starts_at: selected.value?.starts_at ?? null,
            ends_at: selected.value?.ends_at ?? null,
            guest_phone: guestPhone.value.trim()
                ? guestPhoneForSubmit.value
                : null,
        });
        appliedCoupon.value = { code: data.code, discount: data.discount };
        couponOpen.value = false;
    } catch (error: any) {
        appliedCoupon.value = null;
        couponError.value =
            error.response?.data?.message ??
            'No se pudo validar el código. Intenta de nuevo.';
    } finally {
        couponApplying.value = false;
    }
}

function removeCoupon() {
    appliedCoupon.value = null;
    couponInput.value = '';
    couponError.value = null;
}

// Si el total cambia con un cupón puesto, el descuento porcentual se
// recalcula contra el servidor.
watch(grandTotal, () => {
    if (!appliedCoupon.value) return;
    couponInput.value = appliedCoupon.value.code;
    applyCoupon();
});

// ── Datos del huésped ──
const guestName = ref('');
const guestPhone = ref('');
const phoneCountry = ref<'52' | '1' | 'otro'>('52');
const phoneLada = ref('');
const guestPhoneForSubmit = computed(() =>
    internationalPhone(phoneCountry.value, guestPhone.value, phoneLada.value),
);
const guestEmail = ref('');
const notes = ref('');
const honeypot = ref(''); // campo trampa: invisible para personas
const renderedAt = ref('');
const guestAttempted = ref(false);
let idempotencyKey = randomUuid();

const nameError = computed(() =>
    guestName.value.trim().length >= 3 ? null : 'Escribe tu nombre completo.',
);
const guestPhoneError = computed(() =>
    phoneError(phoneCountry.value, guestPhone.value, phoneLada.value),
);
const guestEmailError = computed(() => emailError(guestEmail.value));
const guestValid = computed(
    () => !nameError.value && !guestPhoneError.value && !guestEmailError.value,
);

const submitting = ref(false);
const submitError = ref<string | null>(null);
const roomQuoteLoading = ref(false);
const roomQuoteError = ref<string | null>(null);

function chooseOption(option: Option) {
    selected.value = option;
    detailsOption.value = null;
    adults.value = 1;
    children.value = 0;
    guestAttempted.value = false;
    honeypot.value = '';
    renderedAt.value = new Date().toISOString();
    idempotencyKey = randomUuid();
    submitError.value = null;
    roomQuoteError.value = null;
    selectedProducts.value = {};
    selectedAddons.value = {};
    experiencePicks.value = [];
    appliedCoupon.value = null;
    couponInput.value = '';
    couponError.value = null;
    loadExperiences();
    step.value = 'room';
}

// Re-cotiza ESE tipo con el número de personas real: puede cambiar el
// total (persona extra), el cuarto ofrecido y si hay cupo.
async function refreshRoomQuote() {
    if (!selected.value) return;
    roomQuoteLoading.value = true;
    roomQuoteError.value = null;
    try {
        const params: Record<string, unknown> = {
            mode: mode.value,
            adults: adults.value,
            children: children.value,
            room_type_id: selected.value.room_type_id,
        };
        if (mode.value === 'night') {
            params.arrive_date = arriveDate.value;
            params.depart_date = departDate.value;
        } else {
            params.arrive_at = arriveAt.value;
        }
        const { data } = await axios.get('/api/booking/availability', {
            params,
        });
        if (data.options[0]) {
            selected.value = data.options[0];
        }
    } catch {
        roomQuoteError.value =
            'No se pudo actualizar el precio. Intenta de nuevo.';
    } finally {
        roomQuoteLoading.value = false;
    }
}

let roomQuoteTimer: number | null = null;
watch([adults, children], () => {
    if (step.value !== 'room') return;
    if (roomQuoteTimer) window.clearTimeout(roomQuoteTimer);
    roomQuoteTimer = window.setTimeout(refreshRoomQuote, 300);
});

// Tope real del cuarto elegido: ayuda de UX; quien de verdad lo blinda es
// CreateReservation en el servidor.
const maxGuests = computed(() => selected.value?.effective_capacity ?? 20);

const occupancyLabel = computed(() => {
    const a = `${adults.value} ${adults.value === 1 ? 'adulto' : 'adultos'}`;
    const c = children.value
        ? ` + ${children.value} ${children.value === 1 ? 'niño' : 'niños'}`
        : '';
    return `${a}${c}`;
});

function continueFromRoom() {
    if (!selected.value?.available || roomQuoteLoading.value) return;
    step.value = stepOrder.value.includes('extras') ? 'extras' : 'guest';
}

// Volver un paso: "room"→"dates" sí suelta la habitación elegida, el resto
// solo retrocede sin perder nada.
function goBack() {
    const idx = stepOrder.value.indexOf(step.value);
    if (idx <= 0) return;
    const previous = stepOrder.value[idx - 1];
    if (previous === 'dates') selected.value = null;
    step.value = previous;
}

const hold = ref<HoldResult | null>(null);
const paymentPanel = ref<InstanceType<typeof PaymentPanel> | null>(null);
// Eligió pagar en el hotel (o el hotel no cobra por adelantado).
const payLater = ref(false);

async function submitHold() {
    guestAttempted.value = true;
    if (!selected.value || !guestValid.value) return;
    submitting.value = true;
    submitError.value = null;
    try {
        const payload: Record<string, unknown> = {
            mode: mode.value,
            room_type_id: selected.value.room_type_id,
            room_id: selected.value.room_id,
            adults: adults.value,
            children: children.value,
            guest_name: guestName.value.trim(),
            guest_phone: guestPhoneForSubmit.value,
            guest_email: guestEmail.value.trim() || null,
            notes: notes.value || null,
            website: honeypot.value,
            rendered_at: renderedAt.value,
            products: Object.entries(selectedProducts.value)
                .filter(([, qty]) => qty > 0)
                .map(([productId, qty]) => ({
                    product_id: Number(productId),
                    qty,
                })),
            extras: Object.entries(selectedAddons.value)
                .filter(([, qty]) => qty > 0)
                .map(([extraId, qty]) => ({ extra_id: Number(extraId), qty })),
            experiences: experiencePicks.value.map((pick) => ({
                session_id: pick.session_id,
                people: pick.people,
            })),
            coupon_code: appliedCoupon.value?.code ?? null,
        };
        if (mode.value === 'night') {
            payload.arrive_date = arriveDate.value;
            payload.depart_date = departDate.value;
        } else {
            payload.arrive_at = arriveAt.value;
        }

        const { data } = await axios.post<HoldResult>(
            '/api/booking/holds',
            payload,
            { headers: { 'Idempotency-Key': idempotencyKey } },
        );
        openHold(data);
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            | Record<string, string[]>
            | undefined;
        submitError.value =
            error.response?.data?.message ??
            (errors ? Object.values(errors)[0]?.[0] : null) ??
            'No se pudo crear tu apartado. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

async function openHold(data: HoldResult) {
    hold.value = data;
    payLater.value = false;
    step.value = 'confirm';
    if (data.requires_prepayment) {
        await nextTick();
        paymentPanel.value?.start();
    }
}

function onPayLater(expiresAt: string | null) {
    payLater.value = true;
    if (hold.value && expiresAt) hold.value.hold_expires_at = expiresAt;
}

// ── Retomar el pago al volver de la pasarela sin pagar (?retomar=uuid) ──
onMounted(async () => {
    const uuid = new URLSearchParams(window.location.search).get('retomar');
    if (!uuid) return;
    try {
        const { data } = await axios.get<
            HoldResult & { mode: 'block' | 'night' }
        >(`/api/booking/resume/${encodeURIComponent(uuid)}`);
        mode.value = data.mode;
        openHold(data);
    } catch (error: any) {
        searchError.value =
            error.response?.data?.message ??
            'No pudimos retomar tu apartado. Puedes hacer una reserva nueva.';
    }
});

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
    // Con horas de margen se lee mejor "2 h 59 min"; el minutero corre
    // solo en la última hora.
    if (m >= 60) return `${Math.floor(m / 60)} h ${m % 60} min`;
    const s = Math.floor((diff % 60000) / 1000);
    return `${m}:${String(s).padStart(2, '0')}`;
});

function stayLabel(startIso: string, endIso: string): string {
    if (mode.value === 'night') return formatStayRange(startIso, endIso);
    return `Llegas ${formatFriendlyDateTime(startIso)} · sales ${formatFriendlyDateTime(endIso)}`;
}

// Reserva nueva: misma página sin el apartado retomado (conserva el embed
// y la habitación fija, si los hay).
function startOver() {
    const params = new URLSearchParams(window.location.search);
    params.delete('retomar');
    const query = params.toString();
    window.location.href =
        window.location.pathname + (query ? `?${query}` : '');
}

const codeCopied = ref(false);
async function copyCode() {
    if (!hold.value) return;
    try {
        await navigator.clipboard.writeText(hold.value.code);
        codeCopied.value = true;
        window.setTimeout(() => (codeCopied.value = false), 2000);
    } catch {
        // El código ya está bien visible en pantalla si falla.
    }
}

function addToCalendar() {
    if (!hold.value) return;
    downloadIcs({
        uid: hold.value.code,
        title: `${hold.value.room_type} · ${props.property.name}`,
        start: hold.value.starts_at,
        end: hold.value.ends_at,
        description: `Código de reserva: ${hold.value.code}`,
        location: props.property.name,
    });
}

// ── Resumen "Tu reserva": vivo antes de apartar, el del servidor después ──
const summaryOpen = ref(false);

const summary = computed(() => {
    if (hold.value) {
        const h = hold.value;
        const lines: SummaryLine[] = [
            ...(h.price_breakdown.length
                ? h.price_breakdown.map((l, i) => ({
                      id: `b-${i}`,
                      label: breakdownLabel(l),
                      detail: breakdownDetail(l, props.property.currency),
                      amount: l.amount,
                      regularAmount: l.regular_amount ?? null,
                  }))
                : [{ id: 'room', label: 'Habitación', amount: h.room_total }]),
            ...(h.experiences ?? []).map((l, i) => ({
                id: `e-${i}`,
                label: `${l.people}× ${l.name}`,
                detail: formatFriendlyDateTime(l.starts_at),
                amount: l.total,
            })),
            ...h.extras.map((l) => ({
                id: `a-${l.extra_id}`,
                label: `${l.qty}× ${l.name}`,
                amount: l.total,
            })),
            ...h.products.map((l) => ({
                id: `p-${l.product_id}`,
                label: `${l.qty}× ${l.name}`,
                amount: l.total,
            })),
        ];
        const parts: DonutPart[] = [
            { key: 'room', label: 'Hospedaje', amount: h.room_total },
            {
                key: 'extras',
                label: 'Complementos',
                amount: h.products_total + h.extras_total,
            },
            {
                key: 'experiences',
                label: 'Experiencias',
                amount: h.experiences_total,
            },
        ];
        return {
            photo: h.room_photo ?? selected.value?.photos[0]?.thumb_url ?? null,
            name: h.room_type,
            stay: stayLabel(h.starts_at, h.ends_at),
            occupancy: selected.value ? occupancyLabel.value : null,
            lines,
            discount:
                h.discount > 0 && h.coupon_code
                    ? { code: h.coupon_code, amount: h.discount }
                    : null,
            total: h.total,
            parts,
        };
    }
    if (!selected.value) return null;
    const s = selected.value;
    const lines: SummaryLine[] = [
        ...(s.price_breakdown.length
            ? s.price_breakdown.map((l, i) => ({
                  id: `b-${i}`,
                  label: breakdownLabel(l),
                  detail: breakdownDetail(l, props.property.currency),
                  amount: l.amount,
                  regularAmount: l.regular_amount ?? null,
              }))
            : [{ id: 'room', label: 'Habitación', amount: s.total }]),
        ...experienceLines.value.map((l) => ({
            id: l.id,
            label: `${l.people}× ${l.name}`,
            detail: formatFriendlyDateTime(l.starts_at),
            amount: l.total,
            removable: step.value !== 'confirm',
        })),
        ...selectedProductLines.value.map((l) => ({
            id: l.id,
            label: `${l.qty}× ${l.name}`,
            amount: l.total,
            removable: step.value !== 'confirm',
        })),
    ];
    return {
        photo: s.photos[0]?.thumb_url ?? null,
        name: s.name,
        stay: stayLabel(s.starts_at, s.ends_at),
        occupancy: occupancyLabel.value,
        lines,
        discount: appliedCoupon.value
            ? {
                  code: appliedCoupon.value.code,
                  amount: appliedCoupon.value.discount,
              }
            : null,
        total: discountedTotal.value,
        parts: [
            { key: 'room', label: 'Hospedaje', amount: s.total },
            {
                key: 'extras',
                label: 'Complementos',
                amount: productsSubtotal.value + addonsSubtotal.value,
            },
            {
                key: 'experiences',
                label: 'Experiencias',
                amount: experiencesSubtotal.value,
            },
        ] as DonutPart[],
    };
});

// Quitar una línea desde el resumen (experiencias y extras).
function removeSummaryLine(id: string) {
    if (id.startsWith('s-')) removeExperiencePick(Number(id.slice(2)));
    if (id.startsWith('a-')) setAddonQty(Number(id.slice(2)), 0);
    if (id.startsWith('p-')) setProductQty(Number(id.slice(2)), 0);
}

const sectionBox = 'rounded-xl border border-slate-200';
const sectionHead =
    'flex items-center gap-3 border-b border-slate-200 px-4 py-3';
const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary';
const groupLabel =
    'mb-2 text-xs font-medium tracking-wide text-slate-500 uppercase';
const primaryBtn = 'h-11 rounded-full px-6 text-sm shadow-md shadow-primary/20';
</script>

<template>
    <PublicShell
        title="Reservar"
        :appearance="appearance"
        :property="property"
        :contact="contact"
        :steps="shellSteps"
        :current-step="step"
        :tagline="appearance.texts?.tagline"
    >
        <div ref="stepTop" class="scroll-mt-4" />

        <!-- ═══ PASO 1: cuándo ═══ -->
        <div v-if="step === 'dates'" class="space-y-5">
            <BookingCard
                icon="CalendarDays"
                :title="
                    lockedRoomType
                        ? `Reserva ${lockedRoomType.name}`
                        : (appearance.texts?.title ?? '¿Cuándo nos visitas?')
                "
                :subtitle="
                    lockedRoomType
                        ? 'Elige tus fechas y revisamos al momento si está libre, con su precio real.'
                        : (appearance.texts?.subtitle ??
                          'Elige tus fechas y te mostramos lo disponible al momento, con su precio real.')
                "
            >
                <div
                    v-if="adultsOnly"
                    class="mb-4 flex items-center gap-2 rounded-lg bg-warning/10 px-3 py-2 text-sm text-slate-700"
                >
                    <Lucide
                        icon="ShieldAlert"
                        class="h-4 w-4 shrink-0 text-warning"
                    />
                    Establecimiento exclusivo para mayores de edad.
                </div>

                <!-- Solo si el catálogo de verdad vende en ambas modalidades -->
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

                <!-- Por noche: calendario y, al lado, cómo queda la estancia -->
                <div
                    v-if="mode === 'night'"
                    class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_250px]"
                >
                    <div :class="sectionBox" class="p-3 sm:p-4">
                        <p class="mb-2 px-1 text-sm text-slate-500">
                            Toca el día que llegas y luego el día que te vas.
                        </p>
                        <DateRangePicker
                            v-model:start="arriveDate"
                            v-model:end="departDate"
                        />
                    </div>
                    <div class="flex flex-col gap-3">
                        <div class="rounded-xl bg-slate-50 p-4">
                            <div
                                class="text-xs font-medium tracking-wide text-slate-500 uppercase"
                            >
                                Tu estancia
                            </div>
                            <div class="mt-3 space-y-3">
                                <div class="flex items-start gap-2.5">
                                    <Lucide
                                        icon="LogIn"
                                        class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                                    />
                                    <div>
                                        <div class="text-xs text-slate-500">
                                            Llegas
                                        </div>
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            {{ formatLongDay(arriveDate) }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            desde las
                                            {{
                                                formatTime(
                                                    property.check_in_time,
                                                )
                                            }}
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
                                            Te vas
                                        </div>
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            {{ formatLongDay(departDate) }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            antes de las
                                            {{
                                                formatTime(
                                                    property.check_out_time,
                                                )
                                            }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div
                                class="mt-3 flex items-center gap-2 border-t border-slate-200 pt-3 text-sm text-slate-700"
                            >
                                <Lucide
                                    icon="Moon"
                                    class="h-4 w-4 text-primary"
                                />
                                {{ searchedNights }}
                                {{ searchedNights === 1 ? 'noche' : 'noches' }}
                            </div>
                        </div>
                        <p
                            class="flex items-start gap-2 px-1 text-xs text-slate-500"
                        >
                            <Lucide
                                icon="Users"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            Cuántos son lo indicas al elegir habitación.
                        </p>
                    </div>
                </div>

                <!-- Por horas: la hora exacta manda -->
                <div v-else class="max-w-md">
                    <FormLabel for="arrive-at">Día y hora de llegada</FormLabel>
                    <FormDateTime
                        id="arrive-at"
                        v-model="arriveAt"
                        input-class="h-11 text-sm"
                    />
                    <p v-if="blockTimeError" class="mt-1.5 text-xs text-danger">
                        {{ blockTimeError }}
                    </p>
                    <p v-else class="mt-1.5 text-xs text-slate-500">
                        Cuántos son lo indicas al elegir habitación.
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
                        Cambiaste las fechas: busca de nuevo para ver precios
                        reales.
                    </p>
                    <Button
                        variant="primary"
                        class="w-full sm:ml-auto sm:w-auto"
                        :class="primaryBtn"
                        :disabled="searching || !!blockTimeError"
                        @click="searchAvailability"
                    >
                        <Lucide
                            :icon="searching ? 'LoaderCircle' : 'Search'"
                            class="mr-2 h-4 w-4"
                            :class="searching && 'animate-spin'"
                        />
                        {{
                            searching
                                ? 'Buscando…'
                                : lockedRoomType
                                  ? 'Ver si está libre'
                                  : (appearance.texts?.search_button ??
                                    'Ver habitaciones disponibles')
                        }}
                    </Button>
                </template>
            </BookingCard>

            <!-- Resultados -->
            <div v-if="searched" ref="resultsEl" class="scroll-mt-4">
                <BookingCard
                    v-if="anyAvailable"
                    icon="BedDouble"
                    :title="
                        availableCount === 1
                            ? '1 habitación disponible'
                            : `${availableCount} habitaciones disponibles`
                    "
                    :subtitle="
                        mode === 'night'
                            ? formatStayRange(arriveDate, departDate)
                            : `Llegas ${formatFriendlyDateTime(arriveAt)}`
                    "
                >
                    <template v-if="options.length > 1" #actions>
                        <FormSelect
                            v-model="sortBy"
                            class="h-10 w-full text-sm sm:w-48"
                            aria-label="Ordenar habitaciones"
                        >
                            <option value="recommended">Recomendadas</option>
                            <option value="price_asc">Menor precio</option>
                            <option value="price_desc">Mayor precio</option>
                        </FormSelect>
                    </template>

                    <div
                        v-if="hasGroups"
                        class="mb-4 flex items-start gap-2.5 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600"
                    >
                        <Lucide
                            icon="UsersRound"
                            class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                        />
                        <span>
                            ¿Vienen en grupo y necesitan varias habitaciones?
                            <a
                                href="/reservar/grupos"
                                class="font-medium text-primary hover:underline"
                                >Aparta todas de una vez</a
                            >.
                        </span>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <RoomOptionCard
                            v-for="option in sortedOptions"
                            :key="option.room_type_id"
                            :option="option"
                            :currency="property.currency"
                            :nights="mode === 'night' ? searchedNights : null"
                            @choose="chooseOption(option)"
                            @details="detailsOption = option"
                        />
                    </div>
                </BookingCard>

                <!-- Sin disponibilidad -->
                <BookingCard
                    v-else
                    icon="CalendarX2"
                    tone="warning"
                    :title="
                        lockedRoomType
                            ? `${lockedRoomType.name} no está disponible en esas fechas`
                            : 'Sin disponibilidad para esas fechas'
                    "
                    subtitle="Prueba con otras fechas, o escríbenos y te ayudamos a encontrar una opción."
                >
                    <div
                        class="grid gap-5"
                        :class="hasWaitlist && 'md:grid-cols-[1fr_1.4fr]'"
                    >
                        <div class="space-y-2.5">
                            <a
                                href="/chat"
                                class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-primary/40"
                            >
                                <span :class="sectionIcon">
                                    <Lucide
                                        icon="MessageCircle"
                                        class="h-4 w-4"
                                    />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-sm font-medium text-slate-800"
                                        >Escríbenos</span
                                    >
                                    <span class="block text-xs text-slate-500"
                                        >Te ayudamos a encontrar fecha</span
                                    >
                                </span>
                            </a>
                            <a
                                v-if="property.phone"
                                :href="`tel:${property.phone}`"
                                class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 transition hover:border-primary/40"
                            >
                                <span :class="sectionIcon">
                                    <Lucide icon="Phone" class="h-4 w-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-sm font-medium text-slate-800"
                                        >Llámanos</span
                                    >
                                    <span
                                        class="block text-xs text-slate-500"
                                        >{{ property.phone }}</span
                                    >
                                </span>
                            </a>
                        </div>

                        <!-- Lista de espera: dejar contacto para el aviso -->
                        <div v-if="hasWaitlist" :class="sectionBox">
                            <div :class="sectionHead">
                                <span :class="sectionIcon">
                                    <Lucide icon="BellRing" class="h-4 w-4" />
                                </span>
                                <div class="min-w-0">
                                    <div
                                        class="text-sm font-medium text-slate-800"
                                    >
                                        Avísame si se libera
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        Te escribimos en cuanto haya espacio
                                        para tus fechas.
                                    </div>
                                </div>
                            </div>
                            <div class="p-4">
                                <div
                                    v-if="waitlistDone"
                                    class="flex items-start gap-2 rounded-lg bg-success/10 px-3 py-2.5 text-sm text-success"
                                >
                                    <Lucide
                                        icon="BellRing"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                    />
                                    Listo, te avisaremos si se libera espacio
                                    para tus fechas.
                                </div>
                                <div v-else class="space-y-3">
                                    <div>
                                        <FormLabel for="wl-name"
                                            >Nombre</FormLabel
                                        >
                                        <FormInput
                                            id="wl-name"
                                            v-model="waitlistName"
                                            type="text"
                                            class="h-11 text-sm"
                                            placeholder="Tu nombre"
                                        />
                                        <p
                                            v-if="
                                                waitlistAttempted &&
                                                !waitlistName.trim()
                                            "
                                            class="mt-1 text-xs text-danger"
                                        >
                                            Escribe tu nombre.
                                        </p>
                                    </div>
                                    <div>
                                        <FormLabel for="wl-phone"
                                            >Teléfono</FormLabel
                                        >
                                        <PhoneField
                                            id="wl-phone"
                                            v-model:country="waitlistCountry"
                                            v-model:number="waitlistPhone"
                                            v-model:lada="waitlistLada"
                                            :show-errors="
                                                waitlistAttempted &&
                                                !!waitlistPhone.trim()
                                            "
                                        />
                                    </div>
                                    <div>
                                        <FormLabel for="wl-email"
                                            >Correo</FormLabel
                                        >
                                        <FormInput
                                            id="wl-email"
                                            v-model="waitlistEmail"
                                            type="email"
                                            class="h-11 text-sm"
                                            placeholder="tu@correo.com"
                                        />
                                        <p
                                            v-if="
                                                waitlistAttempted &&
                                                emailError(waitlistEmail)
                                            "
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ emailError(waitlistEmail) }}
                                        </p>
                                        <p
                                            v-else-if="
                                                waitlistAttempted &&
                                                !waitlistPhone.trim() &&
                                                !waitlistEmail.trim()
                                            "
                                            class="mt-1 text-xs text-danger"
                                        >
                                            Déjanos un teléfono o un correo.
                                        </p>
                                    </div>
                                    <p
                                        v-if="waitlistError"
                                        class="text-sm text-danger"
                                    >
                                        {{ waitlistError }}
                                    </p>
                                    <Button
                                        variant="outline-primary"
                                        class="h-11 w-full rounded-full text-sm"
                                        :disabled="waitlistSending"
                                        @click="submitWaitlist"
                                    >
                                        <Lucide
                                            :icon="
                                                waitlistSending
                                                    ? 'LoaderCircle'
                                                    : 'BellRing'
                                            "
                                            class="mr-2 h-4 w-4"
                                            :class="
                                                waitlistSending &&
                                                'animate-spin'
                                            "
                                        />
                                        {{
                                            waitlistSending
                                                ? 'Registrando…'
                                                : 'Avisarme si se libera'
                                        }}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </BookingCard>
            </div>
        </div>

        <!-- ═══ PASOS 2+: contenido a la izquierda, "Tu reserva" al lado ═══ -->
        <div
            v-else
            class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_330px]"
        >
            <div class="min-w-0 space-y-4">
                <!-- Celular: el resumen se abre en una ventana -->
                <button
                    v-if="summary && step !== 'confirm'"
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

                <!-- ═══ PASO: tu habitación (cuántos son) ═══ -->
                <BookingCard
                    v-if="step === 'room' && selected"
                    icon="BedDouble"
                    :title="selected.name"
                    :subtitle="stayLabel(selected.starts_at, selected.ends_at)"
                >
                    <PhotoGallery
                        :photos="selected.photos"
                        :alt="selected.name"
                    />

                    <p
                        v-if="selected.description"
                        class="mt-4 text-sm leading-relaxed text-slate-600"
                    >
                        {{ selected.description }}
                    </p>
                    <div
                        v-if="selected.amenities.length"
                        class="mt-3 flex flex-wrap gap-1.5"
                    >
                        <span
                            v-for="a in selected.amenities"
                            :key="a"
                            class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600"
                            >{{ a }}</span
                        >
                    </div>

                    <div :class="sectionBox" class="mt-5">
                        <div :class="sectionHead">
                            <span :class="sectionIcon">
                                <Lucide icon="Users" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-slate-800">
                                    ¿Cuántos son?
                                </div>
                                <div class="text-xs text-slate-500">
                                    Esta habitación admite hasta {{ maxGuests }}
                                    {{
                                        maxGuests === 1 ? 'persona' : 'personas'
                                    }}
                                    en total.
                                </div>
                            </div>
                        </div>
                        <div class="divide-y divide-slate-200">
                            <div
                                class="flex items-center justify-between gap-3 px-4 py-3"
                            >
                                <div class="text-sm font-medium text-slate-700">
                                    Adultos
                                </div>
                                <QtyStepper
                                    v-model="adults"
                                    :min="1"
                                    :max="maxGuests - children"
                                    label="adulto"
                                />
                            </div>
                            <div
                                v-if="!adultsOnly"
                                class="flex items-center justify-between gap-3 px-4 py-3"
                            >
                                <div class="text-sm font-medium text-slate-700">
                                    Niños
                                </div>
                                <QtyStepper
                                    v-model="children"
                                    :min="0"
                                    :max="maxGuests - adults"
                                    label="niño"
                                />
                            </div>
                        </div>
                        <p
                            v-if="adultsOnly"
                            class="flex items-center gap-1.5 border-t border-slate-200 px-4 py-2.5 text-xs font-medium text-warning"
                        >
                            <Lucide icon="ShieldAlert" class="h-3.5 w-3.5" />
                            Establecimiento exclusivo para mayores de edad.
                        </p>
                    </div>

                    <!-- Precio: barras por noche cuando cambia entre noches -->
                    <div
                        v-if="nightlyVaries(selected)"
                        :class="sectionBox"
                        class="mt-4 p-4"
                    >
                        <NightlyBars
                            :nights="selected.nightly ?? []"
                            :currency="property.currency"
                        />
                    </div>

                    <div
                        v-if="selectedDiscount && !roomQuoteLoading"
                        class="mt-4 flex items-start gap-2.5 rounded-xl bg-success/10 px-4 py-3 text-sm text-slate-700"
                    >
                        <Lucide
                            icon="BadgePercent"
                            class="mt-0.5 h-4 w-4 shrink-0 text-success"
                        />
                        <div class="min-w-0">
                            <div class="font-medium text-slate-800">
                                {{ selectedDiscount.name }} aplicado
                            </div>
                            <div class="text-xs text-slate-600">
                                La noche queda en
                                {{ money(selectedDiscount.unit) }} en vez de
                                {{ money(selectedDiscount.regular) }}: ahorras
                                {{ money(selectedSavings) }} ({{
                                    selectedDiscount.percent
                                }}% menos).
                            </div>
                        </div>
                    </div>
                    <p
                        v-else-if="selectedOfferHint && !roomQuoteLoading"
                        class="mt-4 flex items-start gap-2 text-xs text-success"
                    >
                        <Lucide
                            icon="BadgePercent"
                            class="mt-px h-3.5 w-3.5 shrink-0"
                        />
                        {{ selectedOfferHint }}
                    </p>

                    <div
                        v-if="roomQuoteLoading"
                        class="mt-4 flex items-center gap-2 text-sm text-slate-500"
                    >
                        <Lucide
                            icon="LoaderCircle"
                            class="h-4 w-4 animate-spin"
                        />
                        Recalculando el precio…
                    </div>
                    <div
                        v-else-if="!selected.available"
                        class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                        />
                        No hay una habitación de este tipo libre para
                        {{ adults + children }}
                        {{ adults + children === 1 ? 'persona' : 'personas' }}
                        en esas fechas. Prueba con menos personas u otra
                        habitación.
                    </div>
                    <p v-if="roomQuoteError" class="mt-3 text-sm text-danger">
                        {{ roomQuoteError }}
                    </p>

                    <template #footer>
                        <BackButton
                            label="Otras habitaciones"
                            @click="goBack"
                        />
                        <div
                            class="ml-auto flex flex-1 items-center justify-end gap-3 sm:flex-none"
                        >
                            <span class="hidden text-right sm:block">
                                <span class="block text-xs text-slate-500"
                                    >Total<template v-if="selectedSavings > 0">
                                        ·
                                        <span class="line-through">{{
                                            money(
                                                selected.total +
                                                    selectedSavings,
                                            )
                                        }}</span></template
                                    ></span
                                >
                                <span
                                    class="block text-base font-semibold text-slate-800"
                                    >{{ money(selected.total) }}</span
                                >
                            </span>
                            <Button
                                variant="primary"
                                class="flex-1 sm:flex-none"
                                :class="primaryBtn"
                                :disabled="
                                    roomQuoteLoading || !selected.available
                                "
                                @click="continueFromRoom"
                            >
                                Continuar
                                <Lucide
                                    icon="ArrowRight"
                                    class="ml-2 h-4 w-4"
                                />
                            </Button>
                        </div>
                    </template>
                </BookingCard>

                <!-- ═══ PASO: complementos (opcional) ═══ -->
                <BookingCard
                    v-else-if="step === 'extras' && selected"
                    icon="ConciergeBell"
                    title="¿Algo más para tu llegada?"
                    subtitle="Opcional: se prepara para ti y se suma a tu total."
                >
                    <div class="space-y-6">
                        <!-- Experiencias durante la estancia -->
                        <section
                            v-if="
                                experiencesCatalog?.enabled &&
                                experiencesCatalog.experiences.length
                            "
                        >
                            <div :class="groupLabel">
                                Recorridos y experiencias
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
                                        <div
                                            v-else
                                            class="flex h-16 w-20 shrink-0 items-center justify-center rounded-lg bg-slate-100"
                                        >
                                            <Lucide
                                                icon="Compass"
                                                class="h-6 w-6 text-slate-300"
                                            />
                                        </div>
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
                                            <p
                                                v-if="exp.description"
                                                class="mt-1 line-clamp-2 text-xs text-slate-500"
                                            >
                                                {{ exp.description }}
                                            </p>
                                        </div>
                                    </div>
                                    <div
                                        class="mt-3 grid grid-cols-1 items-end gap-3 sm:grid-cols-[1fr_auto_auto]"
                                    >
                                        <div>
                                            <FormLabel
                                                class="text-xs"
                                                :for="`exp-${exp.id}`"
                                                >Fecha y horario</FormLabel
                                            >
                                            <FormSelect
                                                :id="`exp-${exp.id}`"
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

                        <!-- Add-ons: decoración, desayuno, late checkout -->
                        <section v-if="extrasCatalog?.addons.length">
                            <div :class="groupLabel">Para tu estancia</div>
                            <div
                                :class="sectionBox"
                                class="divide-y divide-slate-200"
                            >
                                <div
                                    v-for="addon in extrasCatalog.addons"
                                    :key="addon.id"
                                    class="flex flex-wrap items-center gap-3 p-4"
                                >
                                    <span
                                        :class="sectionIcon"
                                        class="h-11 w-11"
                                    >
                                        <Lucide
                                            icon="Sparkles"
                                            class="h-5 w-5"
                                        />
                                    </span>
                                    <div class="min-w-0 flex-1 basis-40">
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            {{ addon.name }}
                                        </div>
                                        <div
                                            v-if="addon.description"
                                            class="text-xs text-slate-500"
                                        >
                                            {{ addon.description }}
                                        </div>
                                        <div class="text-sm text-slate-600">
                                            {{ money(addon.price) }}
                                        </div>
                                    </div>
                                    <QtyStepper
                                        :model-value="
                                            selectedAddons[addon.id] ?? 0
                                        "
                                        :max="20"
                                        :label="addon.name"
                                        class="ml-auto"
                                        @update:model-value="
                                            setAddonQty(addon.id, $event)
                                        "
                                    />
                                </div>
                            </div>
                        </section>

                        <!-- Productos del POS por categoría -->
                        <section
                            v-for="[category, items] in extrasByCategory"
                            :key="category"
                        >
                            <div :class="groupLabel">{{ category }}</div>
                            <div
                                :class="sectionBox"
                                class="divide-y divide-slate-200"
                            >
                                <div
                                    v-for="p in items"
                                    :key="p.id"
                                    class="flex flex-wrap items-center gap-3 p-4"
                                >
                                    <img
                                        v-if="p.photo"
                                        :src="p.photo.thumb_url"
                                        :alt="p.name"
                                        class="h-14 w-16 shrink-0 rounded-lg object-cover"
                                        loading="lazy"
                                    />
                                    <div
                                        v-else
                                        class="flex h-14 w-16 shrink-0 items-center justify-center rounded-lg bg-slate-100"
                                    >
                                        <Lucide
                                            icon="ShoppingBag"
                                            class="h-5 w-5 text-slate-300"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1 basis-40">
                                        <div
                                            class="text-sm font-medium text-slate-800"
                                        >
                                            {{ p.name }}
                                        </div>
                                        <div class="text-sm text-slate-600">
                                            {{ money(p.price) }} / {{ p.unit }}
                                        </div>
                                    </div>
                                    <QtyStepper
                                        :model-value="
                                            selectedProducts[p.id] ?? 0
                                        "
                                        :max="20"
                                        :label="p.name"
                                        class="ml-auto"
                                        @update:model-value="
                                            setProductQty(p.id, $event)
                                        "
                                    />
                                </div>
                            </div>
                        </section>
                    </div>

                    <template #footer>
                        <BackButton @click="goBack" />
                        <Button
                            variant="primary"
                            class="ml-auto flex-1 sm:flex-none"
                            :class="primaryBtn"
                            @click="step = 'guest'"
                        >
                            {{
                                selectedProductLines.length ||
                                experienceLines.length
                                    ? 'Continuar'
                                    : 'Continuar sin complementos'
                            }}
                            <Lucide icon="ArrowRight" class="ml-2 h-4 w-4" />
                        </Button>
                    </template>
                </BookingCard>

                <!-- ═══ PASO: tus datos ═══ -->
                <BookingCard
                    v-else-if="step === 'guest' && selected"
                    icon="UserRound"
                    title="Tus datos"
                    subtitle="Para confirmarte la reserva y avisarte cualquier cambio."
                >
                    <form
                        class="space-y-5"
                        novalidate
                        @submit.prevent="submitHold"
                    >
                        <div>
                            <FormLabel for="guest-name"
                                >Nombre completo</FormLabel
                            >
                            <FormInput
                                id="guest-name"
                                v-model="guestName"
                                type="text"
                                autocomplete="name"
                                class="h-11 text-sm"
                                :class="
                                    guestAttempted && nameError
                                        ? 'border-danger'
                                        : ''
                                "
                                placeholder="Como aparece en tu identificación"
                            />
                            <p
                                v-if="guestAttempted && nameError"
                                class="mt-1.5 text-xs text-danger"
                            >
                                {{ nameError }}
                            </p>
                            <p v-else class="mt-1.5 text-xs text-slate-500">
                                Al llegar te pediremos una identificación
                                oficial.
                            </p>
                        </div>
                        <!-- Un dato por renglón: a dos columnas el teléfono
                             (país + número) se partía y quedaba desalineado
                             frente al correo. -->
                        <div class="grid gap-5">
                            <div>
                                <FormLabel for="guest-phone"
                                    >Teléfono (WhatsApp)</FormLabel
                                >
                                <PhoneField
                                    id="guest-phone"
                                    v-model:country="phoneCountry"
                                    v-model:number="guestPhone"
                                    v-model:lada="phoneLada"
                                    :show-errors="guestAttempted"
                                    hint="Aquí te confirmamos por WhatsApp."
                                />
                            </div>
                            <div>
                                <FormLabel for="guest-email">Correo</FormLabel>
                                <FormInput
                                    id="guest-email"
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
                                <p v-else class="mt-1.5 text-xs text-slate-500">
                                    Te mandamos ahí tu confirmación.
                                </p>
                            </div>
                        </div>
                        <div>
                            <FormLabel for="guest-notes"
                                >Algo que debamos saber</FormLabel
                            >
                            <FormTextarea
                                id="guest-notes"
                                v-model="notes"
                                rows="2"
                                class="text-sm"
                                placeholder="Hora aproximada de llegada, alguna petición…"
                            />
                        </div>

                        <!-- Cupón -->
                        <div
                            v-if="hasCoupons"
                            class="rounded-xl bg-slate-50 p-4"
                        >
                            <div
                                v-if="appliedCoupon"
                                class="flex items-center gap-2 text-sm text-success"
                            >
                                <Lucide icon="TicketPercent" class="h-4 w-4" />
                                Cupón {{ appliedCoupon.code }} aplicado: −{{
                                    money(appliedCoupon.discount)
                                }}
                                <button
                                    type="button"
                                    class="ml-auto text-xs font-medium text-slate-500 hover:text-danger"
                                    @click="removeCoupon"
                                >
                                    Quitar
                                </button>
                            </div>
                            <template v-else>
                                <button
                                    v-if="!couponOpen"
                                    type="button"
                                    class="flex items-center gap-2 text-sm font-medium text-primary"
                                    @click="couponOpen = true"
                                >
                                    <Lucide
                                        icon="TicketPercent"
                                        class="h-4 w-4"
                                    />
                                    ¿Tienes un código de descuento?
                                </button>
                                <div v-else>
                                    <FormLabel for="coupon"
                                        >Código de descuento</FormLabel
                                    >
                                    <div class="flex gap-2">
                                        <FormInput
                                            id="coupon"
                                            v-model="couponInput"
                                            type="text"
                                            autocapitalize="characters"
                                            class="h-11 flex-1 text-sm uppercase"
                                            placeholder="CODIGO"
                                            @keydown.enter.prevent="applyCoupon"
                                        />
                                        <Button
                                            variant="outline-primary"
                                            class="h-11 rounded-full px-5 text-sm"
                                            :disabled="
                                                couponApplying ||
                                                !couponInput.trim()
                                            "
                                            @click="applyCoupon"
                                        >
                                            {{
                                                couponApplying
                                                    ? 'Validando…'
                                                    : 'Aplicar'
                                            }}
                                        </Button>
                                    </div>
                                    <p
                                        v-if="couponError"
                                        class="mt-1.5 text-xs text-danger"
                                    >
                                        {{ couponError }}
                                    </p>
                                </div>
                            </template>
                        </div>

                        <!-- Campo trampa: invisible para personas -->
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

                    <template #footer>
                        <BackButton @click="goBack" />
                        <div
                            class="ml-auto flex flex-1 justify-end sm:flex-none"
                        >
                            <Button
                                variant="primary"
                                class="flex-1 sm:flex-none"
                                :class="primaryBtn"
                                :disabled="submitting"
                                @click="submitHold"
                            >
                                <Lucide
                                    :icon="
                                        submitting
                                            ? 'LoaderCircle'
                                            : 'ShieldCheck'
                                    "
                                    class="mr-2 h-4 w-4"
                                    :class="submitting && 'animate-spin'"
                                />
                                {{
                                    submitting
                                        ? 'Apartando…'
                                        : 'Apartar mi habitación'
                                }}
                            </Button>
                        </div>
                        <p
                            class="w-full text-center text-xs text-slate-500 sm:text-right"
                        >
                            Queda apartada {{ humanizeMinutes(holdMinutes) }}
                            mientras terminas. Sin tarjeta y sin compromiso.
                        </p>
                    </template>
                </BookingCard>

                <!-- ═══ PASO: listo (confirmación o pago) ═══ -->
                <template v-else-if="step === 'confirm' && hold">
                    <!-- Sin prepago o eligió pagar en el hotel -->
                    <BookingCard
                        v-if="!hold.requires_prepayment || payLater"
                        icon="CircleCheck"
                        tone="success"
                        title="¡Tu apartado quedó listo!"
                        subtitle="El hotel lo confirma directamente. Guarda tu código: te lo pueden pedir en recepción."
                    >
                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-center"
                        >
                            <div
                                class="flex-1 rounded-xl bg-slate-50 px-4 py-3.5"
                            >
                                <div class="text-xs text-slate-500">
                                    Tu código
                                </div>
                                <div class="flex items-center gap-2">
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
                                            :icon="
                                                codeCopied ? 'Check' : 'Copy'
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                        {{ codeCopied ? 'Copiado' : 'Copiar' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-if="holdExpired"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                        >
                            <Lucide
                                icon="TimerOff"
                                class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                            />
                            El plazo de tu apartado terminó. Si el hotel aún no
                            te confirmó, escríbele con tu código.
                        </div>
                        <div
                            v-else-if="payLater && hold.hold_expires_at"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3 py-2.5 text-sm text-slate-700"
                        >
                            <Lucide
                                icon="Clock"
                                class="mt-0.5 h-4 w-4 shrink-0 text-warning"
                            />
                            Tienes hasta
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

                        <!-- Qué sigue -->
                        <ol class="mt-5 space-y-3">
                            <li class="flex items-start gap-3">
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-success/10 text-success"
                                >
                                    <Lucide icon="Check" class="h-4 w-4" />
                                </span>
                                <p class="pt-1 text-sm text-slate-700">
                                    Apartaste tu habitación.
                                </p>
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                    >2</span
                                >
                                <p class="pt-1 text-sm text-slate-700">
                                    El hotel la confirma y te avisa por WhatsApp
                                    {{ guestEmail ? 'y por correo' : '' }}.
                                </p>
                            </li>
                            <li class="flex items-start gap-3">
                                <span
                                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                    >3</span
                                >
                                <p class="pt-1 text-sm text-slate-700">
                                    Al llegar, presenta tu código y una
                                    identificación oficial.
                                </p>
                            </li>
                        </ol>

                        <div
                            v-if="guarantee"
                            class="mt-5 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-xs leading-relaxed text-slate-600"
                        >
                            <Lucide
                                icon="ShieldCheck"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            Aparte del total, al llegar se cobra un depósito en
                            garantía de {{ money(guarantee.amount) }} por
                            habitación, que se te devuelve al registrar tu
                            salida.
                        </div>

                        <template #footer>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 text-sm font-medium text-slate-600 shadow-sm transition hover:border-slate-300"
                                @click="addToCalendar"
                            >
                                <Lucide icon="CalendarPlus" class="h-4 w-4" />
                                Agregar a mi calendario
                            </button>
                            <a
                                :href="`/reserva?codigo=${encodeURIComponent(hold.code)}`"
                                class="ml-auto inline-flex h-11 items-center gap-1.5 rounded-full bg-primary/10 px-5 text-sm font-medium text-primary transition hover:bg-primary/15"
                            >
                                Ver el estado de mi reserva
                                <Lucide icon="ArrowRight" class="h-4 w-4" />
                            </a>
                        </template>
                    </BookingCard>

                    <!-- Con prepago: cuánto y cómo pagar -->
                    <BookingCard
                        v-else
                        icon="Wallet"
                        title="Completa tu pago"
                        :subtitle="
                            holdExpired
                                ? `Código ${hold.code}`
                                : holdCountdown
                                  ? `Código ${hold.code} · tu apartado se libera en ${holdCountdown} si no se paga`
                                  : `Código ${hold.code}`
                        "
                    >
                        <PaymentPanel
                            ref="paymentPanel"
                            :code="hold.code"
                            :total="hold.total"
                            :deposit="hold.deposit"
                            :currency="property.currency"
                            :payment-optional="hold.payment_optional"
                            :balance-notice="hold.balance_notice"
                            :expired="holdExpired"
                            :phone="property.phone"
                            :urls="{
                                options: '/api/booking/payment-options',
                                payment: `/api/booking/holds/${hold.code}/payment`,
                                payLater: `/api/booking/holds/${hold.code}/pay-later`,
                            }"
                            @pay-later="onPayLater"
                        />
                        <template v-if="holdExpired" #footer>
                            <Button
                                variant="primary"
                                class="ml-auto"
                                :class="primaryBtn"
                                @click="startOver"
                            >
                                Hacer una reserva nueva
                            </Button>
                        </template>
                    </BookingCard>
                </template>
            </div>

            <!-- "Tu reserva" fijo al costado (escritorio) -->
            <aside v-if="summary" class="hidden lg:sticky lg:top-6 lg:block">
                <BookingCard icon="ReceiptText" title="Tu reserva">
                    <StaySummary
                        v-bind="summary"
                        :currency="property.currency"
                        :secondary-currency="property.currency_secondary"
                        :exchange-rate="property.exchange_rate"
                        :removable-coupon="!hold && step === 'guest'"
                        @remove="removeSummaryLine"
                        @remove-coupon="removeCoupon"
                    />
                </BookingCard>
                <div
                    v-if="
                        cancellationPolicy.label ||
                        cancellationPolicy.text ||
                        (guarantee && step !== 'confirm')
                    "
                    class="mt-4 space-y-3 rounded-2xl bg-white p-5 text-xs leading-relaxed text-slate-500 shadow-xl shadow-black/10"
                >
                    <div v-if="guarantee && step !== 'confirm'">
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
                            Si apartas varias habitaciones:
                            {{ guarantee.tiers_label }}.</template
                        >
                    </div>
                    <div
                        v-if="
                            cancellationPolicy.label || cancellationPolicy.text
                        "
                    >
                        <div
                            class="mb-1 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                        >
                            <Lucide icon="Undo2" class="h-4 w-4 text-primary" />
                            Política de cancelación
                        </div>
                        <p v-if="cancellationPolicy.label">
                            {{ cancellationPolicy.label }}
                        </p>
                        <p v-if="cancellationPolicy.text" class="mt-1">
                            {{ cancellationPolicy.text }}
                        </p>
                    </div>
                </div>
            </aside>

            <!-- Celular: el resumen del paso de pago/listo va debajo, a la vista -->
            <div v-if="summary && step === 'confirm'" class="lg:hidden">
                <BookingCard icon="ReceiptText" title="Tu reserva">
                    <StaySummary
                        v-bind="summary"
                        :currency="property.currency"
                        :secondary-currency="property.currency_secondary"
                        :exchange-rate="property.exchange_rate"
                    />
                </BookingCard>
            </div>
        </div>

        <!-- Detalle de una habitación -->
        <BookingModal
            :open="detailsOption !== null"
            :title="detailsOption?.name ?? ''"
            :subtitle="
                detailsOption
                    ? stayLabel(detailsOption.starts_at, detailsOption.ends_at)
                    : undefined
            "
            icon="BedDouble"
            width="lg"
            @close="detailsOption = null"
        >
            <div v-if="detailsOption" class="space-y-4">
                <PhotoGallery
                    :photos="detailsOption.photos"
                    :alt="detailsOption.name"
                    height="md"
                />
                <p
                    v-if="detailsOption.description"
                    class="text-sm leading-relaxed text-slate-600"
                >
                    {{ detailsOption.description }}
                </p>
                <div
                    class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-600"
                >
                    <span class="inline-flex items-center gap-1.5">
                        <Lucide icon="Users" class="h-4 w-4 text-primary" />
                        Hasta {{ detailsOption.effective_capacity }} personas
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <Lucide icon="Clock" class="h-4 w-4 text-primary" />
                        {{
                            humanizeDurationLabel(detailsOption.duration_label)
                        }}
                    </span>
                </div>
                <div v-if="detailsOption.amenities.length">
                    <div :class="groupLabel">Incluye</div>
                    <ul
                        class="grid grid-cols-1 gap-x-4 gap-y-1.5 sm:grid-cols-2"
                    >
                        <li
                            v-for="a in detailsOption.amenities"
                            :key="a"
                            class="flex items-center gap-2 text-sm text-slate-600"
                        >
                            <Lucide
                                icon="Check"
                                class="h-4 w-4 shrink-0 text-success"
                            />
                            {{ a }}
                        </li>
                    </ul>
                </div>
                <div
                    v-if="nightlyVaries(detailsOption)"
                    :class="sectionBox"
                    class="p-4"
                >
                    <NightlyBars
                        :nights="detailsOption.nightly ?? []"
                        :currency="property.currency"
                    />
                </div>
                <div
                    v-if="detailsOption.price_breakdown.length"
                    class="rounded-xl bg-slate-50 p-4"
                >
                    <div :class="groupLabel">Precio de tu estancia</div>
                    <div class="space-y-1.5 text-sm text-slate-600">
                        <div
                            v-for="line in detailsOption.price_breakdown"
                            :key="line.concept"
                            class="flex justify-between gap-3"
                        >
                            <span class="min-w-0">
                                {{ breakdownLabel(line) }}
                                <span
                                    v-if="
                                        breakdownDetail(line, property.currency)
                                    "
                                    class="block text-xs text-slate-500"
                                    >{{
                                        breakdownDetail(line, property.currency)
                                    }}</span
                                >
                            </span>
                            <span class="shrink-0 text-right">
                                <span
                                    v-if="
                                        line.regular_amount &&
                                        line.regular_amount > line.amount
                                    "
                                    class="block text-xs text-slate-400 line-through"
                                    >{{ money(line.regular_amount) }}</span
                                >
                                {{ money(line.amount) }}
                            </span>
                        </div>
                    </div>
                    <div
                        class="mt-2 flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-800"
                    >
                        <span>Total</span>
                        <span>{{ money(detailsOption.total) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Para 1 persona; si son más se recalcula al elegir.
                    </p>
                    <p
                        v-if="
                            longStayHint(
                                detailsOption.long_stay_offers,
                                property.currency,
                            )
                        "
                        class="mt-2 flex items-start gap-1.5 text-xs text-success"
                    >
                        <Lucide
                            icon="BadgePercent"
                            class="mt-px h-3.5 w-3.5 shrink-0"
                        />
                        {{
                            longStayHint(
                                detailsOption.long_stay_offers,
                                property.currency,
                            )
                        }}
                    </p>
                </div>
            </div>
            <template #footer>
                <button
                    type="button"
                    class="inline-flex h-11 items-center rounded-full border border-slate-200 px-5 text-sm font-medium text-slate-600"
                    @click="detailsOption = null"
                >
                    Cerrar
                </button>
                <Button
                    v-if="detailsOption?.available"
                    variant="primary"
                    :class="primaryBtn"
                    @click="detailsOption && chooseOption(detailsOption)"
                >
                    Elegir esta habitación
                </Button>
            </template>
        </BookingModal>

        <!-- Celular: "Tu reserva" en una ventana -->
        <BookingModal
            :open="summaryOpen"
            title="Tu reserva"
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
                :removable-coupon="!hold && step === 'guest'"
                @remove="removeSummaryLine"
                @remove-coupon="removeCoupon"
            >
                <div
                    v-if="cancellationPolicy.label || cancellationPolicy.text"
                    class="mt-4 rounded-lg bg-slate-50 p-3 text-xs leading-relaxed text-slate-500"
                >
                    <div class="mb-1 font-medium text-slate-700">
                        Política de cancelación
                    </div>
                    <p v-if="cancellationPolicy.label">
                        {{ cancellationPolicy.label }}
                    </p>
                    <p v-if="cancellationPolicy.text" class="mt-1">
                        {{ cancellationPolicy.text }}
                    </p>
                </div>
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
            <a
                href="/reserva"
                class="inline-flex h-10 items-center gap-2 rounded-full border border-current/25 bg-current/10 px-4 text-sm font-medium transition hover:bg-current/20"
            >
                <Lucide icon="TicketCheck" class="h-4 w-4" />
                ¿Ya tienes una reserva? Consulta su estado
            </a>
            <a
                v-if="hasGroups"
                href="/reservar/grupos"
                class="inline-flex h-10 items-center gap-2 rounded-full border border-current/25 bg-current/10 px-4 text-sm font-medium transition hover:bg-current/20"
            >
                <Lucide icon="Users" class="h-4 w-4" />
                ¿Vienen en grupo?
            </a>
            <a
                v-if="hasExperiences"
                href="/reservar/experiencias"
                class="inline-flex h-10 items-center gap-2 rounded-full border border-current/25 bg-current/10 px-4 text-sm font-medium transition hover:bg-current/20"
            >
                <Lucide icon="Compass" class="h-4 w-4" />
                Experiencias
            </a>
        </template>
    </PublicShell>
</template>
