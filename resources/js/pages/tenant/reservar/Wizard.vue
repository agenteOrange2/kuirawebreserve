<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useEmbedResize } from '@/composables/useEmbedResize';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import { useWizardAppearance } from '@/composables/useWizardAppearance';
import { randomUuid } from '@/lib/uuid';

interface PriceLine {
    concept: string;
    amount: number;
}

interface TypePhoto {
    id: number;
    url: string;
    thumb_url: string;
}

interface Option {
    room_type_id: number;
    room_id: number | null;
    name: string;
    description: string | null;
    capacity: number;
    effective_capacity: number;
    amenities: string[];
    photos: TypePhoto[];
    duration_label: string;
    starts_at: string;
    ends_at: string;
    total: number;
    price_breakdown: PriceLine[];
    requires_prepayment: boolean;
    available: boolean;
    rooms_count: number;
    advance_error: string | null;
}

interface ExtraProduct {
    id: number;
    name: string;
    category: string | null;
    unit: string;
    price: number;
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
    total: number;
    requires_prepayment: boolean;
    // Modo "ambos": el pago en línea se ofrece pero el huésped puede
    // elegir pagar al llegar.
    payment_optional: boolean;
    deposit: number;
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
    provider?: string;
    amount: number;
    amount_label: string;
    checkout_url?: string;
    bank_accounts?: { banco: string; titular: string; cuenta: string }[];
    whatsapps?: string[];
    valid_hours?: number;
    return_url: string;
}

// Pasos (restructurado 2026-07-11, a pedido explícito): "Fechas" ya NO
// pide personas — solo cuándo. Elegido el tipo, "Confirmar habitación"
// pregunta cuántos son con un tope duro (la ocupación real de ESE cuarto,
// / ajustes/rooms), ahí mismo se ve el cargo por persona extra si aplica.
// "Extras" solo si el hotel lo activó Y tiene productos curados
// (/ajustes/wizard). "Datos" y "Confirmación" siempre cierran.
type StepKey = 'dates' | 'room' | 'extras' | 'guest' | 'confirm';
const STEP_LABELS: Record<StepKey, string> = {
    dates: 'Fechas',
    room: 'Habitación',
    extras: 'Extras',
    guest: 'Tus datos',
    confirm: 'Confirmación',
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
    // activas reales del catálogo (spec-motor-reservas-web §13.2). Un
    // motel 100% por bloque nunca ve la pestaña "Por noche", y viceversa.
    hasNightRates: boolean;
    hasBlockRates: boolean;
    holdMinutes: number;
    hasExperiences: boolean;
    hasGroups: boolean;
}>();

// Widget incrustado: reporta su alto al iframe padre.
useEmbedResize();

// ── Apariencia personalizada (/reservas/ajustes) ──
const { isDark, rootStyle } = useWizardAppearance(props.appearance);

const adultsOnly = computed(
    () => props.property.guest_policy === 'adults_only',
);
// Ambas modalidades activas → se puede elegir; si solo hay una, no hace
// falta ni mostrar el selector, se usa esa directo.
const bothModesAvailable = computed(
    () => props.hasNightRates && props.hasBlockRates,
);

const money = (n: number) =>
    `$${Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2 })} ${props.property.currency}`;

// Doble moneda: referencia "aprox" en la segunda divisa (el cobro es en la
// primaria). exchange_rate = cuántas de la primaria equivalen a 1 secundaria.
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
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const h = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    return `${y}-${m}-${day}T${h}:${min}`;
}

// ── Catálogo de extras (se carga una sola vez; decide si el paso existe):
// products = POS con stock; addons = módulo extras (solo cargo) ──
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

// product_id → cantidad elegida (0 = no seleccionado).
const selectedProducts = ref<Record<number, number>>({});

function setProductQty(productId: number, qty: number) {
    selectedProducts.value = {
        ...selectedProducts.value,
        [productId]: Math.max(0, Math.min(20, qty)),
    };
}

// extra_id (add-on) → cantidad elegida.
const selectedAddons = ref<Record<number, number>>({});

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

// Líneas de extras elegidos (para el resumen en "Tus datos" y el desglose
// de cada paso — antes los extras desaparecían del resumen hasta el final).
// Une productos del POS y add-ons en una sola lista para mostrar.
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
// estancia. Se cargan al elegir habitación (ya hay fechas firmes) y se
// agregan en el paso Extras como plus que suma al total ──
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
// Borrador por experiencia (sesión + personas) antes de "Agregar".
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

function pickKey(pick: ExperiencePick): string {
    return `s-${pick.session_id}`;
}

// Líneas vivas de experiencias elegidas (nombre, fecha, total) — mismo
// papel que selectedProductLines pero para tours.
const experienceLines = computed(() =>
    experiencePicks.value.flatMap((pick) => {
        const exp = experienceById(pick.experience_id);
        const session = exp?.sessions.find((s) => s.id === pick.session_id);
        if (!exp || !session) return [];
        return [
            {
                id: pickKey(pick),
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

// ── Orden de pasos (dinámico: "Extras" solo si aplica) ──
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

function stepNumber(key: StepKey): number {
    return stepOrder.value.indexOf(key) + 1;
}
// Modo inicial: el que de verdad tenga tarifas (si solo hay una modalidad,
// arranca directo en esa; si hay ambas, bloque va primero por ser el caso
// más común hoy — el hotelero puede tener las dos sin problema).
const mode = ref<'block' | 'night'>(
    props.hasBlockRates || !props.hasNightRates ? 'block' : 'night',
);
const arriveDate = ref(localDateInput(new Date()));
const departDate = ref(localDateInput(new Date(Date.now() + 86400000)));
const arriveAt = ref(roundedNowInput());
// Cuántos son se pregunta hasta el paso "Confirmar habitación" (más abajo),
// ya con un cuarto concreto elegido y su tope real a la vista — no aquí.
const adults = ref(1);
const children = ref(0);

const searching = ref(false);
const searchError = ref<string | null>(null);
const searched = ref(false);
const options = ref<Option[]>([]);
const anyAvailable = ref(false);

// Desglose de precio (spec-wizard-precios-y-pasos §3): colapsado por
// default, un toggle por tarjeta — solo vale la pena mostrarlo cuando hay
// algo más que la tarifa base (persona extra, ajuste del cuarto...).
const expandedBreakdown = ref<Set<number>>(new Set());
function toggleBreakdown(roomTypeId: number) {
    const next = new Set(expandedBreakdown.value);
    if (next.has(roomTypeId)) next.delete(roomTypeId);
    else next.add(roomTypeId);
    expandedBreakdown.value = next;
}

async function searchAvailability() {
    searching.value = true;
    searchError.value = null;
    searched.value = false;
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
        const { data } = await axios.get('/api/booking/availability', {
            params,
        });
        options.value = data.options;
        anyAvailable.value = data.any_available;
        searched.value = true;
    } catch (error: any) {
        searchError.value =
            error.response?.data?.message ??
            'No se pudo consultar disponibilidad. Intenta de nuevo.';
    } finally {
        searching.value = false;
    }
}

// ── Paso "Confirmar habitación": cuántos son, con tope real del cuarto ──
const selected = ref<Option | null>(null);

// Total real de lo que se va a apartar: habitación + extras (POS y
// add-ons) + experiencias. selected.total ya trae persona extra y ajustes
// del cuarto.
const grandTotal = computed(
    () =>
        (selected.value?.total ?? 0) +
        productsSubtotal.value +
        addonsSubtotal.value +
        experiencesSubtotal.value,
);
const guestName = ref('');
const guestPhone = ref('');
const guestEmail = ref('');
const notes = ref('');
const honeypot = ref(''); // campo trampa: invisible para personas, los bots lo rellenan
const renderedAt = ref('');
let idempotencyKey = randomUuid();

const submitting = ref(false);
const submitError = ref<string | null>(null);
const roomQuoteLoading = ref(false);
const roomQuoteError = ref<string | null>(null);

// Galería del paso "Confirmar habitación": índice de la foto grande.
const galleryIndex = ref(0);

function chooseOption(option: Option) {
    selected.value = option;
    galleryIndex.value = 0;
    adults.value = 1;
    children.value = 0;
    guestName.value = '';
    guestPhone.value = '';
    guestEmail.value = '';
    notes.value = '';
    honeypot.value = '';
    renderedAt.value = new Date().toISOString();
    idempotencyKey = randomUuid();
    submitError.value = null;
    roomQuoteError.value = null;
    selectedProducts.value = {};
    selectedAddons.value = {};
    experiencePicks.value = [];
    // Con fechas firmes: qué tours tienen sesiones durante la estancia.
    loadExperiences();
    step.value = 'room';
}

// Re-cotiza ESE tipo con el número de personas real — el mismo endpoint
// de disponibilidad, acotado por room_type_id (spec-reservas-avanzado):
// puede cambiar el total (cargo por persona extra), el room_id ofrecido
// (otro cuarto del mismo tipo puede convenir más para ese grupo) y
// `available` (si nadie del tipo admite tanta gente, se avisa aquí en vez
// de que la opción desaparezca de la lista original).
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

// Tope real del cuarto elegido — el stepper de personas nunca deja pasar
// de aquí. Es ayuda de UX nada más: quien de verdad blinda esto es
// CreateReservation en el servidor, por si alguien manda la petición
// directa sin pasar por esta pantalla.
const maxGuests = computed(() => selected.value?.effective_capacity ?? 20);

// "2 adultos + 1 niño" para el resumen — mismo formato que el wizard de grupos.
const occupancyLabel = computed(() => {
    const a = `${adults.value} ${adults.value === 1 ? 'adulto' : 'adultos'}`;
    const c = children.value
        ? ` + ${children.value} ${children.value === 1 ? 'niño' : 'niños'}`
        : '';
    return `${a}${c}`;
});

function setAdults(n: number) {
    // adultsOnly deja children.value siempre en 0, así que restarlo aquí
    // no cambia nada en ese caso — no hace falta un caso especial.
    adults.value = Math.max(1, Math.min(n, maxGuests.value - children.value));
}

function setChildren(n: number) {
    children.value = Math.max(0, Math.min(n, maxGuests.value - adults.value));
}

function continueFromRoom() {
    if (!selected.value?.available) return;
    step.value = stepOrder.value.includes('extras') ? 'extras' : 'guest';
}

// Volver un paso (no directo a fechas): "room"→"dates" sí invalida la
// habitación elegida, el resto solo retrocede sin perder nada.
function goBack() {
    const idx = stepOrder.value.indexOf(step.value);
    if (idx <= 0) return;
    const previous = stepOrder.value[idx - 1];
    if (previous === 'dates') selected.value = null;
    step.value = previous;
}

const hold = ref<HoldResult | null>(null);

async function submitHold() {
    if (!selected.value) return;
    submitting.value = true;
    submitError.value = null;
    try {
        const payload: Record<string, unknown> = {
            mode: mode.value,
            room_type_id: selected.value.room_type_id,
            room_id: selected.value.room_id,
            adults: adults.value,
            children: children.value,
            guest_name: guestName.value,
            guest_phone: guestPhone.value,
            guest_email: guestEmail.value || null,
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
            {
                headers: { 'Idempotency-Key': idempotencyKey },
            },
        );
        hold.value = data;
        step.value = 'confirm';
        if (data.requires_prepayment) {
            preparePayment();
        }
    } catch (error: any) {
        const errors = error.response?.data?.errors as
            Record<string, string[]> | undefined;
        submitError.value =
            error.response?.data?.message ??
            (errors ? Object.values(errors)[0]?.[0] : null) ??
            'No se pudo crear tu apartado. Intenta de nuevo.';
    } finally {
        submitting.value = false;
    }
}

// ── Confirmación / pago (solo si la tarifa lo exige) ──
const payment = ref<PaymentResult | null>(null);
const paymentLoading = ref(false);
const paymentError = ref<string | null>(null);
// Si hay MÁS DE UN método listo, se le pregunta al huésped en vez de que
// el sistema decida solo — antes se resolvía en silencio a favor de la
// pasarela.
const paymentChoice = ref<PaymentOptions | null>(null);

// Con anticipo configurado el huésped elige CUÁNTO paga hoy: solo el
// anticipo (mínimo para apartar) o todo de una vez. El resto se cobra
// después por link o transferencia.
const payAmountChoice = ref<'deposit' | 'full'>('deposit');
const depositApplies = computed(
    () => !!hold.value && hold.value.deposit > 0 && hold.value.deposit < hold.value.total,
);
// Efectivo: el huésped eligió pagar en el hotel — se muestra la
// confirmación normal y el hotel cobra en el lugar.
const payLater = ref(false);

// Avisa al backend para que el apartado pase del hold corto al plazo de
// efectivo del hotel (Plazos, default 24 h, con tope en el check-in) y
// refleja la nueva fecha límite en pantalla. Si la llamada falla, la
// confirmación se muestra igual — el apartado conserva su plazo corto.
async function choosePayLater() {
    payLater.value = true;
    if (!hold.value) return;
    try {
        const { data } = await axios.post<{ hold_expires_at: string | null }>(
            `/api/booking/holds/${hold.value.code}/pay-later`,
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
    payAmountChoice.value = 'deposit';
    payLater.value = false;
    try {
        const { data } = await axios.get<PaymentOptions>(
            '/api/booking/payment-options',
        );
        const optionsCount =
            data.gateways.length + Number(data.transfer.available);

        // Efectivo activo pero SIN ningún método en línea listo: no hay
        // nada que elegir ni que cobrar — se confirma directo y se paga en
        // el hotel (antes esto moría en un intento de cobro imposible).
        if (hold.value?.payment_optional && optionsCount === 0) {
            await choosePayLater();
            paymentLoading.value = false;
            return;
        }

        // Con anticipo o con efectivo activo, SIEMPRE se muestra la
        // pantalla de elección aunque solo haya un método — el huésped
        // decide cuánto paga y si paga ahora o en el hotel.
        if (
            optionsCount >= 2 ||
            ((depositApplies.value || hold.value?.payment_optional) &&
                optionsCount >= 1)
        ) {
            paymentChoice.value = data;
            paymentLoading.value = false;
            return;
        }

        if (data.gateways.length === 1) {
            await requestPayment('gateway', data.gateways[0].provider);
        } else {
            await requestPayment('transfer');
        }
    } catch {
        // Si falla la consulta, se intenta igual sin preferencia (el
        // backend decide con su propia lógica de respaldo).
        await requestPayment();
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
            `/api/booking/holds/${hold.value.code}/payment`,
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
            'No se pudo generar el cobro. Tu apartado sigue vigente; contáctanos para completarlo.';
    } finally {
        paymentLoading.value = false;
    }
}

// ── Cuenta regresiva del hold ──
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
    const s = Math.floor((diff % 60000) / 1000);
    return `${m}:${String(s).padStart(2, '0')}`;
});

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('es-MX', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const codeCopied = ref(false);
async function copyCode() {
    if (!hold.value) return;
    try {
        await navigator.clipboard.writeText(hold.value.code);
        codeCopied.value = true;
        window.setTimeout(() => (codeCopied.value = false), 2000);
    } catch {
        // Silencioso: el código ya está bien visible en pantalla si falla.
    }
}
</script>

<template>
    <Head :title="`Reservar · ${property.name}`" />
    <!-- flex + m-auto: centrado vertical cuando el contenido es corto, scroll
         normal (sin recortar arriba) cuando es largo — justify-center recorta. -->
    <div
        class="flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-8 sm:px-8"
        :style="rootStyle"
    >
        <div class="m-auto w-full max-w-4xl">
            <!-- Header -->
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
                    <Lucide icon="Building2" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-lg font-medium">
                        {{ property.name }}
                    </div>
                    <div class="text-xs text-white/70">
                        Reserva en línea · precios y disponibilidad en vivo
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

            <!-- Pasos: la cantidad varía sola (2-4) según si el hotel activó Extras -->
            <div
                class="mb-5 flex flex-wrap items-center gap-2 px-1 text-xs font-medium text-white/80"
            >
                <template v-for="(key, i) in stepOrder" :key="key">
                    <span
                        class="flex items-center gap-1.5"
                        :class="stepNumber(step) >= i + 1 && 'text-white'"
                    >
                        <span
                            class="flex h-5 w-5 items-center justify-center rounded-full"
                            :class="
                                stepNumber(step) >= i + 1
                                    ? 'bg-white text-theme-1'
                                    : 'bg-white/20'
                            "
                        >
                            {{ i + 1 }}
                        </span>
                        {{ STEP_LABELS[key] }}
                    </span>
                    <span
                        v-if="i < stepOrder.length - 1"
                        class="h-px w-6 bg-white/30"
                    />
                </template>
            </div>

            <div
                class="overflow-hidden rounded-2xl bg-white shadow-2xl"
                :class="isDark && 'booking-dark'"
            >
                <!-- ═══ PASO: fechas y personas ═══ -->
                <div v-if="step === 'dates'" class="p-5 sm:p-7">
                    <h1 class="text-lg font-medium text-slate-800">
                        ¿Cuándo quieres reservar?
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        El precio se calcula al momento — siempre el vigente.
                    </p>
                    <p
                        v-if="adultsOnly"
                        class="mt-2 flex items-center gap-1.5 text-xs font-medium text-warning"
                    >
                        <Lucide icon="ShieldAlert" class="h-3.5 w-3.5" />
                        Establecimiento exclusivo para mayores de edad.
                    </p>

                    <!-- El selector solo aparece si el catálogo realmente vende en ambas modalidades -->
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
                    <div v-else class="mt-5 text-sm font-medium text-slate-600">
                        {{
                            mode === 'night'
                                ? 'Por noche(s)'
                                : property.block_mode_label
                        }}
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
                    <p class="mt-2 text-xs text-slate-400">
                        Cuántos son se confirma al elegir la habitación — cada
                        cuarto tiene su propio máximo.
                    </p>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        :disabled="searching"
                        @click="searchAvailability"
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

                    <!-- Resultados -->
                    <div v-if="searched" class="mt-6 space-y-3">
                        <div
                            v-if="!anyAvailable"
                            class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 py-8 text-center"
                        >
                            <Lucide
                                icon="CircleAlert"
                                class="h-8 w-8 text-slate-300"
                            />
                            <div>
                                <p class="text-sm font-medium text-slate-600">
                                    Sin disponibilidad para esas fechas
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Prueba con otras fechas, o escríbenos y te
                                    ayudamos a encontrar una opción.
                                </p>
                            </div>
                            <a
                                href="/chat"
                                class="flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            >
                                <Lucide icon="MessageCircle" class="h-4 w-4" />
                                Hablar con el hotel
                            </a>
                        </div>

                        <!-- Grupos: el alta multi-habitación la arma el hotel -->
                        <div
                            v-if="hasGroups && anyAvailable"
                            class="flex items-start gap-2 rounded-xl border border-dashed border-slate-200 px-4 py-3 text-xs text-slate-500"
                        >
                            <Lucide
                                icon="UsersRound"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span>
                                ¿Vienen en grupo y necesitan varias
                                habitaciones?
                                <a
                                    v-if="property.phone"
                                    :href="`tel:${property.phone}`"
                                    class="font-medium text-primary hover:underline"
                                    >Llámanos</a
                                >
                                <a
                                    v-else
                                    href="/chat"
                                    class="font-medium text-primary hover:underline"
                                    >Escríbenos</a
                                >
                                y te armamos la reserva completa con folio de
                                grupo.
                            </span>
                        </div>

                        <div
                            v-for="option in options"
                            :key="option.room_type_id"
                            class="overflow-hidden rounded-xl border transition"
                            :class="
                                option.available
                                    ? 'border-slate-200 hover:border-primary/40 hover:shadow-lg hover:shadow-slate-200/60'
                                    : 'border-slate-100 opacity-60'
                            "
                        >
                            <!-- Portada: la primera foto de la galería del tipo -->
                            <div
                                v-if="option.photos.length"
                                class="relative h-44 w-full bg-slate-100 sm:h-52"
                            >
                                <img
                                    :src="option.photos[0].thumb_url"
                                    :alt="option.name"
                                    class="h-full w-full cursor-pointer object-cover"
                                    loading="lazy"
                                    @click="
                                        option.available && chooseOption(option)
                                    "
                                />
                                <span
                                    v-if="option.photos.length > 1"
                                    class="absolute right-2 bottom-2 flex items-center gap-1 rounded-full bg-black/50 px-2 py-0.5 text-[11px] font-medium text-white"
                                >
                                    <Lucide icon="Image" class="h-3 w-3" />
                                    {{ option.photos.length }}
                                </span>
                            </div>
                            <div
                                class="flex flex-wrap items-start justify-between gap-3 p-4"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="font-medium text-slate-800"
                                            >{{ option.name }}</span
                                        >
                                        <span
                                            v-if="!option.available"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                                        >
                                            Sin disponibilidad
                                        </span>
                                    </div>
                                    <p
                                        v-if="option.description"
                                        class="mt-1 text-xs text-slate-500"
                                    >
                                        {{ option.description }}
                                    </p>
                                    <div
                                        class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <span class="flex items-center gap-1">
                                            <Lucide
                                                icon="Users"
                                                class="h-3.5 w-3.5"
                                            />
                                            Hasta {{ option.capacity }}
                                            <template
                                                v-if="
                                                    option.effective_capacity >
                                                    option.capacity
                                                "
                                            >
                                                (hasta
                                                {{ option.effective_capacity }}
                                                con cargo extra)
                                            </template>
                                        </span>
                                        <span class="flex items-center gap-1"
                                            ><Lucide
                                                icon="Clock"
                                                class="h-3.5 w-3.5"
                                            />
                                            {{ option.duration_label }}</span
                                        >
                                    </div>
                                    <div
                                        v-if="option.amenities.length"
                                        class="mt-2 flex flex-wrap gap-1"
                                    >
                                        <span
                                            v-for="a in option.amenities.slice(
                                                0,
                                                5,
                                            )"
                                            :key="a"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500"
                                            >{{ a }}</span
                                        >
                                    </div>
                                    <p
                                        v-if="option.advance_error"
                                        class="mt-1.5 text-[11px] text-warning"
                                    >
                                        {{ option.advance_error }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        class="text-base font-semibold text-slate-800"
                                    >
                                        {{ money(option.total) }}
                                    </div>
                                    <button
                                        v-if="option.price_breakdown.length > 1"
                                        type="button"
                                        class="mt-0.5 text-[11px] font-medium text-primary hover:underline"
                                        @click="
                                            toggleBreakdown(option.room_type_id)
                                        "
                                    >
                                        {{
                                            expandedBreakdown.has(
                                                option.room_type_id,
                                            )
                                                ? 'Ocultar detalle'
                                                : '¿Por qué este precio?'
                                        }}
                                    </button>
                                    <Button
                                        v-if="option.available"
                                        variant="primary"
                                        size="sm"
                                        class="mt-2"
                                        @click="chooseOption(option)"
                                    >
                                        Elegir
                                    </Button>
                                </div>
                            </div>
                            <div
                                v-if="
                                    expandedBreakdown.has(
                                        option.room_type_id,
                                    ) && option.price_breakdown.length > 1
                                "
                                class="space-y-1 border-t border-slate-100 px-4 py-3 text-xs text-slate-500"
                            >
                                <div
                                    v-for="line in option.price_breakdown"
                                    :key="line.concept"
                                    class="flex justify-between"
                                >
                                    <span>{{ line.concept }}</span
                                    ><span>{{ money(line.amount) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ PASO: confirmar habitación (cuántos son, con el tope real de ESTE cuarto) ═══ -->
                <div v-else-if="step === 'room' && selected" class="p-5 sm:p-7">
                    <button
                        type="button"
                        class="mb-4 flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                        @click="goBack"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Cambiar
                        fechas
                    </button>

                    <!-- Galería: foto grande + miniaturas para cambiarla -->
                    <div v-if="selected.photos.length" class="mb-4">
                        <div
                            class="relative h-56 w-full overflow-hidden rounded-xl bg-slate-100 sm:h-72"
                        >
                            <img
                                :src="
                                    selected.photos[
                                        Math.min(
                                            galleryIndex,
                                            selected.photos.length - 1,
                                        )
                                    ].url
                                "
                                :alt="selected.name"
                                class="h-full w-full object-cover"
                            />
                        </div>
                        <div
                            v-if="selected.photos.length > 1"
                            class="mt-2 flex gap-2 overflow-x-auto pb-1"
                        >
                            <button
                                v-for="(photo, index) in selected.photos"
                                :key="photo.id"
                                type="button"
                                class="h-14 w-20 shrink-0 overflow-hidden rounded-lg border-2 transition"
                                :class="
                                    index === galleryIndex
                                        ? 'border-primary'
                                        : 'border-transparent opacity-70 hover:opacity-100'
                                "
                                @click="galleryIndex = index"
                            >
                                <img
                                    :src="photo.thumb_url"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                            </button>
                        </div>
                    </div>

                    <h2 class="text-lg font-medium text-slate-800">
                        {{ selected.name }}
                    </h2>
                    <p
                        v-if="selected.description"
                        class="mt-1 text-sm text-slate-500"
                    >
                        {{ selected.description }}
                    </p>
                    <div
                        class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-slate-500"
                    >
                        <span class="flex items-center gap-1"
                            ><Lucide icon="Clock" class="h-3.5 w-3.5" />
                            {{ selected.duration_label }}</span
                        >
                        <span class="flex items-center gap-1">
                            <Lucide icon="Calendar" class="h-3.5 w-3.5" />
                            {{ formatDateTime(selected.starts_at) }} →
                            {{ formatDateTime(selected.ends_at) }}
                        </span>
                    </div>
                    <div
                        v-if="selected.amenities.length"
                        class="mt-2 flex flex-wrap gap-1"
                    >
                        <span
                            v-for="a in selected.amenities"
                            :key="a"
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500"
                            >{{ a }}</span
                        >
                    </div>

                    <h3 class="mt-6 text-sm font-medium text-slate-800">
                        ¿Cuántos son?
                    </h3>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Esta habitación admite hasta {{ maxGuests }} persona{{
                            maxGuests === 1 ? '' : 's'
                        }}
                        en total.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-4">
                        <div>
                            <FormLabel>Adultos</FormLabel>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                    :disabled="adults <= 1"
                                    @click="setAdults(adults - 1)"
                                >
                                    <Lucide icon="Minus" class="h-3.5 w-3.5" />
                                </button>
                                <span
                                    class="w-6 text-center text-sm font-medium"
                                    >{{ adults }}</span
                                >
                                <button
                                    type="button"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                    :disabled="adults + children >= maxGuests"
                                    @click="setAdults(adults + 1)"
                                >
                                    <Lucide icon="Plus" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                        <div v-if="!adultsOnly">
                            <FormLabel>Niños</FormLabel>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                    :disabled="children <= 0"
                                    @click="setChildren(children - 1)"
                                >
                                    <Lucide icon="Minus" class="h-3.5 w-3.5" />
                                </button>
                                <span
                                    class="w-6 text-center text-sm font-medium"
                                    >{{ children }}</span
                                >
                                <button
                                    type="button"
                                    class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                    :disabled="adults + children >= maxGuests"
                                    @click="setChildren(children + 1)"
                                >
                                    <Lucide icon="Plus" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="adultsOnly"
                        class="mt-1 flex items-center gap-1.5 text-xs font-medium text-warning"
                    >
                        <Lucide icon="ShieldAlert" class="h-3.5 w-3.5" />
                        Establecimiento exclusivo para mayores de edad.
                    </p>

                    <div class="mt-5 rounded-xl bg-slate-50 p-4">
                        <div
                            v-if="roomQuoteLoading"
                            class="flex items-center gap-2 text-sm text-slate-500"
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="h-4 w-4 animate-spin"
                            />
                            Recalculando…
                        </div>
                        <template v-else>
                            <div
                                v-if="!selected.available"
                                class="flex items-center gap-2 text-sm text-warning"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="h-4 w-4 shrink-0"
                                />
                                Esta habitación no tiene cupo disponible para
                                {{ adults + children }} persona{{
                                    adults + children === 1 ? '' : 's'
                                }}
                                en ese horario.
                            </div>
                            <template v-else>
                                <div
                                    v-for="line in selected.price_breakdown"
                                    :key="line.concept"
                                    class="flex justify-between text-xs text-slate-500"
                                >
                                    <span>{{ line.concept }}</span
                                    ><span>{{ money(line.amount) }}</span>
                                </div>
                                <div
                                    class="mt-1.5 flex items-center justify-between border-t border-slate-200 pt-1.5 text-base font-semibold text-slate-800"
                                >
                                    <span>Total</span
                                    ><span>{{ money(selected.total) }}</span>
                                </div>
                            </template>
                        </template>
                    </div>
                    <p v-if="roomQuoteError" class="mt-2 text-xs text-danger">
                        {{ roomQuoteError }}
                    </p>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        :disabled="roomQuoteLoading || !selected.available"
                        @click="continueFromRoom"
                    >
                        <Lucide icon="ArrowRight" class="mr-2 h-4 w-4" />
                        Continuar
                    </Button>
                </div>

                <!-- ═══ PASO: datos del huésped ═══ -->
                <div
                    v-else-if="step === 'guest' && selected"
                    class="p-5 sm:p-7"
                >
                    <button
                        type="button"
                        class="mb-4 flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                        @click="goBack"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Atrás
                    </button>

                    <!-- Resumen con la misma estructura que "Tu grupo" en el
                         wizard grupal: encabezado con chips, renglones con
                         cantidad + subtítulo, total al pie. -->
                    <div class="rounded-xl bg-slate-50 p-4 sm:p-5">
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <div class="text-sm font-medium text-slate-800">
                                Tu reserva
                            </div>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    v-if="selected.duration_label"
                                    class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 shadow-sm"
                                >
                                    {{ selected.duration_label }}
                                </span>
                                <span
                                    class="rounded-full bg-white px-2.5 py-1 text-xs font-medium text-slate-600 shadow-sm"
                                >
                                    {{ adults + children }} persona{{
                                        adults + children === 1 ? '' : 's'
                                    }}
                                </span>
                            </div>
                        </div>
                        <div class="mt-2 divide-y divide-slate-200/80">
                            <div
                                class="flex items-center justify-between gap-3 py-2.5"
                            >
                                <div class="flex min-w-0 items-center gap-3">
                                    <img
                                        v-if="selected.photos.length"
                                        :src="selected.photos[0].thumb_url"
                                        :alt="selected.name"
                                        class="h-12 w-16 shrink-0 rounded-lg object-cover"
                                    />
                                    <div class="min-w-0">
                                        <div
                                            class="truncate text-sm text-slate-700"
                                        >
                                            <span
                                                class="font-medium text-slate-800"
                                                >1×</span
                                            >
                                            {{ selected.name }}
                                        </div>
                                        <div
                                            class="mt-0.5 text-xs text-slate-400"
                                        >
                                            {{
                                                formatDateTime(
                                                    selected.starts_at,
                                                )
                                            }}
                                            →
                                            {{
                                                formatDateTime(selected.ends_at)
                                            }}
                                            · {{ occupancyLabel }}
                                        </div>
                                    </div>
                                </div>
                                <div class="shrink-0 text-sm text-slate-600">
                                    {{ money(selected.total) }}
                                </div>
                            </div>
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
                            <div
                                v-for="line in selectedProductLines"
                                :key="line.id"
                                class="flex items-center justify-between gap-3 py-2.5"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate text-sm text-slate-700"
                                    >
                                        <span class="font-medium text-slate-800"
                                            >{{ line.qty }}×</span
                                        >
                                        {{ line.name }}
                                    </div>
                                </div>
                                <div class="shrink-0 text-sm text-slate-600">
                                    {{ money(line.total) }}
                                </div>
                            </div>
                        </div>
                        <div
                            class="flex items-center justify-between border-t border-slate-200 pt-3"
                        >
                            <span class="text-sm text-slate-500">Total</span>
                            <span
                                class="text-base font-semibold text-slate-800"
                                >{{ money(grandTotal) }}</span
                            >
                        </div>
                    </div>

                    <h2 class="mt-5 text-base font-medium text-slate-800">
                        Tus datos
                    </h2>
                    <div class="mt-3 space-y-4">
                        <div>
                            <FormLabel>Nombre completo *</FormLabel>
                            <FormInput
                                v-model="guestName"
                                type="text"
                                placeholder="Como aparece en tu identificación"
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
                                placeholder="Hora aproximada de llegada, alguna petición…"
                            />
                        </div>
                        <!-- Campo trampa: invisible para personas, los bots lo rellenan solo -->
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
                                : 'Apartar esta habitación'
                        }}
                    </Button>
                    <!-- holdMinutes del servidor: antes se mostraba por error la
                         duración de la ESTANCIA ("720 minutos" en bloques de 12h). -->
                    <p class="mt-2.5 text-center text-[11px] text-slate-400">
                        Se aparta por {{ holdMinutes }} minutos mientras
                        confirmas. No se pide ningún dato de tarjeta en este
                        paso.
                    </p>
                </div>

                <!-- ═══ PASO: extras (opcional, solo si el hotel lo activó) ═══ -->
                <div
                    v-else-if="step === 'extras' && selected"
                    class="p-5 sm:p-7"
                >
                    <button
                        type="button"
                        class="mb-4 flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                        @click="goBack"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Volver
                    </button>

                    <h2 class="text-lg font-medium text-slate-800">
                        ¿Algo más para tu llegada?
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Opcional — se prepara y te espera, se suma a tu total.
                    </p>

                    <!-- Experiencias: tours con sesiones durante la estancia,
                         se agregan como plus con su propio cupo -->
                    <div v-if="experiencesCatalog?.enabled" class="mt-5">
                        <div
                            class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Recorridos y experiencias
                        </div>
                        <div class="space-y-3">
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

                        <!-- Tours ya agregados -->
                        <div
                            v-if="experienceLines.length"
                            class="mt-3 space-y-2"
                        >
                            <div
                                v-for="line in experienceLines"
                                :key="line.id"
                                class="flex items-center justify-between gap-3 rounded-lg bg-primary/5 px-3 py-2.5 text-sm"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate font-medium text-slate-800"
                                    >
                                        {{ line.people }}× {{ line.name }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ formatDateTime(line.starts_at) }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span
                                        class="text-sm font-medium text-slate-800"
                                        >{{ money(line.total) }}</span
                                    >
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
                    </div>

                    <!-- Add-ons (módulo extras): decoración, desayuno, late checkout -->
                    <div v-if="extrasCatalog?.addons.length" class="mt-5">
                        <div
                            class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Para tu estancia
                        </div>
                        <div class="space-y-2">
                            <div
                                v-for="addon in extrasCatalog.addons"
                                :key="addon.id"
                                class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 p-3"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="truncate text-sm font-medium text-slate-800"
                                    >
                                        {{ addon.name }}
                                    </div>
                                    <div
                                        v-if="addon.description"
                                        class="truncate text-xs text-slate-500"
                                    >
                                        {{ addon.description }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ money(addon.price) }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                        :disabled="!selectedAddons[addon.id]"
                                        @click="
                                            setAddonQty(
                                                addon.id,
                                                (selectedAddons[addon.id] ??
                                                    0) - 1,
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
                                            selectedAddons[addon.id] ?? 0
                                        }}</span
                                    >
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50"
                                        @click="
                                            setAddonQty(
                                                addon.id,
                                                (selectedAddons[addon.id] ??
                                                    0) + 1,
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
                        </div>
                    </div>

                    <div class="mt-5 space-y-5">
                        <div
                            v-for="[category, items] in extrasByCategory"
                            :key="category"
                        >
                            <div
                                class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                {{ category }}
                            </div>
                            <div class="space-y-2">
                                <div
                                    v-for="p in items"
                                    :key="p.id"
                                    class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 p-3"
                                >
                                    <div class="min-w-0">
                                        <div
                                            class="truncate text-sm font-medium text-slate-800"
                                        >
                                            {{ p.name }}
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            {{ money(p.price) }} / {{ p.unit }}
                                        </div>
                                    </div>
                                    <div
                                        class="flex shrink-0 items-center gap-2"
                                    >
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50 disabled:opacity-30"
                                            :disabled="!selectedProducts[p.id]"
                                            @click="
                                                setProductQty(
                                                    p.id,
                                                    (selectedProducts[p.id] ??
                                                        0) - 1,
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
                                                selectedProducts[p.id] ?? 0
                                            }}</span
                                        >
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-50"
                                            @click="
                                                setProductQty(
                                                    p.id,
                                                    (selectedProducts[p.id] ??
                                                        0) + 1,
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
                            </div>
                        </div>
                    </div>

                    <!-- Resumen vivo: habitación + extras + experiencias + total,
                         no solo el subtotal de extras suelto (feedback 2026-07-14). -->
                    <div class="mt-5 rounded-lg bg-slate-50 px-4 py-3 text-sm">
                        <div
                            class="flex items-center justify-between text-slate-500"
                        >
                            <span>{{ selected.name }}</span>
                            <span>{{ money(selected.total) }}</span>
                        </div>
                        <div
                            v-for="line in experienceLines"
                            :key="line.id"
                            class="mt-1 flex items-center justify-between text-slate-500"
                        >
                            <span>{{ line.people }}× {{ line.name }}</span>
                            <span>{{ money(line.total) }}</span>
                        </div>
                        <div
                            v-for="line in selectedProductLines"
                            :key="line.id"
                            class="mt-1 flex items-center justify-between text-slate-500"
                        >
                            <span>{{ line.qty }}× {{ line.name }}</span>
                            <span>{{ money(line.total) }}</span>
                        </div>
                        <div
                            class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-base font-semibold text-slate-800"
                        >
                            <span>Total</span>
                            <span>{{ money(grandTotal) }}</span>
                        </div>
                        <div
                            v-if="secondaryMoney(grandTotal)"
                            class="mt-0.5 text-right text-xs text-slate-400"
                        >
                            {{ secondaryMoney(grandTotal) }}
                        </div>
                    </div>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        @click="step = 'guest'"
                    >
                        <Lucide icon="ArrowRight" class="mr-2 h-4 w-4" />
                        Continuar
                    </Button>
                </div>

                <!-- ═══ PASO: confirmación / pago ═══ -->
                <div
                    v-else-if="step === 'confirm' && hold"
                    class="p-5 text-center sm:p-7"
                >
                    <!-- Sin prepago (o eligió pagar al llegar): confirmación directa -->
                    <template v-if="!hold.requires_prepayment || payLater">
                        <div
                            class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success"
                        >
                            <Lucide icon="Check" class="h-7 w-7" />
                        </div>
                        <h2 class="text-lg font-medium text-slate-800">
                            ¡Tu apartado quedó listo!
                        </h2>
                        <p class="mt-1.5 text-sm text-slate-500">
                            El hotel confirmará directamente. Guarda tu código —
                            te lo pueden pedir en recepción.
                        </p>
                        <p
                            v-if="payLater && hold.hold_expires_at"
                            class="mt-1.5 text-xs text-warning"
                        >
                            Tienes hasta {{ formatDateTime(hold.hold_expires_at) }}
                            para pagar en recepción; si no, el apartado se
                            libera.
                        </p>
                        <div
                            class="mx-auto mt-4 max-w-xs rounded-xl bg-slate-50 p-4 text-left"
                        >
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <div
                                    class="text-2xl font-semibold tracking-wide text-slate-800"
                                >
                                    {{ hold.code }}
                                </div>
                                <button
                                    type="button"
                                    class="flex shrink-0 items-center gap-1 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-medium text-slate-500 transition hover:bg-white"
                                    @click="copyCode"
                                >
                                    <Lucide
                                        :icon="codeCopied ? 'Check' : 'Copy'"
                                        class="h-3 w-3"
                                    />
                                    {{ codeCopied ? 'Copiado' : 'Copiar' }}
                                </button>
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ hold.room_type }}
                            </div>
                            <div class="mt-1 text-xs text-slate-500">
                                {{ formatDateTime(hold.starts_at) }} →
                                {{ formatDateTime(hold.ends_at) }}
                            </div>
                            <div
                                class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-xs text-slate-500"
                            >
                                <template v-if="hold.price_breakdown.length">
                                    <div
                                        v-for="line in hold.price_breakdown"
                                        :key="line.concept"
                                        class="flex justify-between"
                                    >
                                        <span>{{ line.concept }}</span
                                        ><span>{{ money(line.amount) }}</span>
                                    </div>
                                </template>
                                <div v-else class="flex justify-between">
                                    <span>Habitación</span
                                    ><span>{{ money(hold.room_total) }}</span>
                                </div>
                                <div
                                    v-for="line in hold.experiences ?? []"
                                    :key="`e-${line.experience_booking_id}`"
                                    class="flex justify-between"
                                >
                                    <span
                                        >{{ line.people }}×
                                        {{ line.name }}</span
                                    ><span>{{ money(line.total) }}</span>
                                </div>
                                <div
                                    v-for="line in hold.extras"
                                    :key="`a-${line.extra_id}`"
                                    class="flex justify-between"
                                >
                                    <span>{{ line.qty }}× {{ line.name }}</span
                                    ><span>{{ money(line.total) }}</span>
                                </div>
                                <div
                                    v-for="line in hold.products"
                                    :key="line.product_id"
                                    class="flex justify-between"
                                >
                                    <span>{{ line.qty }}× {{ line.name }}</span
                                    ><span>{{ money(line.total) }}</span>
                                </div>
                            </div>
                            <div
                                class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-base font-medium text-slate-800"
                            >
                                <span>Total</span
                                ><span>{{ money(hold.total) }}</span>
                            </div>
                            <div
                                v-if="secondaryMoney(hold.total)"
                                class="mt-0.5 text-right text-xs text-slate-400"
                            >
                                {{ secondaryMoney(hold.total) }}
                            </div>
                        </div>
                        <p
                            v-if="holdCountdown && !payLater"
                            class="mt-3 text-xs text-warning"
                        >
                            Se libera sola en {{ holdCountdown }} si el hotel no
                            confirma antes.
                        </p>
                    </template>

                    <!-- Con prepago: elegir método, pasarela o transferencia -->
                    <template v-else>
                        <div v-if="paymentLoading" class="py-6">
                            <Lucide
                                icon="RefreshCw"
                                class="mx-auto h-8 w-8 animate-spin text-primary"
                            />
                            <p class="mt-3 text-sm text-slate-500">
                                Preparando tu cobro…
                            </p>
                        </div>

                        <!-- Ambos métodos disponibles: se le pregunta al huésped, ya no se decide en silencio -->
                        <template v-else-if="paymentChoice && !payment">
                            <div
                                class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="Wallet" class="h-7 w-7" />
                            </div>
                            <h2 class="text-lg font-medium text-slate-800">
                                ¿Cómo prefieres pagar?
                            </h2>
                            <p class="mt-1.5 text-sm text-slate-500">
                                Código
                                <span class="font-medium">{{ hold.code }}</span>
                            </p>
                            <!-- Desglose antes de pagar: qué compone el total que se
                                 va a cobrar, igual que en la confirmación sin prepago. -->
                            <div
                                class="mx-auto mt-4 max-w-sm rounded-xl bg-slate-50 p-4 text-left"
                            >
                                <div class="text-xs text-slate-500">
                                    {{ hold.room_type }}
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500">
                                    {{ formatDateTime(hold.starts_at) }} →
                                    {{ formatDateTime(hold.ends_at) }}
                                </div>
                                <div
                                    class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-xs text-slate-500"
                                >
                                    <template
                                        v-if="hold.price_breakdown.length"
                                    >
                                        <div
                                            v-for="line in hold.price_breakdown"
                                            :key="line.concept"
                                            class="flex justify-between"
                                        >
                                            <span>{{ line.concept }}</span
                                            ><span>{{
                                                money(line.amount)
                                            }}</span>
                                        </div>
                                    </template>
                                    <div v-else class="flex justify-between">
                                        <span>Habitación</span
                                        ><span>{{
                                            money(hold.room_total)
                                        }}</span>
                                    </div>
                                    <div
                                        v-for="line in hold.experiences ?? []"
                                        :key="`e-${line.experience_booking_id}`"
                                        class="flex justify-between"
                                    >
                                        <span
                                            >{{ line.people }}×
                                            {{ line.name }}</span
                                        ><span>{{ money(line.total) }}</span>
                                    </div>
                                    <div
                                        v-for="line in hold.extras"
                                        :key="`a-${line.extra_id}`"
                                        class="flex justify-between"
                                    >
                                        <span
                                            >{{ line.qty }}×
                                            {{ line.name }}</span
                                        ><span>{{ money(line.total) }}</span>
                                    </div>
                                    <div
                                        v-for="line in hold.products"
                                        :key="line.product_id"
                                        class="flex justify-between"
                                    >
                                        <span
                                            >{{ line.qty }}×
                                            {{ line.name }}</span
                                        ><span>{{ money(line.total) }}</span>
                                    </div>
                                </div>
                                <div
                                    class="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-base font-medium text-slate-800"
                                >
                                    <span>Total</span
                                    ><span>{{ money(hold.total) }}</span>
                                </div>
                            </div>

                            <!-- Con anticipo: cuánto pagar hoy es decisión
                                 del huésped, no del sistema -->
                            <div
                                v-if="depositApplies"
                                class="mx-auto mt-5 max-w-sm text-left"
                            >
                                <div
                                    class="mb-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    ¿Cuánto pagas hoy?
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
                                        <div class="mt-1 text-xs text-slate-500">
                                            El resto lo pagas después, por link
                                            o transferencia.
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
                                        <div class="mt-1 text-xs text-slate-500">
                                            Liquidas hoy y te olvidas de saldos.
                                        </div>
                                    </button>
                                </div>
                            </div>

                            <div class="mx-auto mt-5 max-w-sm space-y-2.5">
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
                                            {{
                                                paymentChoice.transfer
                                                    .accounts_count
                                            }}
                                            cuenta{{
                                                paymentChoice.transfer
                                                    .accounts_count === 1
                                                    ? ''
                                                    : 's'
                                            }}
                                            disponible{{
                                                paymentChoice.transfer
                                                    .accounts_count === 1
                                                    ? ''
                                                    : 's'
                                            }}
                                        </div>
                                    </div>
                                </button>
                            </div>
                            <!-- Efectivo activo: pagar en el hotel también es opción -->
                            <div
                                v-if="hold.payment_optional"
                                class="mx-auto mt-2.5 max-w-sm"
                            >
                                <button
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
                                            Prefiero pagar en el hotel
                                        </div>
                                        <div class="text-xs text-slate-500">
                                            Pagas al llegar, en recepción; el
                                            hotel confirma tu apartado.
                                        </div>
                                    </div>
                                </button>
                            </div>
                            <p
                                v-if="holdCountdown"
                                class="mt-4 text-xs text-warning"
                            >
                                Tu apartado se libera solo en
                                {{ holdCountdown }} si no se completa el pago.
                            </p>
                        </template>

                        <template v-else-if="payment?.method === 'transfer'">
                            <div
                                class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-info/10 text-info"
                            >
                                <Lucide icon="Landmark" class="h-7 w-7" />
                            </div>
                            <h2 class="text-lg font-medium text-slate-800">
                                Completa tu apartado por transferencia
                            </h2>
                            <p class="mt-1.5 text-sm text-slate-500">
                                Código
                                <span
                                    class="inline-flex items-center gap-1 font-medium"
                                >
                                    {{ hold.code }}
                                    <button
                                        type="button"
                                        class="text-slate-400 transition hover:text-slate-600"
                                        @click="copyCode"
                                    >
                                        <Lucide
                                            :icon="
                                                codeCopied ? 'Check' : 'Copy'
                                            "
                                            class="h-3.5 w-3.5"
                                        />
                                    </button>
                                </span>
                            </p>
                            <!-- Pasos claros: transferir, comprobante por
                                 WhatsApp, confirmación -->
                            <div
                                class="mx-auto mt-4 max-w-sm space-y-3 text-left"
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
                                            a una de estas cuentas:
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
                                            Manda tu comprobante con tu código
                                            <span class="font-medium">{{
                                                hold.code
                                            }}</span
                                            >:
                                        </p>
                                        <template
                                            v-if="payment.whatsapps?.length"
                                        >
                                            <a
                                                v-for="wa in payment.whatsapps"
                                                :key="wa"
                                                :href="`https://wa.me/${wa}?text=${encodeURIComponent(`Hola, envío el comprobante de mi reserva ${hold.code}`)}`"
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
                                        En cuanto el hotel verifique tu pago, tu
                                        reserva queda confirmada y te avisamos.
                                    </p>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-slate-400">
                                Vigente por {{ payment.valid_hours }} horas.
                            </p>
                            <a
                                :href="payment.return_url"
                                class="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            >
                                Ver estado de tu reserva
                                <Lucide icon="ArrowRight" class="h-4 w-4" />
                            </a>
                        </template>

                        <template v-else-if="paymentError">
                            <Lucide
                                icon="CircleAlert"
                                class="mx-auto h-10 w-10 text-warning"
                            />
                            <h2 class="mt-3 text-lg font-medium text-slate-800">
                                Tu apartado quedó guardado
                            </h2>
                            <p class="mt-1.5 text-sm text-slate-500">
                                {{ paymentError }}
                            </p>
                            <div
                                class="mx-auto mt-4 max-w-xs rounded-xl bg-slate-50 p-4"
                            >
                                <div
                                    class="text-2xl font-semibold tracking-wide text-slate-800"
                                >
                                    {{ hold.code }}
                                </div>
                                <div
                                    class="mt-1 text-base font-medium text-slate-800"
                                >
                                    {{ money(hold.total) }}
                                </div>
                            </div>
                            <!-- Honestidad sobre el estado: sin pago, el apartado se
                                 libera solo — no queda "reservado" en silencio. -->
                            <p
                                v-if="holdCountdown"
                                class="mt-3 text-xs text-warning"
                            >
                                Sin el pago, el apartado se libera solo en
                                {{ holdCountdown }}.
                            </p>
                            <!-- El apartado sigue vivo: reintentar vuelve a consultar los
                                 métodos (por si el hotel corrigió algo) en vez de dejar
                                 al huésped sin salida. -->
                            <div class="mt-4 flex flex-col items-center gap-2">
                                <Button
                                    variant="primary"
                                    class="shadow-md shadow-primary/20"
                                    @click="preparePayment"
                                >
                                    <Lucide
                                        icon="RefreshCw"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Intentar el pago de nuevo
                                </Button>
                                <a
                                    v-if="property.phone"
                                    :href="`tel:${property.phone}`"
                                    class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                                >
                                    <Lucide icon="Phone" class="h-4 w-4" />
                                    Llamar al hotel
                                </a>
                            </div>
                        </template>

                        <!-- method === 'gateway': la página ya está redirigiendo -->
                        <template v-else>
                            <Lucide
                                icon="RefreshCw"
                                class="mx-auto h-8 w-8 animate-spin text-primary"
                            />
                            <p class="mt-3 text-sm text-slate-500">
                                Te estamos llevando a la página de pago segura…
                            </p>
                        </template>
                    </template>
                </div>
            </div>

            <!-- Accesos relacionados: legibles sobre el fondo oscuro -->
            <div
                class="mt-5 flex flex-wrap items-center justify-center gap-2.5"
            >
                <a
                    href="/reserva"
                    class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
                >
                    <Lucide icon="TicketCheck" class="h-4 w-4" /> ¿Ya tienes una
                    reserva? Consulta su estado
                </a>
                <a
                    v-if="hasGroups"
                    href="/reservar/grupos"
                    class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
                >
                    <Lucide icon="Users" class="h-4 w-4" /> ¿Vienen en grupo?
                    Aparta varias habitaciones
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
            <p class="mt-3 text-center text-xs text-white/70">
                Impulsado por KuiraWebReserve · tus datos de pago nunca pasan
                por este sitio
            </p>
        </div>
    </div>
</template>
