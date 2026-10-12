<script setup lang="ts">
import axios from 'axios';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import Button from '@/components/Base/Button';
import {
    FormInput,
    FormLabel,
    FormTextarea,
    FormTime,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import type { BankAccount } from '@/components/Booking/BankAccounts.vue';
import BankAccounts from '@/components/Booking/BankAccounts.vue';
import BookingCard from '@/components/Booking/BookingCard.vue';
import BookingModal from '@/components/Booking/BookingModal.vue';
import PaymentRing from '@/components/Booking/PaymentRing.vue';
import PublicShell from '@/components/Booking/PublicShell.vue';
import type { PublicContact } from '@/components/Booking/theme';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import {
    downloadIcs,
    formatFriendlyDateTime,
    formatMoney,
} from '@/lib/bookingFormat';

interface PendingRequest {
    method: 'gateway' | 'transfer';
    amount: number;
    amount_label: string;
    checkout_url: string | null;
    expires_at: string | null;
    bank_accounts: BankAccount[];
    // Cobro consolidado del GRP-: cubre todas las habitaciones del grupo.
    covers_group?: boolean;
}

interface PreRegistration {
    guest_name: string | null;
    guest_email: string | null;
    has_email: boolean;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    eta: string | null;
    guest_notes: string | null;
}

// Lo que comparten la reserva y el grupo: estado, estancia y cuenta. Las
// tarjetas de pago y comprobante pintan cualquiera de las dos.
interface AccountView {
    code: string;
    status: string;
    status_label: string;
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
    payments: { amount: number; method_label: string; paid_at: string }[];
    proof: { can_send: boolean; received_at: string | null };
    pending_request: PendingRequest | null;
}

interface LookupResult extends AccountView {
    kind: 'reservation';
    room_type: string | null;
    group_code: string | null;
    pre_registration: PreRegistration;
    can_cancel: boolean;
    cancellation_policy: string | null;
    cancellation_policy_text: string | null;
    // Depósito en garantía que le van a cobrar al llegar (con el escalón de
    // su grupo). Aparte del total: no es parte de la cuenta.
    guarantee: { amount: number } | null;
    cancel_refund_estimate: number | null;
}

interface GroupRoom {
    code: string;
    room_type: string | null;
    status: string;
    status_label: string;
    starts_at: string;
    ends_at: string;
    adults: number;
    children: number;
    total: number;
    paid: number;
}

interface GroupResult extends AccountView {
    kind: 'group';
    guest_name: string | null;
    rooms: GroupRoom[];
    experiences: {
        code: string;
        name: string;
        starts_at: string | null;
        people: number;
        status_label: string;
        total: number;
    }[];
}

type Tone = 'primary' | 'success' | 'info' | 'warning' | 'danger' | 'slate';

const props = defineProps<{
    // Misma apariencia que el wizard (/reservas/ajustes): una sola
    // configuración para todas las páginas públicas.
    appearance: WizardAppearance;
    property: {
        name: string;
        logo_url: string | null;
        phone: string | null;
        email: string | null;
        currency: string;
        timezone: string;
    };
    // Contacto público del hotel (redes, mapa, sitio) para el pie.
    contact: PublicContact;
}>();

const money = (n: number) => formatMoney(n, props.property.currency);
// Siempre en la hora del hotel: quien consulta desde otra zona veía la
// llegada corrida.
const when = (iso: string) =>
    formatFriendlyDateTime(iso, props.property.timezone);

// El correo de la reserva trae ?codigo= para no teclear el folio.
const code = ref(
    new URLSearchParams(window.location.search).get('codigo') ?? '',
);
const phone = ref('');
const searching = ref(false);
const error = ref<string | null>(null);
const result = ref<LookupResult | null>(null);
// Folio GRP-: la vista del grupo. Con una habitación abierta, `result` la
// enseña encima y "Volver al grupo" regresa aquí.
const group = ref<GroupResult | null>(null);
const groupKeys = ref<{ code: string; phone: string } | null>(null);
const view = computed<AccountView | null>(() => result.value ?? group.value);

// Las llaves con las que SE ENCONTRÓ la reserva. Cancelar, pre-registrar
// y subir el comprobante usan estas, no lo que haya en los campos: antes,
// si el huésped corregía el código después de buscar, el botón actuaba
// sobre lo tecleado y fallaba (o apuntaba a otra reserva).
const keys = ref<{ code: string; phone: string } | null>(null);

// El código queda en la dirección (?codigo=): al recargar o compartir la
// liga ya no hay que teclearlo otra vez (el teléfono nunca viaja ahí).
function syncCodeParam(value: string | null) {
    try {
        const url = new URL(window.location.href);
        if (value) url.searchParams.set('codigo', value);
        else url.searchParams.delete('codigo');
        window.history.replaceState(window.history.state, '', url);
    } catch {
        // Sin History API la página sigue igual.
    }
}

async function search() {
    searching.value = true;
    error.value = null;
    roomError.value = null;
    const lookup = { code: code.value.trim(), phone: phone.value.trim() };
    try {
        const { data } = await axios.get<LookupResult | GroupResult>(
            '/api/booking/reservation',
            { params: lookup },
        );
        keys.value = lookup;
        syncCodeParam(data.code);
        if (data.kind === 'group') {
            groupKeys.value = lookup;
            result.value = null;
            showGroup(data);
        } else {
            group.value = null;
            groupKeys.value = null;
            showResult(data);
        }
    } catch (e: any) {
        error.value =
            e.response?.data?.message ??
            'No se pudo consultar. Intenta de nuevo en un momento.';
    } finally {
        searching.value = false;
    }
}

function showResult(data: LookupResult) {
    result.value = data;
    // Antes el aviso "Tu reserva quedó cancelada" se quedaba pegado a la
    // siguiente reserva que se buscara.
    canceled.value = false;
    confirmingCancel.value = false;
    cancelError.value = null;
    proofFile.value = null;
    proofError.value = null;
    fillPreForm(data.pre_registration);
    preSaved.value = false;
    preError.value = null;
    // Con datos clave aún vacíos, la sección se abre sola para invitar
    // a completarlos; ya llenos, queda plegada.
    preOpen.value = !(
        data.pre_registration.eta || data.pre_registration.vehicle_plate
    );
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function showGroup(data: GroupResult) {
    group.value = data;
    canceled.value = false;
    proofFile.value = null;
    proofError.value = null;
    proofReplacing.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Una habitación del grupo: su ficha (pre-registro, cancelación) con el
// mismo teléfono con el que se abrió el grupo.
const openingRoom = ref<string | null>(null);
const roomError = ref<string | null>(null);
async function openRoom(roomCode: string) {
    if (!groupKeys.value) return;
    openingRoom.value = roomCode;
    roomError.value = null;
    const lookup = { code: roomCode, phone: groupKeys.value.phone };
    try {
        const { data } = await axios.get<LookupResult>(
            '/api/booking/reservation',
            { params: lookup },
        );
        keys.value = lookup;
        showResult(data);
    } catch (e: any) {
        roomError.value =
            e.response?.data?.message ??
            'No se pudo abrir esa habitación. Intenta de nuevo en un momento.';
    } finally {
        openingRoom.value = null;
    }
}

// De vuelta al grupo, recargado: lo hecho en la habitación (cancelarla,
// su pre-registro) cambia la cuenta del grupo.
async function backToGroup() {
    if (!groupKeys.value) return;
    keys.value = groupKeys.value;
    result.value = null;
    canceled.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    try {
        const { data } = await axios.get<GroupResult>(
            '/api/booking/reservation',
            { params: groupKeys.value },
        );
        if (data.kind === 'group') group.value = data;
    } catch {
        // Se queda la vista que ya estaba.
    }
}

// "Buscar otra": vuelve al buscador con el código listo para corregirse.
function lookupAnother() {
    result.value = null;
    group.value = null;
    groupKeys.value = null;
    keys.value = null;
    error.value = null;
    roomError.value = null;
    canceled.value = false;
    confirmingCancel.value = false;
    cancelError.value = null;
    window.setTimeout(
        () =>
            (
                document.getElementById(
                    'lookup-code',
                ) as HTMLInputElement | null
            )?.focus(),
        0,
    );
}

// El huésped lo dicta por teléfono o lo pega en un mensaje al hotel.
const codeCopied = ref(false);
async function copyCode() {
    if (!view.value) return;
    try {
        await navigator.clipboard.writeText(view.value.code);
        codeCopied.value = true;
        window.setTimeout(() => (codeCopied.value = false), 2000);
    } catch {
        // El código sigue a la vista si el navegador no deja copiar.
    }
}

// Cancelación en dos pasos: primero se muestra la política, luego se confirma.
const confirmingCancel = ref(false);
const canceling = ref(false);
const cancelError = ref<string | null>(null);
const canceled = ref(false);

async function cancelReservation() {
    if (!keys.value) return;
    canceling.value = true;
    cancelError.value = null;
    try {
        const { data } = await axios.post<LookupResult>(
            '/api/booking/reservation/cancel',
            keys.value,
        );
        result.value = data;
        confirmingCancel.value = false;
        canceled.value = true;
    } catch (e: any) {
        cancelError.value =
            e.response?.data?.message ??
            'No se pudo cancelar. Intenta de nuevo o contacta al hotel.';
    } finally {
        canceling.value = false;
    }
}

// Comprobante de transferencia: las reservas del sitio no tienen chat, y
// la página decía "envía tu comprobante al hotel" sin decir a dónde.
const proofFile = ref<File | null>(null);
const proofSending = ref(false);
const proofError = ref<string | null>(null);
const proofInput = ref<HTMLInputElement | null>(null);
const proofReplacing = ref(false);

function pickProof(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    proofError.value = null;
    if (file && file.size > 8 * 1024 * 1024) {
        proofFile.value = null;
        proofError.value =
            'El archivo pesa más de 8 MB; manda una captura de pantalla.';
        return;
    }
    proofFile.value = file;
}

function clearProof() {
    proofFile.value = null;
    proofError.value = null;
    if (proofInput.value) proofInput.value.value = '';
}

async function sendProof() {
    if (!keys.value || !proofFile.value) return;
    proofSending.value = true;
    proofError.value = null;
    const form = new FormData();
    form.append('code', keys.value.code);
    form.append('phone', keys.value.phone);
    form.append('file', proofFile.value);
    try {
        const { data } = await axios.post<LookupResult | GroupResult>(
            '/api/booking/reservation/proof',
            form,
        );
        if (data.kind === 'group') group.value = data;
        else result.value = data;
        proofReplacing.value = false;
        clearProof();
    } catch (e: any) {
        proofError.value =
            e.response?.data?.message ??
            'No se pudo enviar tu comprobante. Intenta de nuevo en un momento.';
    } finally {
        proofSending.value = false;
    }
}

const hasPayRequest = computed(
    () => !!view.value?.pending_request && !canceled.value,
);
const hasProofBlock = computed(
    () =>
        !!view.value &&
        !canceled.value &&
        (view.value.proof.can_send || !!view.value.proof.received_at),
);
const payCardSubtitle = computed(() => {
    const method = hasPayRequest.value
        ? view.value?.pending_request?.method
        : null;
    if (method === 'gateway')
        return 'Paga en línea con tarjeta; el pago se registra solo.';
    if (method === 'transfer')
        return 'Transfiere a la cuenta del hotel y sube aquí tu comprobante.';
    return 'Sube aquí el comprobante de tu transferencia.';
});

// Sin nada que hacer en la columna ancha (ya hospedado, sin contacto del
// hotel), el pago no se queda colgado a la derecha con un hueco al lado.
const hasMainColumn = computed(
    () =>
        hasPayRequest.value ||
        hasProofBlock.value ||
        canPreRegister.value ||
        (!!group.value && !result.value) ||
        !!props.property.phone ||
        !!mailHref.value,
);

const showProofForm = computed(
    () =>
        !!view.value?.proof.can_send &&
        (!view.value.proof.received_at || proofReplacing.value),
);

// Pre-registro en línea: el huésped deja sus datos antes de llegar. El
// formulario se precarga con lo que la reserva ya sabe y solo aplica en
// Pendiente/Confirmada (después del check-in ya no tiene caso).
const preOpen = ref(false);
const preSaving = ref(false);
const preError = ref<string | null>(null);
const preSaved = ref(false);
const preForm = reactive({
    guest_name: '',
    guest_email: '',
    vehicle_plate: '',
    vehicle_desc: '',
    eta: '',
    guest_notes: '',
});

const canPreRegister = computed(
    () =>
        !!result.value &&
        !canceled.value &&
        !holdLapsed.value &&
        ['pending', 'confirmed'].includes(result.value.status),
);

function fillPreForm(pre: PreRegistration) {
    preForm.guest_name = pre.guest_name ?? '';
    preForm.guest_email = pre.guest_email ?? '';
    preForm.vehicle_plate = pre.vehicle_plate ?? '';
    preForm.vehicle_desc = pre.vehicle_desc ?? '';
    preForm.eta = pre.eta ?? '';
    preForm.guest_notes = pre.guest_notes ?? '';
}

async function savePreRegistration() {
    if (!keys.value) return;
    preSaving.value = true;
    preError.value = null;
    preSaved.value = false;
    try {
        const { data } = await axios.post<LookupResult>(
            '/api/booking/reservation/pre-register',
            {
                ...keys.value,
                guest_name: preForm.guest_name.trim() || null,
                guest_email: preForm.guest_email.trim() || null,
                vehicle_plate: preForm.vehicle_plate.trim() || null,
                vehicle_desc: preForm.vehicle_desc.trim() || null,
                eta: preForm.eta || null,
                guest_notes: preForm.guest_notes.trim() || null,
            },
        );
        result.value = data;
        fillPreForm(data.pre_registration);
        preSaved.value = true;
        // Guardado, se pliega y deja a la vista el resumen con el "listo".
        preOpen.value = false;
    } catch (e: any) {
        preError.value =
            e.response?.data?.message ??
            'No se pudieron guardar tus datos. Intenta de nuevo en un momento.';
    } finally {
        preSaving.value = false;
    }
}

// Semáforo de estados: mismo lenguaje visual que el panel.
const statusMeta = computed<{ icon: Icon; tone: Tone }>(() => {
    if (holdLapsed.value) return { icon: 'Hourglass', tone: 'danger' };
    switch (view.value?.status) {
        case 'confirmed':
            return { icon: 'CircleCheck', tone: 'success' };
        case 'pending':
            return { icon: 'Clock', tone: 'warning' };
        case 'checked_in':
            return { icon: 'BedDouble', tone: 'primary' };
        case 'completed':
            return { icon: 'Flag', tone: 'slate' };
        case 'no_show':
            return { icon: 'UserX', tone: 'danger' };
        default:
            return { icon: 'CircleX', tone: 'danger' };
    }
});

const toneCircle: Record<Tone, string> = {
    primary: 'border-primary/10 bg-primary/10 text-primary',
    success: 'border-success/10 bg-success/10 text-success',
    info: 'border-info/10 bg-info/10 text-info',
    warning: 'border-warning/10 bg-warning/10 text-warning',
    danger: 'border-danger/10 bg-danger/10 text-danger',
    slate: 'border-slate-200/70 bg-slate-100 text-slate-500',
};
const toneBadge: Record<Tone, string> = {
    primary: 'bg-primary/10 text-primary',
    success: 'bg-success/10 text-success',
    info: 'bg-info/10 text-info',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-danger/10 text-danger',
    slate: 'bg-slate-100 text-slate-500',
};
const toneText: Record<Tone, string> = {
    primary: 'text-primary',
    success: 'text-success',
    info: 'text-info',
    warning: 'text-warning',
    danger: 'text-danger',
    slate: 'text-slate-400',
};

// Cuenta regresiva del apartado pendiente, igual que en el wizard.
const nowMs = ref(Date.now());
let clock: number | null = null;
onMounted(() => {
    clock = window.setInterval(() => (nowMs.value = Date.now()), 1000);
});
onBeforeUnmount(() => {
    if (clock) window.clearInterval(clock);
});

const holdMsLeft = computed(() =>
    view.value?.hold_expires_at
        ? Date.parse(view.value.hold_expires_at) - nowMs.value
        : null,
);

const holdCountdown = computed(() => {
    const diff = holdMsLeft.value;
    if (diff === null || diff <= 0 || view.value?.status !== 'pending')
        return null;
    const totalMinutes = Math.floor(diff / 60000);
    if (totalMinutes >= 60) {
        const h = Math.floor(totalMinutes / 60);
        return `${h} h ${totalMinutes % 60} min`;
    }
    return `${totalMinutes}:${String(Math.floor((diff % 60000) / 1000)).padStart(2, '0')}`;
});

// Apartado que venció sin pago: el estado dice "Cancelada", pero no la
// canceló nadie, y con el comprobante a tiempo todavía se puede reabrir.
const holdExpired = computed(
    () =>
        !!view.value &&
        !canceled.value &&
        view.value.hold_expires_at !== null &&
        (holdMsLeft.value ?? 1) <= 0 &&
        ['pending', 'cancelled'].includes(view.value.status) &&
        view.value.paid <= 0,
);

// El apartado venció mientras la página estaba abierta: el servidor lo
// libera en su siguiente vuelta, pero aquí seguía diciendo "Pendiente" (y
// ofrecía pre-registro, calendario y cancelar) hasta volver a buscar.
const holdLapsed = computed(
    () => holdExpired.value && view.value?.status === 'pending',
);

const statusLabel = computed(() =>
    holdLapsed.value ? 'Apartado vencido' : (view.value?.status_label ?? ''),
);

// Releer la reserva (o el grupo) con las mismas llaves, sin tocar la vista
// si falla.
async function refreshView() {
    const lookup = result.value ? keys.value : groupKeys.value;
    if (!lookup) return;
    try {
        const { data } = await axios.get<LookupResult | GroupResult>(
            '/api/booking/reservation',
            { params: lookup },
        );
        if (data.kind === 'group') group.value = data;
        else if (result.value) result.value = data;
    } catch {
        // Se queda lo que ya estaba.
    }
}

// Al vencer, un minuto después se pregunta al servidor cómo quedó.
let lapseTimer: number | null = null;
watch(holdLapsed, (lapsed) => {
    if (lapseTimer) window.clearTimeout(lapseTimer);
    lapseTimer = lapsed ? window.setTimeout(refreshView, 65000) : null;
});
onBeforeUnmount(() => {
    if (lapseTimer) window.clearTimeout(lapseTimer);
});

const isCancelled = computed(
    () => canceled.value || view.value?.status === 'cancelled',
);

// Lo que cambia cómo se atiende la reserva, en su franja propia.
const notices = computed(() => {
    const r = view.value;
    if (!r) return [];
    const isGroup = r === group.value;
    const list: { tone: Tone; icon: Icon; text: string }[] = [];

    if (canceled.value) {
        list.push({
            tone: 'success',
            icon: 'CircleCheck',
            text: 'Tu reserva quedó cancelada. Si tenías pagos registrados, el hotel se pondrá en contacto contigo.',
        });
    } else if (holdCountdown.value) {
        list.push({
            tone: 'warning',
            icon: 'Hourglass',
            text: `Tu apartado se libera solo en ${holdCountdown.value} si no se confirma o paga antes.`,
        });
    } else if (holdExpired.value) {
        list.push({
            tone: 'warning',
            icon: 'Hourglass',
            text: r.proof.can_send
                ? `Tu apartado venció el ${when(r.hold_expires_at!)}. Si ya transferiste, sube tu comprobante y, ${isGroup ? 'si las habitaciones siguen libres, se reabren' : 'si la habitación sigue libre, se reabre'} con este mismo código.`
                : `Tu apartado venció el ${when(r.hold_expires_at!)} sin pago; puedes hacer una reserva nueva o contactar al hotel.`,
        });
    }

    if (r.status === 'checked_in') {
        list.push({
            tone: 'primary',
            icon: 'BedDouble',
            text: isGroup
                ? `Ya están hospedados; la salida es el ${when(r.ends_at)}`
                : `Ya estás hospedado; tu salida es el ${when(r.ends_at)}`,
        });
    } else if (r.status === 'completed') {
        list.push({
            tone: 'slate',
            icon: 'Flag',
            text: 'Tu estancia ya terminó. Gracias por hospedarte con nosotros.',
        });
    }

    return list;
});

// Noches contadas en el calendario del hotel (no en el del navegador).
const dayKey = (iso: string) =>
    new Date(iso).toLocaleDateString('en-CA', {
        timeZone: props.property.timezone,
    });
const stayLength = computed(() => {
    const r = view.value;
    if (!r) return null;
    const nights = Math.round(
        (Date.parse(dayKey(r.ends_at)) - Date.parse(dayKey(r.starts_at))) /
            86400000,
    );
    if (nights >= 1) return `${nights} ${nights === 1 ? 'noche' : 'noches'}`;
    // Estancia por horas (motel): mismo día.
    const hours = Math.round(
        (Date.parse(r.ends_at) - Date.parse(r.starts_at)) / 3600000,
    );
    return hours > 0 ? `${hours} h` : null;
});

const guestsLabel = computed(() => {
    const r = view.value;
    if (!r) return '';
    const adults = `${r.adults} ${r.adults === 1 ? 'adulto' : 'adultos'}`;
    return r.children
        ? `${adults} y ${r.children} ${r.children === 1 ? 'niño' : 'niños'}`
        : adults;
});

// Del correo llega ?codigo=: el campo de teléfono se enfoca solo.
onMounted(() => {
    if (code.value) {
        (
            document.getElementById('lookup-phone') as HTMLInputElement | null
        )?.focus();
    }
});

// El número completo: con los últimos 4 dígitos y un folio consecutivo
// cualquiera abría una reserva ajena (el servidor pide los últimos 8).
const canSearch = computed(
    () => !!code.value.trim() && phone.value.replace(/\D/g, '').length >= 8,
);

function addToCalendar() {
    const v = view.value;
    if (!v) return;
    downloadIcs({
        uid: v.code,
        title: `${result.value ? (result.value.room_type ?? 'Reserva') : `Grupo ${v.code}`} · ${props.property.name}`,
        start: v.starts_at,
        end: v.ends_at,
        description: `Código de reserva: ${v.code}`,
        location: props.property.name,
    });
}

const showCalendar = computed(
    () =>
        !!view.value &&
        !canceled.value &&
        !holdLapsed.value &&
        ['pending', 'confirmed'].includes(view.value.status),
);

const canCancel = computed(
    () => !!result.value?.can_cancel && !canceled.value && !holdLapsed.value,
);

const mailHref = computed(() =>
    props.property.email && view.value
        ? `mailto:${props.property.email}?subject=${encodeURIComponent(`Mi reserva ${view.value.code}`)}`
        : null,
);

// Fecha corta para los renglones: "vie 16 oct".
const shortDay = (iso: string) =>
    new Date(iso).toLocaleDateString('es-MX', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: props.property.timezone,
    });

function roomTone(status: string): Tone {
    return (
        (
            {
                confirmed: 'success',
                pending: 'warning',
                checked_in: 'primary',
                completed: 'slate',
            } as Record<string, Tone>
        )[status] ?? 'danger'
    );
}

const stripItem = 'inline-flex items-center gap-1.5 text-slate-500';
const stripValue = 'font-medium text-slate-700';
const stripDivider = 'hidden h-4 w-px bg-slate-200 sm:block';
const sectionLabel =
    'mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase';
const primaryBtn = 'h-11 rounded-full px-6 text-sm shadow-md shadow-primary/20';
const pillBtn =
    'inline-flex h-11 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-4 text-sm font-medium text-slate-600 shadow-sm transition hover:border-primary/30 hover:text-primary';
</script>

<template>
    <PublicShell
        title="Mi reserva"
        tagline="Consulta el estado de tu reserva"
        :appearance="appearance"
        :property="property"
        :contact="contact"
        :width="result || group ? 'wide' : 'narrow'"
    >
        <!-- Buscador: con la reserva a la vista se va, y "Buscar otra" lo
             regresa. Así las acciones nunca leen campos editados después. -->
        <BookingCard
            v-if="!result && !group"
            icon="TicketCheck"
            title="Encuentra tu reserva"
            subtitle="Con tu código (te lo dimos al reservar) y el teléfono con el que reservaste."
        >
            <form
                class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                @submit.prevent="canSearch && search()"
            >
                <div>
                    <FormLabel for="lookup-code">Código de reserva</FormLabel>
                    <FormInput
                        id="lookup-code"
                        v-model="code"
                        type="text"
                        autocapitalize="characters"
                        autocomplete="off"
                        class="h-11 text-sm uppercase placeholder:normal-case"
                        placeholder="RES-2026-0019 o GRP-2026-0152"
                    />
                </div>
                <div>
                    <FormLabel for="lookup-phone">Teléfono</FormLabel>
                    <FormInput
                        id="lookup-phone"
                        v-model="phone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        class="h-11 text-sm"
                        placeholder="10 dígitos, con o sin lada"
                    />
                </div>
                <Button
                    type="submit"
                    variant="primary"
                    class="sm:mt-7"
                    :class="primaryBtn"
                    :disabled="searching || !canSearch"
                >
                    <Lucide
                        :icon="searching ? 'LoaderCircle' : 'Search'"
                        class="mr-2 h-4 w-4"
                        :class="searching && 'animate-spin'"
                    />
                    {{ searching ? 'Buscando…' : 'Buscar' }}
                </Button>
            </form>
            <p
                v-if="error"
                class="mt-4 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger"
            >
                <Lucide icon="CircleAlert" class="mt-0.5 h-4 w-4 shrink-0" />
                {{ error }}
            </p>
        </BookingCard>

        <div v-else class="space-y-5">
            <!-- Encabezado de la reserva: identidad y acciones, avisos, y
                 la franja de datos duros. -->
            <BookingCard flush as="div">
                <div
                    class="flex flex-col gap-4 p-5 sm:p-6 md:flex-row md:items-center md:justify-between"
                >
                    <div class="flex min-w-0 items-center gap-3.5">
                        <div
                            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full border"
                            :class="toneCircle[statusMeta.tone]"
                        >
                            <Lucide :icon="statusMeta.icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h1
                                    class="font-mono text-lg font-semibold tracking-wide text-slate-800"
                                >
                                    {{ view.code }}
                                </h1>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                                    :title="
                                        codeCopied
                                            ? 'Código copiado'
                                            : 'Copiar código'
                                    "
                                    @click="copyCode"
                                >
                                    <Lucide
                                        :icon="codeCopied ? 'Check' : 'Copy'"
                                        class="h-4 w-4"
                                    />
                                </button>
                                <span
                                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="toneBadge[statusMeta.tone]"
                                    >{{ statusLabel }}</span
                                >
                                <span
                                    v-if="result?.group_code"
                                    class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500"
                                    title="Tu reserva es parte de este grupo"
                                    >Grupo {{ result?.group_code }}</span
                                >
                            </div>
                            <p
                                v-if="result"
                                class="mt-0.5 text-sm text-slate-500 sm:truncate"
                            >
                                {{ result.room_type ?? 'Habitación' }} ·
                                {{ property.name }}
                            </p>
                            <p
                                v-else-if="group"
                                class="mt-0.5 text-sm text-slate-500 sm:truncate"
                            >
                                Reserva de grupo
                                <template v-if="group.guest_name"
                                    >· {{ group.guest_name }}</template
                                >
                                · {{ property.name }}
                            </p>
                        </div>
                    </div>
                    <!-- Celular: cuadrícula de 2; escritorio: fila. "Buscar
                         otra" va primero, como el volver del panel. -->
                    <div
                        class="flex w-full flex-wrap gap-2 md:w-auto md:items-center md:justify-end [&>*]:flex-1 md:[&>*]:flex-none"
                    >
                        <button
                            v-if="result && group"
                            type="button"
                            :class="pillBtn"
                            @click="backToGroup"
                        >
                            <Lucide icon="ArrowLeft" class="h-4 w-4" />
                            Volver al grupo
                        </button>
                        <button
                            v-else
                            type="button"
                            :class="pillBtn"
                            @click="lookupAnother"
                        >
                            <Lucide icon="ArrowLeft" class="h-4 w-4" />
                            Buscar otra
                        </button>
                        <button
                            v-if="showCalendar"
                            type="button"
                            :class="pillBtn"
                            @click="addToCalendar"
                        >
                            <Lucide icon="CalendarPlus" class="h-4 w-4" />
                            Calendario
                        </button>
                        <button
                            v-if="canCancel"
                            type="button"
                            class="inline-flex h-11 basis-full items-center justify-center gap-1.5 rounded-full px-4 text-sm font-medium text-danger transition hover:bg-danger/10 md:basis-auto"
                            @click="confirmingCancel = true"
                        >
                            <Lucide icon="CalendarX2" class="h-4 w-4" />
                            Cancelar reserva
                        </button>
                    </div>
                </div>

                <div
                    v-for="(notice, i) in notices"
                    :key="i"
                    class="flex items-start gap-2.5 border-t border-slate-200/70 px-5 py-3 text-sm text-slate-600 sm:px-6"
                >
                    <Lucide
                        :icon="notice.icon"
                        class="mt-0.5 h-4 w-4 shrink-0"
                        :class="toneText[notice.tone]"
                    />
                    <span>{{ notice.text }}</span>
                </div>

                <!-- Datos duros de la estancia -->
                <div
                    class="flex flex-col gap-2.5 border-t border-slate-200/70 bg-slate-50 px-5 py-3.5 text-sm sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4 sm:px-6"
                >
                    <span :class="stripItem">
                        <Lucide
                            icon="LogIn"
                            class="h-4 w-4 shrink-0 text-primary"
                        />
                        Llegas
                        <span :class="stripValue">{{
                            when(view.starts_at)
                        }}</span>
                    </span>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="LogOut"
                            class="h-4 w-4 shrink-0 text-primary"
                        />
                        Sales
                        <span :class="stripValue">{{
                            when(view.ends_at)
                        }}</span>
                    </span>
                    <template v-if="stayLength">
                        <span :class="stripDivider" />
                        <span :class="stripItem">
                            <Lucide
                                icon="Moon"
                                class="h-4 w-4 shrink-0 text-primary"
                            />
                            <span :class="stripValue">{{ stayLength }}</span>
                        </span>
                    </template>
                    <span :class="stripDivider" />
                    <span :class="stripItem">
                        <Lucide
                            icon="Users"
                            class="h-4 w-4 shrink-0 text-primary"
                        />
                        <span :class="stripValue">{{ guestsLabel }}</span>
                    </span>
                    <template v-if="group && !result">
                        <span :class="stripDivider" />
                        <span :class="stripItem">
                            <Lucide
                                icon="BedDouble"
                                class="h-4 w-4 shrink-0 text-primary"
                            />
                            <span :class="stripValue"
                                >{{ group.rooms.length }}
                                {{
                                    group.rooms.length === 1
                                        ? 'habitación'
                                        : 'habitaciones'
                                }}</span
                            >
                        </span>
                    </template>
                    <span
                        v-if="view.total > 0"
                        class="self-start rounded-full px-2.5 py-1 text-xs font-medium sm:ml-auto sm:self-auto"
                        :class="
                            isCancelled
                                ? 'bg-slate-100 text-slate-500'
                                : view.pending_balance > 0
                                  ? 'bg-warning/10 text-warning'
                                  : 'bg-success/10 text-success'
                        "
                    >
                        {{
                            isCancelled
                                ? 'Sin cargo pendiente'
                                : view.pending_balance > 0
                                  ? `Por pagar ${money(view.pending_balance)}`
                                  : 'Sin saldo pendiente'
                        }}
                    </span>
                </div>
            </BookingCard>

            <!-- El pago va primero en el DOM (en celular es lo que más
                 importa) y en escritorio se acomoda a la derecha. -->
            <div
                class="grid items-start gap-5"
                :class="
                    hasMainColumn
                        ? 'lg:grid-cols-[minmax(0,1fr)_380px]'
                        : 'mx-auto max-w-xl'
                "
            >
                <BookingCard
                    icon="Wallet"
                    title="Tu pago"
                    :subtitle="view.payment_status_label"
                    :class="hasMainColumn && 'lg:col-start-2 lg:row-start-1'"
                >
                    <!-- Cancelada sin pagos: no hay cuenta que enseñar -->
                    <div
                        v-if="isCancelled && view.paid <= 0"
                        class="flex items-start gap-2.5 rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-600"
                    >
                        <Lucide
                            icon="CircleCheck"
                            class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                        />
                        <span
                            >Esta reserva está cancelada y no tiene pagos: no
                            hay nada por pagar.</span
                        >
                    </div>
                    <template v-else>
                        <PaymentRing
                            :total="view.total"
                            :paid="view.paid"
                            :currency="property.currency"
                        />
                        <p
                            v-if="isCancelled"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                            />
                            <span
                                >La reserva está cancelada. Lo que pagaste lo
                                revisa el hotel según su política y te
                                contacta.</span
                            >
                        </p>
                    </template>
                    <p
                        v-if="
                            view.payment_due_at &&
                            view.pending_balance > 0 &&
                            !isCancelled &&
                            !holdLapsed
                        "
                        class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600"
                    >
                        <Lucide
                            icon="CalendarClock"
                            class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                        />
                        <span
                            >Fecha límite de pago:
                            <span class="font-medium text-slate-700">{{
                                when(view.payment_due_at)
                            }}</span></span
                        >
                    </p>

                    <!-- Abonos que ya cuentan -->
                    <div
                        v-if="view.payments.length"
                        class="mt-5 border-t border-slate-200/70 pt-4"
                    >
                        <div :class="sectionLabel">Pagos registrados</div>
                        <ul class="divide-y divide-slate-200 text-sm">
                            <li
                                v-for="(p, i) in view.payments"
                                :key="i"
                                class="flex items-center justify-between gap-3 py-2"
                            >
                                <div class="min-w-0">
                                    <div class="text-slate-700">
                                        {{ p.method_label }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ when(p.paid_at) }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 font-medium text-slate-700"
                                    >{{ money(p.amount) }}</span
                                >
                            </li>
                        </ul>
                    </div>

                    <!-- Depósito en garantía: aparte de la cuenta -->
                    <p
                        v-if="result?.guarantee && showCalendar"
                        class="mt-5 flex items-start gap-2 border-t border-slate-200/70 pt-4 text-sm leading-relaxed text-slate-500"
                    >
                        <Lucide
                            icon="ShieldCheck"
                            class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                        />
                        Aparte del total, al llegar se cobra un depósito en
                        garantía de {{ money(result.guarantee.amount) }}, que se
                        te devuelve al registrar tu salida.
                    </p>
                </BookingCard>

                <div
                    v-if="hasMainColumn"
                    class="min-w-0 space-y-5 lg:col-start-1 lg:row-start-1"
                >
                    <!-- Cómo pagar: el cobro vigente y el comprobante, en la
                         columna ancha (al costado alargaban la tarjeta del
                         pago y dejaban un hueco junto al pre-registro). -->
                    <BookingCard
                        v-if="hasPayRequest || hasProofBlock"
                        icon="CreditCard"
                        title="Cómo pagar"
                        :subtitle="payCardSubtitle"
                    >
                        <div
                            class="grid grid-cols-1 gap-5"
                            :class="
                                hasPayRequest &&
                                hasProofBlock &&
                                'md:grid-cols-2'
                            "
                        >
                            <!-- Cobro vigente: pagar aquí mismo o repetir las cuentas -->
                            <div v-if="hasPayRequest">
                                <a
                                    v-if="
                                        view.pending_request.method ===
                                            'gateway' &&
                                        view.pending_request.checkout_url
                                    "
                                    :href="view.pending_request.checkout_url"
                                    class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-primary px-4 text-sm font-medium text-white shadow-md shadow-primary/20 transition hover:bg-primary/90"
                                >
                                    <Lucide icon="CreditCard" class="h-4 w-4" />
                                    Pagar
                                    {{ view.pending_request.amount_label }}
                                </a>
                                <div
                                    v-else-if="
                                        view.pending_request.method ===
                                        'transfer'
                                    "
                                >
                                    <div :class="sectionLabel">
                                        Transfiere
                                        {{ view.pending_request.amount_label }}
                                    </div>
                                    <BankAccounts
                                        :accounts="
                                            view.pending_request.bank_accounts
                                        "
                                    />
                                    <p
                                        v-if="
                                            result &&
                                            view.pending_request.covers_group
                                        "
                                        class="mt-2 text-xs text-slate-500"
                                    >
                                        Este cobro cubre todas las habitaciones
                                        del grupo
                                        {{ result.group_code }}.
                                    </p>
                                </div>
                            </div>

                            <!-- Comprobante: se sube aquí mismo -->
                            <div
                                v-if="hasProofBlock"
                                :class="
                                    hasPayRequest &&
                                    'border-t border-slate-200/70 pt-5 md:border-t-0 md:border-l md:pt-0 md:pl-5'
                                "
                            >
                                <div :class="sectionLabel">
                                    ¿Ya transferiste?
                                </div>
                                <div
                                    v-if="
                                        view.proof.received_at &&
                                        !proofReplacing
                                    "
                                    class="flex items-start gap-2.5 rounded-lg bg-info/10 px-3 py-2.5 text-sm text-slate-600"
                                >
                                    <Lucide
                                        icon="FileCheck2"
                                        class="mt-0.5 h-4 w-4 shrink-0 text-info"
                                    />
                                    <div class="min-w-0">
                                        Recibimos tu comprobante el
                                        {{ when(view.proof.received_at) }}: el
                                        hotel lo verifica y tu pago aparece aquí
                                        al aprobarlo.
                                        <button
                                            v-if="view.proof.can_send"
                                            type="button"
                                            class="mt-1 block font-medium text-primary hover:underline"
                                            @click="proofReplacing = true"
                                        >
                                            Enviar otro comprobante
                                        </button>
                                    </div>
                                </div>
                                <template v-if="showProofForm">
                                    <p class="mb-3 text-sm text-slate-500">
                                        Sube la foto o el PDF de tu
                                        transferencia y el hotel la verifica.
                                        Mientras tanto, tu apartado se sostiene.
                                    </p>
                                    <input
                                        ref="proofInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        class="hidden"
                                        @change="pickProof"
                                    />
                                    <div
                                        v-if="proofFile"
                                        class="flex items-center gap-2.5 rounded-lg border border-slate-200/70 px-3 py-2.5 text-sm"
                                    >
                                        <Lucide
                                            icon="Paperclip"
                                            class="h-4 w-4 shrink-0 text-primary"
                                        />
                                        <span
                                            class="min-w-0 flex-1 truncate text-slate-700"
                                            >{{ proofFile.name }}</span
                                        >
                                        <button
                                            type="button"
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                            title="Quitar archivo"
                                            :disabled="proofSending"
                                            @click="clearProof"
                                        >
                                            <Lucide icon="X" class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div class="mt-3 grid grid-cols-1 gap-2">
                                        <Button
                                            v-if="proofFile"
                                            type="button"
                                            variant="primary"
                                            class="w-full"
                                            :class="primaryBtn"
                                            :disabled="proofSending"
                                            @click="sendProof"
                                        >
                                            <Lucide
                                                :icon="
                                                    proofSending
                                                        ? 'LoaderCircle'
                                                        : 'Upload'
                                                "
                                                class="mr-2 h-4 w-4"
                                                :class="
                                                    proofSending &&
                                                    'animate-spin'
                                                "
                                            />
                                            {{
                                                proofSending
                                                    ? 'Enviando…'
                                                    : 'Enviar comprobante'
                                            }}
                                        </Button>
                                        <button
                                            v-else
                                            type="button"
                                            :class="pillBtn"
                                            class="w-full"
                                            @click="proofInput?.click()"
                                        >
                                            <Lucide
                                                icon="ImageUp"
                                                class="h-4 w-4"
                                            />
                                            Elegir foto o PDF
                                        </button>
                                        <button
                                            v-if="
                                                proofReplacing && !proofSending
                                            "
                                            type="button"
                                            class="h-9 text-sm font-medium text-slate-500 hover:text-slate-700"
                                            @click="
                                                proofReplacing = false;
                                                clearProof();
                                            "
                                        >
                                            Mejor no
                                        </button>
                                    </div>
                                </template>
                                <p
                                    v-if="proofError"
                                    class="mt-3 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger"
                                >
                                    <Lucide
                                        icon="CircleAlert"
                                        class="mt-0.5 h-4 w-4 shrink-0"
                                    />
                                    {{ proofError }}
                                </p>
                            </div>
                        </div>
                    </BookingCard>

                    <!-- Las habitaciones del grupo: cada una abre su ficha -->
                    <BookingCard
                        v-if="group && !result"
                        icon="BedDouble"
                        title="Tus habitaciones"
                        subtitle="Abre cada una para dejar su pre-registro o ver su detalle."
                        flush
                    >
                        <ul class="divide-y divide-slate-200">
                            <li
                                v-for="room in group.rooms"
                                :key="room.code"
                                class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:px-6"
                            >
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="text-sm font-medium text-slate-800"
                                            >{{
                                                room.room_type ?? 'Habitación'
                                            }}</span
                                        >
                                        <span
                                            class="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                            :class="
                                                toneBadge[roomTone(room.status)]
                                            "
                                            >{{ room.status_label }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500"
                                    >
                                        <span class="font-mono">{{
                                            room.code
                                        }}</span>
                                        <span
                                            >{{ shortDay(room.starts_at) }} al
                                            {{ shortDay(room.ends_at) }}</span
                                        >
                                        <span
                                            >{{ room.adults }}
                                            {{
                                                room.adults === 1
                                                    ? 'adulto'
                                                    : 'adultos'
                                            }}<template v-if="room.children">
                                                y {{ room.children }}
                                                {{
                                                    room.children === 1
                                                        ? 'niño'
                                                        : 'niños'
                                                }}</template
                                            ></span
                                        >
                                    </div>
                                </div>
                                <div
                                    class="flex items-center justify-between gap-3 sm:justify-end"
                                >
                                    <span
                                        class="text-sm font-medium text-slate-700"
                                        >{{ money(room.total) }}</span
                                    >
                                    <button
                                        type="button"
                                        :class="pillBtn"
                                        :disabled="openingRoom !== null"
                                        @click="openRoom(room.code)"
                                    >
                                        <Lucide
                                            :icon="
                                                openingRoom === room.code
                                                    ? 'LoaderCircle'
                                                    : 'ChevronRight'
                                            "
                                            class="h-4 w-4"
                                            :class="
                                                openingRoom === room.code &&
                                                'animate-spin'
                                            "
                                        />
                                        Ver
                                    </button>
                                </div>
                            </li>
                            <li
                                v-for="exp in group.experiences"
                                :key="exp.code"
                                class="flex items-center gap-3 px-5 py-4 sm:px-6"
                            >
                                <div
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="Compass" class="h-4 w-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="truncate text-sm font-medium text-slate-800"
                                    >
                                        {{ exp.name }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        <template v-if="exp.starts_at"
                                            >{{ when(exp.starts_at) }} ·
                                        </template>
                                        {{ exp.people }}
                                        {{
                                            exp.people === 1
                                                ? 'persona'
                                                : 'personas'
                                        }}
                                        · {{ exp.status_label }}
                                    </div>
                                </div>
                                <span
                                    class="shrink-0 text-sm font-medium text-slate-700"
                                    >{{ money(exp.total) }}</span
                                >
                            </li>
                        </ul>
                        <p
                            v-if="roomError"
                            class="mx-5 mb-4 flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger sm:mx-6"
                        >
                            <Lucide
                                icon="CircleAlert"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
                            {{ roomError }}
                        </p>
                    </BookingCard>

                    <!-- Pre-registro -->
                    <BookingCard
                        v-if="canPreRegister"
                        icon="ClipboardCheck"
                        title="Tu pre-registro"
                        subtitle="Déjanos tus datos desde ahora: en recepción solo confirmas y recibes tu llave."
                    >
                        <template #actions>
                            <button
                                type="button"
                                :class="pillBtn"
                                @click="preOpen = !preOpen"
                            >
                                {{ preOpen ? 'Ocultar' : 'Completar' }}
                                <Lucide
                                    :icon="preOpen ? 'ChevronUp' : 'Pencil'"
                                    class="h-4 w-4"
                                />
                            </button>
                        </template>
                        <div v-if="!preOpen">
                            <p
                                v-if="preSaved"
                                class="mb-3 flex items-center gap-2 text-sm text-success"
                            >
                                <Lucide icon="CircleCheck" class="h-4 w-4" />
                                Listo, guardamos tus datos. Te esperamos.
                            </p>
                            <div
                                class="grid grid-cols-1 gap-2.5 text-sm sm:grid-cols-2"
                            >
                                <div
                                    class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-slate-600"
                                >
                                    <Lucide
                                        icon="Clock"
                                        class="h-4 w-4 shrink-0 text-primary"
                                    />
                                    Llegada aprox.:
                                    <span class="font-medium text-slate-700">{{
                                        preForm.eta || 'sin hora'
                                    }}</span>
                                </div>
                                <div
                                    class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-slate-600"
                                >
                                    <Lucide
                                        icon="Car"
                                        class="h-4 w-4 shrink-0 text-primary"
                                    />
                                    Vehículo:
                                    <span
                                        class="truncate font-medium text-slate-700"
                                        >{{
                                            preForm.vehicle_plate || 'sin placa'
                                        }}</span
                                    >
                                </div>
                            </div>
                        </div>
                        <form
                            v-else
                            class="space-y-5"
                            @submit.prevent="savePreRegistration"
                        >
                            <div>
                                <div :class="sectionLabel">Quién llega</div>
                                <div
                                    class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                                >
                                    <div>
                                        <FormLabel for="pre-name"
                                            >Nombre completo</FormLabel
                                        >
                                        <FormInput
                                            id="pre-name"
                                            v-model="preForm.guest_name"
                                            type="text"
                                            autocomplete="name"
                                            class="h-11 text-sm"
                                            placeholder="Quien llega a recepción"
                                        />
                                    </div>
                                    <div>
                                        <FormLabel for="pre-email"
                                            >Correo</FormLabel
                                        >
                                        <FormInput
                                            id="pre-email"
                                            v-model="preForm.guest_email"
                                            type="email"
                                            autocomplete="email"
                                            class="h-11 text-sm"
                                            placeholder="Para tus confirmaciones"
                                            :disabled="
                                                result.pre_registration
                                                    .has_email
                                            "
                                        />
                                        <p
                                            v-if="
                                                result.pre_registration
                                                    .has_email
                                            "
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            Este correo ya está en tu reserva.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div :class="sectionLabel">Tu llegada</div>
                                <div
                                    class="grid grid-cols-1 gap-4 sm:grid-cols-3"
                                >
                                    <div>
                                        <FormLabel>Hora aproximada</FormLabel>
                                        <FormTime
                                            v-model="preForm.eta"
                                            input-class="h-11 text-sm"
                                        />
                                    </div>
                                    <div>
                                        <FormLabel for="pre-plate"
                                            >Placa</FormLabel
                                        >
                                        <FormInput
                                            id="pre-plate"
                                            v-model="preForm.vehicle_plate"
                                            type="text"
                                            class="h-11 text-sm uppercase"
                                            placeholder="ABC-123"
                                        />
                                    </div>
                                    <div>
                                        <FormLabel for="pre-car"
                                            >Marca y color</FormLabel
                                        >
                                        <FormInput
                                            id="pre-car"
                                            v-model="preForm.vehicle_desc"
                                            type="text"
                                            class="h-11 text-sm"
                                            placeholder="Sedán gris"
                                        />
                                    </div>
                                </div>
                            </div>
                            <div>
                                <FormLabel for="pre-notes"
                                    >¿Algo que debamos saber?</FormLabel
                                >
                                <FormTextarea
                                    id="pre-notes"
                                    v-model="preForm.guest_notes"
                                    :rows="2"
                                    class="text-sm"
                                    placeholder="Llegada tarde, cuna para bebé, alguna alergia…"
                                />
                                <p class="mt-1.5 text-xs text-slate-500">
                                    Todo es opcional: llena lo que sepas.
                                </p>
                            </div>
                            <div
                                class="flex flex-wrap items-center gap-3 border-t border-slate-200/70 pt-4"
                            >
                                <p
                                    v-if="preError"
                                    class="flex-1 text-sm text-danger"
                                >
                                    {{ preError }}
                                </p>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    class="w-full sm:ml-auto sm:w-auto"
                                    :class="primaryBtn"
                                    :disabled="preSaving"
                                >
                                    <Lucide
                                        :icon="
                                            preSaving ? 'LoaderCircle' : 'Save'
                                        "
                                        class="mr-2 h-4 w-4"
                                        :class="preSaving && 'animate-spin'"
                                    />
                                    {{
                                        preSaving
                                            ? 'Guardando…'
                                            : 'Guardar mis datos'
                                    }}
                                </Button>
                            </div>
                        </form>
                    </BookingCard>

                    <!-- Contacto directo con el hotel -->
                    <BookingCard
                        v-if="property.phone || mailHref"
                        icon="Headset"
                        :title="
                            isCancelled || view.status === 'completed'
                                ? '¿Dudas sobre tu reserva?'
                                : '¿Necesitas cambiar algo?'
                        "
                        :subtitle="
                            isCancelled || view.status === 'completed'
                                ? 'Escríbenos o llámanos con tu código a la mano.'
                                : 'Fechas, personas o una petición especial: escríbenos con tu código a la mano.'
                        "
                    >
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <a
                                v-if="property.phone"
                                :href="`tel:${property.phone}`"
                                :class="pillBtn"
                            >
                                <Lucide icon="Phone" class="h-4 w-4" />
                                Llamar al hotel
                            </a>
                            <a
                                v-if="mailHref"
                                :href="mailHref"
                                :class="pillBtn"
                            >
                                <Lucide icon="Mail" class="h-4 w-4" />
                                Escribir un correo
                            </a>
                        </div>
                    </BookingCard>
                </div>
            </div>
        </div>

        <!-- Confirmar cancelación -->
        <BookingModal
            :open="confirmingCancel && !!result"
            title="¿Cancelar tu reserva?"
            :subtitle="result?.code"
            icon="CalendarX2"
            width="md"
            @close="!canceling && (confirmingCancel = false)"
        >
            <div v-if="result" class="space-y-3 text-sm text-slate-600">
                <p>
                    {{
                        result.cancellation_policy ??
                        'La habitación se libera de inmediato y tu código dejará de ser válido.'
                    }}
                </p>
                <p v-if="result.cancellation_policy_text">
                    {{ result.cancellation_policy_text }}
                </p>
                <p
                    v-if="
                        result.paid > 0 &&
                        result.cancel_refund_estimate !== null
                    "
                    class="rounded-lg bg-slate-50 px-3 py-2.5 font-medium text-slate-700"
                >
                    Según la política, al cancelar ahora te corresponde un
                    reembolso de {{ money(result.cancel_refund_estimate) }}. El
                    hotel lo procesará y te contactará.
                </p>
                <p
                    v-if="cancelError"
                    class="flex items-start gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-danger"
                >
                    <Lucide
                        icon="CircleAlert"
                        class="mt-0.5 h-4 w-4 shrink-0"
                    />
                    {{ cancelError }}
                </p>
            </div>
            <template #footer>
                <button
                    type="button"
                    :class="pillBtn"
                    :disabled="canceling"
                    @click="confirmingCancel = false"
                >
                    Conservarla
                </button>
                <Button
                    variant="danger"
                    class="h-11 rounded-full px-5 text-sm"
                    :disabled="canceling"
                    @click="cancelReservation"
                >
                    <Lucide
                        v-if="canceling"
                        icon="LoaderCircle"
                        class="mr-2 h-4 w-4 animate-spin"
                    />
                    {{ canceling ? 'Cancelando…' : 'Sí, cancelar' }}
                </Button>
            </template>
        </BookingModal>

        <template #links>
            <a
                href="/reservar"
                class="inline-flex h-10 items-center gap-2 rounded-full border border-current/25 bg-current/10 px-4 text-sm font-medium transition hover:bg-current/20"
            >
                <Lucide icon="CalendarPlus" class="h-4 w-4" />
                Hacer una reserva nueva
            </a>
        </template>
    </PublicShell>
</template>
