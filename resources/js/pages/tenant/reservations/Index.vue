<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog, Menu, Slideover } from '@/components/Base/Headless';
import Lucide, { type Icon } from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ReservationRow {
    id: number;
    code: string;
    guest_id: number | null;
    guest_name: string | null;
    guest_phone: string | null;
    guest_email: string | null;
    num_people: number;
    adults: number;
    children: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    eta: string | null;
    room: string | null;
    room_id: number | null;
    room_type: string | null;
    rate_plan: string | null;
    rate_plan_id: number;
    starts_at: string;
    starts_at_input: string;
    ends_at: string;
    ends_at_input: string;
    status: string;
    status_label: string;
    hold_expires_at: string | null;
    total_amount: string;
    starts_today: boolean;
    source_channel: string;
    notes: string | null;
    guest_notes: string | null;
    cancellation_reason: string | null;
    deposit_amount: string;
    payment_status: string;
    payment_status_label: string;
    payment_due_at: string | null;
    payment_overdue: boolean;
    paid_total: number;
    pending_balance: number;
    updated_at: string | null;
    timeline: {
        id: string;
        message: string;
        by: string | null;
        at: string | null;
    }[];
}

interface StayRow {
    id: number;
    room: string | null;
    guest_name: string | null;
    num_people: number;
    vehicle_plate: string | null;
    vehicle_desc: string | null;
    rate_plan: string | null;
    check_in_at: string;
    planned_end_at: string;
    overdue: boolean;
    amount: string;
    channel: string;
}

interface RatePlanOption {
    id: number;
    name: string;
    type: string;
    room_type: string;
    price: string;
    duration_minutes: number | null;
    duration_unit: string | null;
    duration_value: number | null;
    duration_label: string;
    deposit_percent: string | null;
    min_advance_label: string | null;
}

const props = defineProps<{
    property: { id: number; name: string };
    reservations: ReservationRow[];
    history: ReservationRow[];
    stays: StayRow[];
    ratePlans: RatePlanOption[];
    canManage: boolean;
    focusReservationId: number | null;
    prefill: {
        intent: 'walkin' | 'reserve' | null;
        room: {
            id: number;
            number: string;
            room_type: string | null;
            rate_plan_id: number | null;
        } | null;
        guest: {
            id: number;
            full_name: string | null;
            phone: string | null;
            visits: number;
            is_blacklisted: boolean;
            blacklist_reason: string | null;
            vehicle: { plate: string | null; desc: string | null } | null;
        } | null;
    };
}>();

const toast = useToasts();
const money = (n: number) => '$' + new Intl.NumberFormat('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n || 0);
const tab = ref<'reservas' | 'encasa' | 'historial'>('reservas');
const selectedReservationId = ref<number | null>(null);
const prefillConsumed = ref(false);
const focusReservationConsumed = ref(false);
const editingReservationId = ref<number | null>(null);
const currentRoomPreset = ref<typeof props.prefill.room>(null);

function reload() {
    router.reload({ only: ['reservations', 'history', 'stays'] });
}

// ── Acciones de estado con confirmación en modal ──
type ConfirmKind = 'confirm' | 'check_in' | 'check_out' | 'no_show' | 'cancel';
const confirmAction = ref<
    | { kind: Exclude<ConfirmKind, 'check_out'>; reservation: ReservationRow; stay?: never }
    | { kind: 'check_out'; stay: StayRow; reservation?: never }
    | null
>(null);
const confirmReason = ref('');
const confirmBusy = ref(false);

// Copys por tipo de acción (título, subtítulo, icono, variante, CTA, motivo).
const confirmMeta: Record<
    ConfirmKind,
    { title: string; icon: Icon; tone: string; variant: any; cta: string; reason: boolean }
> = {
    confirm: { title: 'Confirmar reserva', icon: 'CircleCheck', tone: 'bg-primary/10 text-primary', variant: 'primary', cta: 'Confirmar reserva', reason: false },
    check_in: { title: 'Registrar check-in', icon: 'LogIn', tone: 'bg-success/10 text-success', variant: 'success', cta: 'Registrar check-in', reason: false },
    check_out: { title: 'Registrar check-out', icon: 'LogOut', tone: 'bg-pending/10 text-pending', variant: 'primary', cta: 'Registrar check-out', reason: false },
    no_show: { title: 'Marcar no-show', icon: 'UserX', tone: 'bg-warning/10 text-warning', variant: 'warning', cta: 'Marcar no-show', reason: true },
    cancel: { title: 'Cancelar reserva', icon: 'Ban', tone: 'bg-danger/10 text-danger', variant: 'danger', cta: 'Cancelar reserva', reason: true },
};

const askAction = (kind: Exclude<ConfirmKind, 'check_out'>, r: ReservationRow) => {
    confirmReason.value = '';
    confirmAction.value = { kind, reservation: r };
};
const askConfirm = (r: ReservationRow) => askAction('confirm', r);
const askCheckIn = (r: ReservationRow) => askAction('check_in', r);
const askNoShow = (r: ReservationRow) => askAction('no_show', r);
const askCancel = (r: ReservationRow) => askAction('cancel', r);
// Cuenta final (folio) de la estancia al hacer check-out.
interface FolioData {
    lodging_total: number;
    lodging_paid: number;
    lodging_pending: number;
    consumption_pending: number;
    grand_pending: number;
    orders: { id: number; total: number; created_at: string; summary: string }[];
}
const folio = ref<FolioData | null>(null);
const folioLoading = ref(false);
const folioMethod = ref<'cash' | 'card' | 'transfer'>('cash');
const folioForce = ref(false);
const folioMethods = [
    { key: 'cash', label: 'Efectivo', icon: 'Banknote' },
    { key: 'card', label: 'Tarjeta', icon: 'CreditCard' },
    { key: 'transfer', label: 'Transfer.', icon: 'ArrowLeftRight' },
] as const;

const askCheckOut = async (s: StayRow) => {
    confirmReason.value = '';
    folio.value = null;
    folioMethod.value = 'cash';
    folioForce.value = false;
    confirmAction.value = { kind: 'check_out', stay: s };
    folioLoading.value = true;
    try {
        const { data } = await axios.get(`/api/stays/${s.id}/folio`);
        folio.value = data;
    } catch {
        folio.value = null;
    } finally {
        folioLoading.value = false;
    }
};

// Subtítulo del modal según sea reserva o estancia.
const confirmSubtitle = computed(() => {
    const action = confirmAction.value;
    if (!action) return '';
    if (action.kind === 'check_out') {
        return `Hab. ${action.stay.room ?? '—'} · ${action.stay.guest_name ?? 'Anónimo'} · entró ${action.stay.check_in_at}`;
    }
    return `${action.reservation.code} · ${action.reservation.guest_name ?? 'Anónimo'} · Hab. ${action.reservation.room ?? 'por asignar'}`;
});

async function submitConfirmAction() {
    if (!confirmAction.value) return;
    const action = confirmAction.value;
    confirmBusy.value = true;
    try {
        if (action.kind === 'check_out') {
            const pending = folio.value?.grand_pending ?? 0;
            await axios.patch(`/api/stays/${action.stay.id}/check-out`, {
                payment_method: pending > 0 && !folioForce.value ? folioMethod.value : null,
                force: pending > 0 && folioForce.value,
            });
            toast.success(
                'Check-out realizado',
                pending > 0 && !folioForce.value
                    ? `Se cobró la cuenta final y la habitación ${action.stay.room ?? '—'} pasó a sucia.`
                    : `La habitación ${action.stay.room ?? '—'} pasó a sucia; limpieza puede entrar.`,
            );
        } else if (action.kind === 'confirm') {
            await axios.patch(`/api/reservations/${action.reservation.id}/confirm`);
            toast.success('Reserva confirmada', `${action.reservation.code} · ${action.reservation.guest_name ?? 'Anónimo'}`);
        } else if (action.kind === 'check_in') {
            await axios.patch(`/api/reservations/${action.reservation.id}/check-in`);
            toast.success('Check-in realizado', `${action.reservation.guest_name ?? 'Anónimo'} entró; la hab. ${action.reservation.room ?? '—'} quedó en uso.`);
        } else {
            await axios.patch(`/api/reservations/${action.reservation.id}/cancel`, {
                no_show: action.kind === 'no_show',
                reason: confirmReason.value || null,
            });
            toast.success(
                action.kind === 'no_show' ? 'No-show registrado' : 'Reserva cancelada',
                `${action.reservation.code} pasó al Historial y la habitación quedó libre.`,
            );
        }
        confirmAction.value = null;
        selectedReservationId.value = null;
        reload();
    } catch (error: any) {
        toast.error(
            'No se pudo completar la acción',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        confirmBusy.value = false;
    }
}

// ── Nueva reserva ─────────────────────────────────────────────
const showCreate = ref(false);
const walkIn = ref(false);
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const modalError = ref<string | null>(null);

const form = reactive({
    rate_plan_id: '' as string | number,
    starts_at: '',
    ends_at: '',
    room_id: '' as string | number,
    guest_id: null as number | null,
    guest_name: '',
    guest_phone: '',
    adults: 1,
    children: 0,
    vehicle_plate: '',
    vehicle_desc: '',
    eta: '',
    confirmed: true,
    notes: '',
    guest_notes: '',
});

// ── Autocompletado de huésped (CRM) ──────────────────────────
interface GuestHit {
    id: number;
    full_name: string | null;
    phone: string | null;
    visits: number;
    is_blacklisted: boolean;
    blacklist_reason: string | null;
}

const guestQuery = ref('');
const guestHits = ref<GuestHit[]>([]);
const selectedGuest = ref<GuestHit | null>(null);
let guestTimer: ReturnType<typeof setTimeout> | null = null;

function onGuestQuery() {
    selectedGuest.value = null;
    form.guest_id = null;
    if (guestTimer) clearTimeout(guestTimer);
    guestTimer = setTimeout(async () => {
        if (guestQuery.value.trim().length < 2) {
            guestHits.value = [];
            return;
        }
        const { data } = await axios.get('/api/guests/search', {
            params: { q: guestQuery.value.trim() },
        });
        guestHits.value = data;
    }, 300);
}

function pickGuest(hit: GuestHit) {
    selectedGuest.value = hit;
    form.guest_id = hit.id;
    form.guest_name = hit.full_name ?? '';
    form.guest_phone = hit.phone ?? '';
    guestQuery.value = '';
    guestHits.value = [];
}

function clearGuest() {
    selectedGuest.value = null;
    form.guest_id = null;
    form.guest_name = '';
    form.guest_phone = '';
}

function resetFormErrors() {
    availability.value = null;
    modalError.value = null;
    guestQuery.value = '';
    guestHits.value = [];
    Object.keys(errors).forEach((k) => delete errors[k]);
}

const selectedPlan = computed(() =>
    props.ratePlans.find((p) => p.id === Number(form.rate_plan_id)),
);

// ── Fechas y precio en automático según la tarifa ─────────────
const UNIT_MINUTES: Record<string, number> = { minute: 1, hour: 60, day: 1440, week: 10080 };

const pad2 = (n: number) => String(n).padStart(2, '0');
const toLocalInput = (d: Date) =>
    `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
const fromLocalInput = (value: string): Date | null => {
    if (!value) return null;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d;
};

// Espejo de RatePlan::suggestedEnd(): noche → día siguiente 12:00; resto → inicio + periodo.
function suggestedEndFor(plan: RatePlanOption, start: Date): Date {
    const end = new Date(start);
    if (plan.type === 'night') {
        end.setDate(end.getDate() + 1);
        end.setHours(12, 0, 0, 0);
        return end;
    }
    const value = Math.max(1, plan.duration_value ?? plan.duration_minutes ?? 60);
    const unit = plan.duration_unit ?? 'minute';
    if (unit === 'month') {
        end.setMonth(end.getMonth() + value);
        return end;
    }
    end.setTime(end.getTime() + value * (UNIT_MINUTES[unit] ?? 1) * 60000);
    return end;
}

// Espejo de RatePlan::unitsFor(): noches por día calendario, meses por
// calendario, el resto en periodos de minutos (redondeo hacia arriba).
function unitsForEstimate(plan: RatePlanOption, start: Date, end: Date): number {
    if (plan.type === 'night') {
        const a = new Date(start);
        a.setHours(0, 0, 0, 0);
        const b = new Date(end);
        b.setHours(0, 0, 0, 0);
        return Math.max(1, Math.round((b.getTime() - a.getTime()) / 86400000));
    }
    const value = Math.max(1, plan.duration_value ?? plan.duration_minutes ?? 60);
    const unit = plan.duration_unit ?? 'minute';
    if (unit === 'month') {
        let units = 0;
        const cursor = new Date(start);
        while (cursor < end) {
            cursor.setMonth(cursor.getMonth() + value);
            units++;
        }
        return Math.max(1, units);
    }
    const periodMinutes = value * (UNIT_MINUTES[unit] ?? 1);
    return Math.max(1, Math.ceil((end.getTime() - start.getTime()) / 60000 / periodMinutes));
}

// La salida se rellena sola mientras el usuario no la edite a mano.
const endsAutoFilled = ref(false);

function autoFillEnd() {
    const plan = selectedPlan.value;
    const start = fromLocalInput(form.starts_at);
    if (!plan || !start) return;
    if (form.ends_at && !endsAutoFilled.value) return;
    form.ends_at = toLocalInput(suggestedEndFor(plan, start));
    endsAutoFilled.value = true;
}

watch(
    () => [form.rate_plan_id, form.starts_at],
    () => {
        if (showCreate.value) autoFillEnd();
    },
);

// Estimación en vivo; el total definitivo lo recalcula el servidor al
// guardar (puede variar por modificador de precio de la habitación).
const estimate = computed(() => {
    const plan = selectedPlan.value;
    if (!plan) return null;
    const start = walkIn.value && !form.starts_at ? new Date() : fromLocalInput(form.starts_at);
    if (!start) return null;
    const end = fromLocalInput(form.ends_at) ?? suggestedEndFor(plan, start);
    if (end <= start) return null;
    const units = unitsForEstimate(plan, start, end);
    const unitPrice = Number(plan.price);
    if (!Number.isFinite(unitPrice)) return null;
    const total = Math.round(units * unitPrice * 100) / 100;
    const depositPct = plan.deposit_percent ? Number(plan.deposit_percent) : null;
    return {
        units,
        breakdown:
            plan.type === 'night'
                ? `${units} ${units === 1 ? 'noche' : 'noches'}`
                : units === 1
                  ? plan.duration_label
                  : `${units} × ${plan.duration_label}`,
        unitPrice,
        total,
        depositPct,
        deposit: depositPct ? Math.round(total * depositPct) / 100 : null,
    };
});

interface AvailabilityResult {
    total: number;
    units: number;
    duration_label: string;
    advance_error: string | null;
    rooms: { id: number; number: string; status: string }[];
}
const availability = ref<AvailabilityResult | null>(null);
const searching = ref(false);

function openCreate(
    asWalkIn: boolean,
    roomPreset: typeof props.prefill.room = null,
) {
    editingReservationId.value = null;
    currentRoomPreset.value = roomPreset;
    walkIn.value = asWalkIn;
    form.rate_plan_id =
        roomPreset?.rate_plan_id ?? props.ratePlans[0]?.id ?? '';
    form.starts_at = '';
    form.ends_at = '';
    endsAutoFilled.value = true;
    // Llegada sugerida: próxima media hora; la salida se calcula sola
    // según la tarifa (el usuario puede ajustar ambas).
    if (!asWalkIn) {
        const start = new Date();
        start.setMinutes(start.getMinutes() + (30 - (start.getMinutes() % 30)), 0, 0);
        form.starts_at = toLocalInput(start);
        autoFillEnd();
    }
    form.room_id = roomPreset?.id ?? '';
    form.guest_id = null;
    form.guest_name = '';
    form.guest_phone = '';
    form.adults = 1;
    form.children = 0;
    form.vehicle_plate = '';
    form.vehicle_desc = '';
    form.eta = '';
    form.confirmed = true;
    form.notes = '';
    form.guest_notes = '';
    selectedGuest.value = null;
    resetFormErrors();
    showCreate.value = true;
}

function openEdit(reservation: ReservationRow) {
    editingReservationId.value = reservation.id;
    selectedReservationId.value = null;
    currentRoomPreset.value =
        reservation.room_id && reservation.room
            ? {
                  id: reservation.room_id,
                  number: reservation.room,
                  room_type: reservation.room_type,
                  rate_plan_id: reservation.rate_plan_id,
              }
            : null;
    walkIn.value = false;
    endsAutoFilled.value = false; // fechas reales de la reserva: no pisarlas
    form.rate_plan_id = reservation.rate_plan_id;
    form.starts_at = reservation.starts_at_input;
    form.ends_at = reservation.ends_at_input;
    form.room_id = reservation.room_id ?? '';
    form.guest_id = reservation.guest_id;
    form.guest_name = reservation.guest_name ?? '';
    form.guest_phone = reservation.guest_phone ?? '';
    form.adults = reservation.adults ?? reservation.num_people;
    form.children = reservation.children ?? 0;
    form.vehicle_plate = reservation.vehicle_plate ?? '';
    form.vehicle_desc = reservation.vehicle_desc ?? '';
    form.eta = reservation.eta ?? '';
    form.confirmed = reservation.status === 'confirmed';
    form.notes = reservation.notes ?? '';
    form.guest_notes = reservation.guest_notes ?? '';
    selectedGuest.value = null;
    resetFormErrors();
    showCreate.value = true;
}

// El slideover de detalle sirve tanto para próximas como para historial.
const selectedReservation = computed(
    () =>
        [...props.reservations, ...props.history].find(
            (reservation) => reservation.id === selectedReservationId.value,
        ) ?? null,
);

const selectedIsActionable = computed(
    () =>
        selectedReservation.value !== null &&
        ['pending', 'confirmed'].includes(selectedReservation.value.status),
);

const roomOptions = computed(() => {
    const items = new Map<
        number,
        { id: number; number: string; status?: string; hint?: string }
    >();

    currentRoomPreset.value &&
        items.set(currentRoomPreset.value.id, {
            id: currentRoomPreset.value.id,
            number: currentRoomPreset.value.number,
            hint: currentRoomPreset.value.room_type ?? undefined,
        });

    availability.value?.rooms.forEach((room) => {
        items.set(room.id, room);
    });

    return Array.from(items.values());
});

watch(
    () => props.prefill,
    (prefill) => {
        if (!props.canManage || !prefill.intent || prefillConsumed.value) {
            return;
        }

        prefillConsumed.value = true;
        openCreate(prefill.intent === 'walkin', prefill.room);

        // Huésped precargado desde su ficha: fija el huésped del CRM y,
        // si tiene vehículo registrado, autollena placa y descripción.
        if (prefill.guest) {
            pickGuest({
                id: prefill.guest.id,
                full_name: prefill.guest.full_name,
                phone: prefill.guest.phone,
                visits: prefill.guest.visits,
                is_blacklisted: prefill.guest.is_blacklisted,
                blacklist_reason: prefill.guest.blacklist_reason,
            });
            if (prefill.guest.vehicle) {
                form.vehicle_plate = prefill.guest.vehicle.plate ?? '';
                form.vehicle_desc = prefill.guest.vehicle.desc ?? '';
            }
        }
    },
    { immediate: true },
);

watch(
    () => [props.focusReservationId, props.reservations, props.history] as const,
    ([reservationId, reservations, history]) => {
        if (!reservationId || focusReservationConsumed.value) {
            return;
        }

        const exists = [...reservations, ...history].some(
            (reservation) => reservation.id === reservationId,
        );

        if (!exists) {
            return;
        }

        focusReservationConsumed.value = true;
        selectedReservationId.value = reservationId;
    },
    { immediate: true, deep: true },
);

async function searchAvailability() {
    if (!form.rate_plan_id || !showCreate.value) return;
    searching.value = true;
    modalError.value = null;
    availability.value = null;
    try {
        const { data } = await axios.get('/api/availability', {
            params: {
                rate_plan_id: form.rate_plan_id,
                starts_at: walkIn.value
                    ? new Date().toISOString()
                    : form.starts_at,
                ends_at: form.ends_at || undefined,
                ignore_reservation_id: editingReservationId.value || undefined,
            },
        });
        availability.value = data;
        // Mantiene la habitación elegida si sigue disponible (clave al
        // editar); si no, propone la primera libre.
        const keepCurrent = data.rooms.some(
            (room: { id: number }) => room.id === Number(form.room_id),
        );
        if (!keepCurrent) {
            form.room_id = data.rooms[0]?.id ?? '';
        }
        if (data.advance_error && !walkIn.value) {
            modalError.value = data.advance_error;
        }
    } catch (error: any) {
        modalError.value =
            error.response?.data?.message ??
            'No se pudo consultar disponibilidad.';
    } finally {
        searching.value = false;
    }
}

// Disponibilidad automática: al tener tarifa + llegada (o walk-in), se
// consulta sola con un pequeño debounce; nada de reservar al aire.
let availabilityTimer: ReturnType<typeof setTimeout> | null = null;
watch(
    () => [showCreate.value, form.rate_plan_id, form.starts_at, form.ends_at] as const,
    () => {
        if (availabilityTimer) clearTimeout(availabilityTimer);
        if (!showCreate.value || !form.rate_plan_id) return;
        if (!walkIn.value && !form.starts_at) return;
        availabilityTimer = setTimeout(searchAvailability, 400);
    },
    { immediate: false },
);

async function submitCreate() {
    saving.value = true;
    modalError.value = null;
    Object.keys(errors).forEach((k) => delete errors[k]);

    try {
        const reservationPayload = {
            rate_plan_id: form.rate_plan_id,
            room_id: form.room_id || undefined,
            starts_at: form.starts_at,
            ends_at: form.ends_at || undefined,
            guest_id: form.guest_id ?? undefined,
            guest_name: form.guest_name || undefined,
            guest_phone: form.guest_phone || undefined,
            adults: form.adults,
            children: form.children,
            vehicle_plate: form.vehicle_plate || null,
            vehicle_desc: form.vehicle_desc || null,
            eta: form.eta || null,
            notes: form.notes || undefined,
            guest_notes: form.guest_notes || undefined,
        };

        if (walkIn.value) {
            await axios.post('/api/stays', {
                room_id: form.room_id,
                rate_plan_id: form.rate_plan_id,
                planned_end_at: form.ends_at || undefined,
                guest_id: form.guest_id ?? undefined,
                guest_name: form.guest_name || undefined,
                guest_phone: form.guest_phone || undefined,
                num_people: form.adults + form.children,
                vehicle_plate: form.vehicle_plate || null,
                vehicle_desc: form.vehicle_desc || null,
                notes: form.notes || undefined,
            });
            toast.success('Walk-in registrado', 'La habitación quedó ocupada.');
        } else if (editingReservationId.value) {
            await axios.patch(
                `/api/reservations/${editingReservationId.value}`,
                reservationPayload,
            );
            toast.success('Reserva actualizada');
        } else {
            await axios.post('/api/reservations', {
                ...reservationPayload,
                confirmed: form.confirmed,
            });
            toast.success(
                form.confirmed ? 'Reserva creada' : 'Hold creado',
                form.confirmed
                    ? undefined
                    : 'Aparta la habitación 30 minutos mientras se confirma.',
            );
        }
        showCreate.value = false;
        editingReservationId.value = null;
        reload();
    } catch (error: any) {
        const data = error.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) => (errors[key] = (msgs as string[])[0]),
            );
        } else {
            modalError.value =
                data?.message ??
                (editingReservationId.value
                    ? 'No se pudo guardar.'
                    : 'No se pudo crear.');
        }
    } finally {
        saving.value = false;
    }
}

// Estado con color + icono lucide consistente en tabla, historial y detalle.
const statusMeta: Record<string, { class: string; icon: Icon }> = {
    pending: { class: 'bg-warning/10 text-warning', icon: 'Clock' },
    confirmed: { class: 'bg-primary/10 text-primary', icon: 'CircleCheck' },
    checked_in: { class: 'bg-success/10 text-success', icon: 'LogIn' },
    completed: { class: 'bg-slate-100 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300', icon: 'CircleCheckBig' },
    cancelled: { class: 'bg-danger/10 text-danger', icon: 'Ban' },
    no_show: { class: 'bg-pending/10 text-pending', icon: 'UserX' },
};

const statusFor = (status: string) =>
    statusMeta[status] ?? { class: 'bg-slate-100 text-slate-600', icon: 'CircleHelp' as Icon };

// ── Pagos (spec §7.5) ────────────────────────────────────────
function paymentBadge(r: ReservationRow): string {
    if (r.payment_overdue) return 'bg-danger/10 text-danger';
    if (r.payment_status === 'paid') return 'bg-success/10 text-success';
    if (r.payment_status === 'deposit_paid') return 'bg-info/10 text-info';
    return 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';
}

const payingReservation = ref<ReservationRow | null>(null);
const paymentForm = reactive({
    amount: '' as string | number,
    method: 'cash',
    reference: '',
    notes: '',
});
const paymentError = ref<string | null>(null);
const payingBusy = ref(false);

function openPayment(r: ReservationRow) {
    payingReservation.value = r;
    // Default inteligente: primero el anticipo pendiente, luego el resto.
    const deposit = Number(r.deposit_amount);
    const suggested =
        deposit > 0 && r.paid_total < deposit
            ? Math.min(deposit - r.paid_total, r.pending_balance)
            : r.pending_balance;
    paymentForm.amount = Number(suggested.toFixed(2));
    paymentForm.method = 'cash';
    paymentForm.reference = '';
    paymentForm.notes = '';
    paymentError.value = null;
}

async function submitPayment() {
    if (!payingReservation.value) return;
    payingBusy.value = true;
    paymentError.value = null;
    try {
        await axios.post(`/api/reservations/${payingReservation.value.id}/payments`, {
            amount: paymentForm.amount,
            method: paymentForm.method,
            reference: paymentForm.reference || null,
            notes: paymentForm.notes || null,
        });
        payingReservation.value = null;
        reload();
        toast.success('Pago registrado');
    } catch (error: any) {
        paymentError.value = error.response?.data?.message ?? 'No se pudo registrar el pago.';
    } finally {
        payingBusy.value = false;
    }
}

const channelLabel: Record<string, string> = {
    front_desk: 'Mostrador',
    phone: 'Teléfono',
    web: 'Web',
    whatsapp: 'WhatsApp',
    walk_in: 'Walk-in',
    agent: 'Asistente IA',
};

const channelBadge: Record<string, string> = {
    front_desk: 'bg-slate-100 text-slate-600 dark:bg-darkmode-400 dark:text-slate-300',
    phone: 'bg-warning/10 text-warning',
    web: 'bg-info/10 text-info',
    whatsapp: 'bg-success/10 text-success',
    walk_in: 'bg-danger/10 text-danger',
    agent: 'bg-primary/10 text-primary',
};

const channelIcon: Record<string, Icon> = {
    front_desk: 'ConciergeBell',
    phone: 'Phone',
    web: 'Globe',
    whatsapp: 'MessageCircle',
    walk_in: 'Footprints',
    agent: 'Bot',
};

const paxLabel = (r: ReservationRow) =>
    r.children > 0 ? `${r.adults} adulto(s) · ${r.children} niño(s)` : `${r.adults} adulto(s)`;

const modalTitle = computed(() => {
    if (walkIn.value) {
        return 'Walk-in (ocupar ahora)';
    }

    return editingReservationId.value ? 'Editar reserva' : 'Nueva reserva';
});
</script>

<template>
    <RazeLayout title="Reservas">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">Reservas</h1>
                    <p class="text-sm text-slate-500">{{ property.name }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        as="a"
                        :href="route('tenant.reservations.reports')"
                        variant="outline-secondary"
                        class="rounded-[0.5rem] bg-white"
                    >
                        <Lucide icon="ChartColumn" class="mr-2 h-4 w-4 stroke-[1.3]" /> Reportes
                    </Button>
                    <template v-if="canManage">
                        <Button
                            variant="outline-primary"
                            :disabled="!ratePlans.length"
                            @click="openCreate(true)"
                        >
                            <Lucide icon="Zap" class="mr-2 h-4 w-4" /> Walk-in
                        </Button>
                        <Button
                            variant="primary"
                            :disabled="!ratePlans.length"
                            @click="openCreate(false)"
                        >
                            <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Nueva
                            reserva
                        </Button>
                    </template>
                </div>
            </div>

            <div
                v-if="!ratePlans.length"
                class="box mt-5 border-l-4 border-l-warning p-5"
            >
                <p class="text-sm">
                    Define al menos una tarifa en "Zonas y tipos" para poder
                    reservar.
                </p>
            </div>

            <!-- Tabs -->
            <div
                class="mt-5 inline-flex flex-wrap gap-1 rounded-[0.7rem] border border-slate-200/80 bg-slate-100/70 p-1 dark:border-darkmode-400 dark:bg-darkmode-700"
            >
                <button
                    v-for="t in [
                        { key: 'reservas', label: 'Próximas', icon: 'CalendarDays', count: reservations.length },
                        { key: 'encasa', label: 'En uso', icon: 'DoorOpen', count: stays.length },
                        { key: 'historial', label: 'Historial', icon: 'History', count: history.length },
                    ]"
                    :key="t.key"
                    class="flex items-center gap-2 rounded-[0.5rem] px-4 py-2 text-sm font-medium transition"
                    :class="
                        tab === t.key
                            ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                            : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                    "
                    @click="tab = t.key as typeof tab"
                >
                    <Lucide :icon="t.icon as Icon" class="h-4 w-4" />
                    {{ t.label }}
                    <span
                        class="rounded-full px-1.5 py-0.5 text-xs leading-none"
                        :class="
                            tab === t.key
                                ? 'bg-primary/10 text-primary'
                                : 'bg-slate-200/80 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        {{ t.count }}
                    </span>
                </button>
            </div>

            <!-- Próximas -->
            <div v-show="tab === 'reservas'" class="box mt-5">
                <div class="overflow-auto p-5 lg:overflow-visible">
                    <Table v-if="reservations.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th>Llegada → Salida</Table.Th>
                                <Table.Th>Total</Table.Th>
                                <Table.Th>Estado</Table.Th>
                                <Table.Th v-if="canManage" class="text-right"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="r in reservations" :key="r.id">
                                <Table.Td>
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600"
                                        >
                                            {{ r.code }}
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                            :class="
                                                channelBadge[
                                                    r.source_channel
                                                ] ??
                                                'bg-slate-100 text-slate-600'
                                            "
                                        >
                                            <Lucide
                                                :icon="channelIcon[r.source_channel] ?? 'Tag'"
                                                class="h-3 w-3"
                                            />
                                            {{
                                                channelLabel[
                                                    r.source_channel
                                                ] ?? r.source_channel
                                            }}
                                        </span>
                                    </div>
                                    <div class="mt-1 font-medium">
                                        {{ r.guest_name ?? 'Anónimo' }}
                                    </div>
                                    <div class="text-xs text-slate-500">
                                        {{ paxLabel(r) }} · {{ r.rate_plan }}
                                    </div>
                                    <div
                                        v-if="r.eta || r.vehicle_plate"
                                        class="mt-1 flex flex-wrap gap-2 text-xs text-slate-500"
                                    >
                                        <span
                                            v-if="r.eta"
                                            class="inline-flex items-center gap-1"
                                            title="Hora estimada de llegada"
                                        >
                                            <Lucide icon="Clock" class="h-3 w-3" />
                                            ETA {{ r.eta }}
                                        </span>
                                        <span
                                            v-if="r.vehicle_plate"
                                            class="inline-flex items-center gap-1"
                                            :title="r.vehicle_desc ?? 'Vehículo'"
                                        >
                                            <Lucide icon="Car" class="h-3 w-3" />
                                            {{ r.vehicle_plate }}
                                        </span>
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <span class="font-medium">{{
                                        r.room ?? '—'
                                    }}</span>
                                    <span
                                        class="block text-xs text-slate-500"
                                        >{{ r.room_type }}</span
                                    >
                                </Table.Td>
                                <Table.Td class="text-sm">
                                    {{ r.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ r.ends_at }}
                                    <span
                                        v-if="r.starts_today"
                                        class="ml-1 rounded-full bg-success/10 px-1.5 text-xs text-success"
                                        >hoy</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    ${{ r.total_amount }}
                                    <span
                                        class="mt-1 block w-fit rounded-full px-1.5 py-0.5 text-xs"
                                        :class="paymentBadge(r)"
                                    >
                                        {{ r.payment_overdue ? 'Pago vencido' : r.payment_status_label }}
                                    </span>
                                    <span
                                        v-if="r.payment_due_at && r.payment_status !== 'paid'"
                                        class="block text-xs text-slate-400"
                                        >liquidar antes de {{ r.payment_due_at }}</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                        :class="statusFor(r.status).class"
                                    >
                                        <Lucide
                                            :icon="statusFor(r.status).icon"
                                            class="h-3 w-3"
                                        />
                                        {{ r.status_label }}
                                    </span>
                                    <span
                                        v-if="r.hold_expires_at"
                                        class="block text-xs text-slate-400"
                                        >hold hasta
                                        {{ r.hold_expires_at }}</span
                                    >
                                </Table.Td>
                                <Table.Td v-if="canManage">
                                    <div class="flex items-center justify-end gap-2">
                                        <!-- Acción principal contextual -->
                                        <Button
                                            v-if="r.status === 'pending'"
                                            variant="primary"
                                            size="sm"
                                            class="whitespace-nowrap rounded-[0.5rem]"
                                            @click="askConfirm(r)"
                                        >
                                            <Lucide icon="CircleCheck" class="mr-1.5 h-4 w-4" /> Confirmar
                                        </Button>
                                        <Button
                                            v-else
                                            variant="outline-success"
                                            size="sm"
                                            class="whitespace-nowrap rounded-[0.5rem]"
                                            @click="askCheckIn(r)"
                                        >
                                            <Lucide icon="LogIn" class="mr-1.5 h-4 w-4" /> Check-in
                                        </Button>

                                        <!-- Menú de acciones secundarias -->
                                        <Menu>
                                            <Menu.Button
                                                class="flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:hover:bg-darkmode-400"
                                            >
                                                <Lucide icon="MoreVertical" class="h-4 w-4" />
                                            </Menu.Button>
                                            <Menu.Items class="w-52">
                                                <Menu.Item as="button" type="button" @click="selectedReservationId = r.id">
                                                    <Lucide icon="Eye" class="mr-2 h-4 w-4" /> Ver detalle
                                                </Menu.Item>
                                                <Menu.Item as="button" type="button" @click="openEdit(r)">
                                                    <Lucide icon="Pencil" class="mr-2 h-4 w-4" /> Editar reserva
                                                </Menu.Item>
                                                <Menu.Item
                                                    v-if="r.pending_balance > 0"
                                                    as="button"
                                                    type="button"
                                                    class="text-pending"
                                                    @click="openPayment(r)"
                                                >
                                                    <Lucide icon="Banknote" class="mr-2 h-4 w-4" /> Registrar pago
                                                </Menu.Item>
                                                <Menu.Item
                                                    v-if="r.status === 'pending'"
                                                    as="button"
                                                    type="button"
                                                    class="text-success"
                                                    @click="askCheckIn(r)"
                                                >
                                                    <Lucide icon="LogIn" class="mr-2 h-4 w-4" /> Registrar check-in
                                                </Menu.Item>
                                                <Menu.Divider />
                                                <Menu.Item as="button" type="button" class="text-warning" @click="askNoShow(r)">
                                                    <Lucide icon="UserX" class="mr-2 h-4 w-4" /> Marcar no-show
                                                </Menu.Item>
                                                <Menu.Item as="button" type="button" class="text-danger" @click="askCancel(r)">
                                                    <Lucide icon="Ban" class="mr-2 h-4 w-4" /> Cancelar reserva
                                                </Menu.Item>
                                            </Menu.Items>
                                        </Menu>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="py-8 text-center text-slate-500">
                        Sin reservas próximas.
                    </div>
                </div>
            </div>

            <!-- En uso (estancias activas) -->
            <div v-show="tab === 'encasa'" class="box mt-5">
                <div class="overflow-auto p-5 lg:overflow-visible">
                    <Table v-if="stays.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Entrada</Table.Th>
                                <Table.Th>Salida prevista</Table.Th>
                                <Table.Th>Monto</Table.Th>
                                <Table.Th v-if="canManage" class="text-right"
                                    >Acciones</Table.Th
                                >
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="s in stays" :key="s.id">
                                <Table.Td class="font-medium">{{
                                    s.room
                                }}</Table.Td>
                                <Table.Td>
                                    {{ s.guest_name ?? 'Anónimo' }}
                                    <span class="block text-xs text-slate-500"
                                        >{{ s.num_people }} pax ·
                                        {{ channelLabel[s.channel] ?? s.channel }}</span
                                    >
                                    <span
                                        v-if="s.vehicle_plate"
                                        class="mt-0.5 inline-flex items-center gap-1 text-xs text-slate-500"
                                        :title="s.vehicle_desc ?? 'Vehículo'"
                                    >
                                        <Lucide icon="Car" class="h-3 w-3" />
                                        {{ s.vehicle_plate }}
                                    </span>
                                </Table.Td>
                                <Table.Td class="text-sm">{{
                                    s.check_in_at
                                }}</Table.Td>
                                <Table.Td class="text-sm">
                                    {{ s.planned_end_at }}
                                    <span
                                        v-if="s.overdue"
                                        class="ml-1 rounded-full bg-danger/10 px-1.5 text-xs text-danger"
                                        >vencida</span
                                    >
                                </Table.Td>
                                <Table.Td>${{ s.amount }}</Table.Td>
                                <Table.Td v-if="canManage">
                                    <div class="flex justify-end">
                                        <Button
                                            variant="outline-primary"
                                            size="sm"
                                            class="whitespace-nowrap rounded-[0.5rem]"
                                            @click="askCheckOut(s)"
                                        >
                                            <Lucide icon="LogOut" class="mr-1.5 h-4 w-4" /> Check-out
                                        </Button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="py-8 text-center text-slate-500">
                        Ninguna habitación en uso ahora mismo.
                    </div>
                </div>
            </div>

            <!-- Historial: completadas, canceladas y no-shows -->
            <div v-show="tab === 'historial'" class="box mt-5">
                <div class="overflow-auto p-5 lg:overflow-visible">
                    <p class="mb-4 text-xs text-slate-500">
                        Últimas reservas que salieron del flujo: completadas,
                        canceladas y no-shows. Los no-shows cuentan en la
                        confiabilidad del huésped (visible en su perfil).
                    </p>
                    <Table v-if="history.length" striped>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>Huésped</Table.Th>
                                <Table.Th>Habitación</Table.Th>
                                <Table.Th>Llegada → Salida</Table.Th>
                                <Table.Th>Total</Table.Th>
                                <Table.Th>Estado</Table.Th>
                                <Table.Th class="text-right">Detalle</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            <Table.Tr v-for="r in history" :key="r.id">
                                <Table.Td>
                                    <span
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600"
                                    >
                                        {{ r.code }}
                                    </span>
                                    <div class="mt-1 font-medium">
                                        {{ r.guest_name ?? 'Anónimo' }}
                                    </div>
                                </Table.Td>
                                <Table.Td>
                                    <span class="font-medium">{{ r.room ?? '—' }}</span>
                                    <span class="block text-xs text-slate-500">{{ r.room_type }}</span>
                                </Table.Td>
                                <Table.Td class="text-sm">
                                    {{ r.starts_at }}
                                    <span class="text-slate-400">→</span>
                                    {{ r.ends_at }}
                                </Table.Td>
                                <Table.Td>${{ r.total_amount }}</Table.Td>
                                <Table.Td>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                        :class="statusFor(r.status).class"
                                    >
                                        <Lucide :icon="statusFor(r.status).icon" class="h-3 w-3" />
                                        {{ r.status_label }}
                                    </span>
                                    <span
                                        v-if="r.cancellation_reason"
                                        class="block max-w-[220px] truncate text-xs text-slate-400"
                                        :title="r.cancellation_reason"
                                        >{{ r.cancellation_reason }}</span
                                    >
                                </Table.Td>
                                <Table.Td>
                                    <div class="flex justify-end">
                                        <button
                                            class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-darkmode-400"
                                            title="Ver detalle"
                                            @click="selectedReservationId = r.id"
                                        >
                                            <Lucide icon="Eye" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </Table.Td>
                            </Table.Tr>
                        </Table.Tbody>
                    </Table>
                    <div v-else class="py-8 text-center text-slate-500">
                        Aún no hay historial.
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmación de No-show / Cancelación -->
        <Dialog
            :open="confirmAction !== null"
            @close="confirmAction = null"
        >
            <Dialog.Panel>
                <div v-if="confirmAction" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="confirmMeta[confirmAction.kind].tone"
                        >
                            <Lucide :icon="confirmMeta[confirmAction.kind].icon" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                {{ confirmMeta[confirmAction.kind].title }}
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">{{ confirmSubtitle }}</p>
                        </div>
                    </div>

                    <div
                        class="mt-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 p-3.5 text-sm text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-700 dark:text-slate-300"
                    >
                        <p class="font-medium">¿Qué pasará?</p>
                        <ul class="mt-1.5 list-inside list-disc space-y-1 text-xs">
                            <template v-if="confirmAction.kind === 'confirm'">
                                <li>La reserva pasa de <span class="font-medium">hold</span> a <span class="font-medium">confirmada</span> y la habitación queda apartada en firme.</li>
                                <li>Se libera el temporizador de 30 min del hold.</li>
                            </template>
                            <template v-else-if="confirmAction.kind === 'check_in'">
                                <li>El huésped queda registrado y la reserva pasa a la pestaña <span class="font-medium">En uso</span>.</li>
                                <li>La habitación cambia a <span class="font-medium">Ocupada</span> en el plano.</li>
                            </template>
                            <template v-else-if="confirmAction.kind === 'check_out'">
                                <li>La estancia termina y la habitación pasa a <span class="font-medium">Sucia</span> (limpieza puede entrar).</li>
                                <li v-if="folio && folio.grand_pending > 0">Se cobra la <span class="font-medium">cuenta final</span> (hospedaje + consumos) y entra a tu corte.</li>
                            </template>
                            <template v-else-if="confirmAction.kind === 'no_show'">
                                <li>Registra que el huésped <span class="font-medium">no se presentó</span>; la habitación se libera para venta.</li>
                                <li>Pasa a <span class="font-medium">Historial</span> como "No show" y afecta la confiabilidad del huésped.</li>
                            </template>
                            <template v-else>
                                <li>La reserva se cancela y la habitación se libera.</li>
                                <li>Pasa a <span class="font-medium">Historial</span> como "Cancelada"; los reembolsos se gestionan aparte.</li>
                            </template>
                        </ul>
                    </div>

                    <!-- Cuenta final (solo check-out) -->
                    <div v-if="confirmAction.kind === 'check_out'" class="mt-4">
                        <div v-if="folioLoading" class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-500 dark:bg-darkmode-700">
                            <Lucide icon="RefreshCw" class="h-4 w-4 animate-spin text-primary" /> Calculando cuenta final…
                        </div>
                        <template v-else-if="folio">
                            <div class="rounded-lg border border-slate-200/70 dark:border-darkmode-400">
                                <div class="flex items-center gap-2 border-b border-slate-200/70 px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-darkmode-400">
                                    <Lucide icon="ReceiptText" class="h-3.5 w-3.5" /> Cuenta final
                                </div>
                                <div class="space-y-2 px-4 py-3 text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="flex items-center gap-2 text-slate-500"><Lucide icon="BedDouble" class="h-4 w-4" /> Hospedaje pendiente</span>
                                        <span class="font-medium">{{ money(folio.lodging_pending) }}</span>
                                    </div>
                                    <div v-for="o in folio.orders" :key="o.id" class="flex items-center justify-between">
                                        <span class="flex min-w-0 items-center gap-2 text-slate-500">
                                            <Lucide icon="ShoppingCart" class="h-4 w-4 shrink-0" />
                                            <span class="truncate">{{ o.summary }} <span class="text-slate-400">· {{ o.created_at }}</span></span>
                                        </span>
                                        <span class="shrink-0 font-medium">{{ money(o.total) }}</span>
                                    </div>
                                    <div class="flex items-center justify-between border-t border-dashed border-slate-300/70 pt-2.5 dark:border-darkmode-400">
                                        <span class="font-medium">Total a cobrar</span>
                                        <span class="text-base font-medium" :class="folio.grand_pending > 0 ? 'text-danger' : 'text-success'">
                                            {{ folio.grand_pending > 0 ? money(folio.grand_pending) : 'Sin saldo — todo pagado' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <template v-if="folio.grand_pending > 0">
                                <div v-if="!folioForce" class="mt-3">
                                    <label class="mb-1.5 block text-sm text-slate-500">Método de pago</label>
                                    <div class="grid grid-cols-3 gap-2">
                                        <button
                                            v-for="m in folioMethods"
                                            :key="m.key"
                                            type="button"
                                            class="flex flex-col items-center gap-1 rounded-lg border py-2.5 text-xs font-medium transition"
                                            :class="folioMethod === m.key ? 'border-primary bg-primary/10 text-primary' : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'"
                                            @click="folioMethod = m.key"
                                        >
                                            <Lucide :icon="m.icon" class="h-4 w-4" /> {{ m.label }}
                                        </button>
                                    </div>
                                </div>
                                <label
                                    class="mt-3 flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 text-xs transition"
                                    :class="folioForce ? 'border-danger/30 bg-danger/5 text-danger' : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400'"
                                >
                                    <input v-model="folioForce" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-danger focus:ring-danger/30" />
                                    <span><span class="font-medium">Salida con saldo pendiente</span> — el huésped se va sin pagar {{ money(folio.grand_pending) }}; queda registrado en su expediente.</span>
                                </label>
                            </template>
                        </template>
                    </div>

                    <div v-if="confirmMeta[confirmAction.kind].reason" class="mt-4">
                        <FormLabel htmlFor="confirm-reason">
                            Motivo <span class="text-slate-400">(opcional, queda en el registro)</span>
                        </FormLabel>
                        <FormInput
                            id="confirm-reason"
                            v-model="confirmReason"
                            type="text"
                            :placeholder="
                                confirmAction.kind === 'no_show'
                                    ? 'No contestó el teléfono, no llegó a las 23:00…'
                                    : 'El huésped avisó que no viene, cambio de planes…'
                            "
                        />
                    </div>

                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline-secondary" @click="confirmAction = null">
                            Volver
                        </Button>
                        <Button
                            :variant="confirmMeta[confirmAction.kind].variant"
                            :disabled="confirmBusy || (confirmAction.kind === 'check_out' && folioLoading)"
                            @click="submitConfirmAction"
                        >
                            <Lucide :icon="confirmMeta[confirmAction.kind].icon" class="mr-2 h-4 w-4" />
                            {{
                                confirmBusy
                                    ? 'Procesando…'
                                    : confirmAction.kind === 'check_out' && folio && folio.grand_pending > 0 && !folioForce
                                      ? `Cobrar ${money(folio.grand_pending)} y check-out`
                                      : confirmMeta[confirmAction.kind].cta
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: registrar pago -->
        <Dialog size="lg" :open="payingReservation !== null" @close="payingReservation = null">
            <Dialog.Panel>
                <form v-if="payingReservation" class="flex max-h-[85vh] flex-col" @submit.prevent="submitPayment">
                    <!-- Header -->
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                            <Lucide icon="Banknote" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">Registrar pago</h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                {{ payingReservation.code }} · {{ payingReservation.guest_name ?? 'Anónimo' }} · Hab. {{ payingReservation.room ?? '—' }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="payingReservation = null"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        <!-- Resumen de saldo -->
                        <div class="grid grid-cols-3 gap-3">
                            <div class="rounded-lg border border-slate-200/70 p-3 text-center dark:border-darkmode-400">
                                <div class="text-xs text-slate-500">Total</div>
                                <div class="mt-1 font-medium">${{ payingReservation.total_amount }}</div>
                            </div>
                            <div class="rounded-lg border border-success/20 bg-success/5 p-3 text-center">
                                <div class="text-xs text-slate-500">Pagado</div>
                                <div class="mt-1 font-medium text-success">${{ payingReservation.paid_total.toFixed(2) }}</div>
                            </div>
                            <div class="rounded-lg border border-danger/20 bg-danger/5 p-3 text-center">
                                <div class="text-xs text-slate-500">Pendiente</div>
                                <div class="mt-1 font-medium text-danger">${{ payingReservation.pending_balance.toFixed(2) }}</div>
                            </div>
                        </div>

                        <div
                            v-if="Number(payingReservation.deposit_amount) > 0 || payingReservation.payment_due_at"
                            class="space-y-1 rounded-lg border border-dashed border-slate-300/70 bg-slate-50/70 p-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <p v-if="Number(payingReservation.deposit_amount) > 0" class="flex items-center gap-1.5 text-slate-500">
                                <Lucide icon="PiggyBank" class="h-3.5 w-3.5" /> Anticipo requerido: <span class="font-medium">${{ payingReservation.deposit_amount }}</span>
                            </p>
                            <p v-if="payingReservation.payment_due_at" class="flex items-center gap-1.5" :class="payingReservation.payment_overdue ? 'text-danger' : 'text-slate-500'">
                                <Lucide :icon="payingReservation.payment_overdue ? 'TriangleAlert' : 'CalendarClock'" class="h-3.5 w-3.5" />
                                {{ payingReservation.payment_overdue ? 'Venció el' : 'Liquidar antes de' }} {{ payingReservation.payment_due_at }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="pay-amount">Monto</FormLabel>
                                <div class="relative">
                                    <Lucide icon="DollarSign" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="pay-amount" v-model.number="paymentForm.amount" type="number" step="0.01" min="0.01" :max="payingReservation.pending_balance" class="pl-9" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="pay-method">Método</FormLabel>
                                <div class="relative">
                                    <Lucide icon="CreditCard" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormSelect id="pay-method" v-model="paymentForm.method" class="pl-9">
                                        <option value="cash">Efectivo</option>
                                        <option value="card">Tarjeta</option>
                                        <option value="transfer">Transferencia</option>
                                    </FormSelect>
                                </div>
                            </div>
                            <div v-if="paymentForm.method !== 'cash'" class="sm:col-span-2">
                                <FormLabel htmlFor="pay-ref">Referencia / folio</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Hash" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="pay-ref" v-model="paymentForm.reference" type="text" class="pl-9" placeholder="Folio bancario o voucher" />
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <FormLabel htmlFor="pay-notes">Notas <span class="text-slate-400">(opcional)</span></FormLabel>
                                <FormInput id="pay-notes" v-model="paymentForm.notes" type="text" placeholder="Ej. pago parcial, cambio pendiente…" />
                            </div>
                        </div>

                        <p v-if="paymentError" class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{{ paymentError }}</p>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button type="button" variant="outline-secondary" @click="payingReservation = null">Cancelar</Button>
                        <Button type="submit" variant="primary" class="shadow-md shadow-primary/20" :disabled="payingBusy">
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{ payingBusy ? 'Registrando…' : 'Registrar pago' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: nueva reserva / walk-in -->
        <Dialog size="xl" :open="showCreate" @close="showCreate = false">
            <Dialog.Panel>
                <form class="flex max-h-[85vh] flex-col" @submit.prevent="submitCreate">
                    <!-- Header -->
                    <div class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                            :class="walkIn ? 'bg-warning/10 text-warning' : 'bg-primary/10 text-primary'"
                        >
                            <Lucide :icon="walkIn ? 'Zap' : editingReservationId ? 'Pencil' : 'CalendarPlus'" class="h-5 w-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">{{ modalTitle }}</h2>
                            <p class="mt-0.5 text-xs text-slate-500">{{ property.name }}</p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showCreate = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>

                    <!-- Body -->
                    <div class="grid flex-1 grid-cols-1 gap-x-6 gap-y-5 overflow-y-auto px-6 py-6 sm:grid-cols-2">
                        <!-- Sección: estancia -->
                        <div class="col-span-full flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="BedDouble" class="h-3.5 w-3.5" /> Detalles de la estancia
                        </div>
                        <div class="col-span-full">
                            <FormLabel htmlFor="res-plan">Tarifa</FormLabel>
                            <div class="relative">
                                <Lucide icon="Tag" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                <FormSelect
                                    id="res-plan"
                                    v-model="form.rate_plan_id"
                                    class="pl-9"
                                    @change="availability = null"
                                >
                                    <option v-for="plan in ratePlans" :key="plan.id" :value="plan.id">
                                        {{ plan.room_type }} · {{ plan.name }} (${{ plan.price }} / {{ plan.duration_label }})
                                    </option>
                                </FormSelect>
                            </div>
                            <FormHelp v-if="selectedPlan?.min_advance_label">
                                Requiere reservar con mínimo {{ selectedPlan.min_advance_label }} de antelación.
                            </FormHelp>
                        </div>

                        <template v-if="!walkIn">
                            <div>
                                <FormLabel htmlFor="res-start">Llegada</FormLabel>
                                <div class="relative">
                                    <Lucide icon="CalendarCheck2" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="res-start" v-model="form.starts_at" type="datetime-local" class="pl-9" @change="availability = null" />
                                </div>
                                <FormHelp v-if="errors.starts_at" class="text-danger">{{ errors.starts_at }}</FormHelp>
                            </div>
                            <div>
                                <FormLabel htmlFor="res-end" class="flex items-center gap-1.5">
                                    Salida
                                    <span
                                        v-if="endsAutoFilled && form.ends_at"
                                        title="Calculada según la duración de la tarifa. Puedes ajustarla a mano."
                                        class="inline-flex cursor-help items-center gap-1 rounded-full bg-primary/10 px-1.5 py-0.5 text-[11px] font-medium text-primary"
                                    >
                                        <Lucide icon="Sparkles" class="h-3 w-3" /> auto
                                    </span>
                                </FormLabel>
                                <div class="relative">
                                    <Lucide icon="CalendarX2" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput
                                        id="res-end"
                                        v-model="form.ends_at"
                                        type="datetime-local"
                                        class="pl-9"
                                        @change="availability = null; endsAutoFilled = false"
                                    />
                                </div>
                                <FormHelp v-if="errors.ends_at" class="text-danger">{{ errors.ends_at }}</FormHelp>
                            </div>
                        </template>
                        <div v-else class="col-span-full">
                            <FormLabel htmlFor="res-end-walkin">
                                Salida prevista <span class="text-slate-400">(auto según tarifa si vacío)</span>
                            </FormLabel>
                            <div class="relative">
                                <Lucide icon="CalendarX2" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                <FormInput id="res-end-walkin" v-model="form.ends_at" type="datetime-local" class="pl-9" />
                            </div>
                        </div>

                        <!-- Disponibilidad (se consulta sola) -->
                        <div class="col-span-full flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50/70 p-3 dark:border-darkmode-400 dark:bg-darkmode-700">
                            <template v-if="searching">
                                <Lucide icon="RefreshCw" class="h-4 w-4 animate-spin text-primary" />
                                <span class="text-sm text-slate-500">Buscando habitaciones disponibles…</span>
                            </template>
                            <template v-else-if="availability">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="availability.rooms.length ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'"
                                >
                                    <Lucide icon="BedDouble" class="h-3.5 w-3.5" />
                                    {{ availability.rooms.length ? `${availability.rooms.length} habitación(es) disponible(s)` : 'Sin disponibilidad en ese rango' }}
                                </span>
                                <span v-if="availability.rooms.length" class="text-sm font-medium text-slate-600 dark:text-slate-300">
                                    Total ${{ availability.total }}
                                </span>
                            </template>
                            <span v-else class="text-xs text-slate-400">
                                {{ walkIn || form.starts_at ? 'La disponibilidad se consulta automáticamente.' : 'Elige tarifa y llegada; la disponibilidad se consulta sola.' }}
                            </span>
                            <button
                                type="button"
                                title="Volver a consultar disponibilidad"
                                class="ml-auto flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-200/60 hover:text-primary dark:hover:bg-darkmode-400"
                                :disabled="searching || (!walkIn && !form.starts_at)"
                                @click="searchAvailability"
                            >
                                <Lucide icon="RefreshCw" class="h-3.5 w-3.5" />
                            </button>
                        </div>

                        <!-- Estimación de cobro según la tarifa -->
                        <div v-if="estimate" class="col-span-full rounded-lg border border-primary/20 bg-primary/5 px-4 py-3">
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <div class="flex items-center gap-2 text-sm">
                                    <Lucide icon="Calculator" class="h-4 w-4 text-primary" />
                                    <span class="text-slate-600 dark:text-slate-300">
                                        {{ estimate.breakdown }} · ${{ estimate.unitPrice.toFixed(2) }} c/u
                                    </span>
                                </div>
                                <div class="ml-auto text-base font-medium text-primary">
                                    Total estimado: ${{ estimate.total.toFixed(2) }}
                                </div>
                            </div>
                            <div v-if="estimate.deposit" class="mt-1.5 flex items-center gap-1.5 text-xs text-slate-500">
                                <Lucide icon="PiggyBank" class="h-3.5 w-3.5" />
                                Anticipo {{ estimate.depositPct }}%: <span class="font-medium">${{ estimate.deposit.toFixed(2) }}</span>
                                — el resto se liquida según la tarifa.
                            </div>
                        </div>

                        <div v-if="roomOptions.length" class="col-span-full">
                            <FormLabel htmlFor="res-room">Habitación asignada</FormLabel>
                            <div class="relative">
                                <Lucide icon="DoorClosed" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                <FormSelect id="res-room" v-model="form.room_id" class="pl-9">
                                    <option v-for="room in roomOptions" :key="room.id" :value="room.id">
                                        {{ room.number }}<template v-if="room.hint"> · {{ room.hint }}</template>
                                    </option>
                                </FormSelect>
                            </div>
                            <FormHelp>Solo se listan habitaciones libres en el rango elegido.</FormHelp>
                        </div>
                        <div
                            v-else-if="availability && !availability.rooms.length"
                            class="col-span-full flex items-center gap-2 rounded-lg bg-danger/10 px-3 py-2.5 text-sm text-danger"
                        >
                            <Lucide icon="TriangleAlert" class="h-4 w-4 shrink-0" />
                            No hay habitaciones de este tipo libres en ese rango. Cambia las fechas o elige otra tarifa.
                        </div>

                        <!-- Sección: huésped -->
                        <div class="col-span-full mt-2 flex items-center gap-2 border-t border-slate-200/60 pt-5 text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-darkmode-400">
                            <Lucide icon="User" class="h-3.5 w-3.5" /> Huésped
                        </div>
                        <div class="col-span-full">
                            <FormLabel htmlFor="res-guest-search">Buscar en el directorio</FormLabel>
                            <div
                                v-if="selectedGuest"
                                class="flex items-center justify-between rounded-lg border border-primary/30 bg-primary/5 px-3 py-2.5"
                            >
                                <div class="flex items-center gap-2 text-sm">
                                    <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary/10 text-primary">
                                        <Lucide icon="User" class="h-4 w-4" />
                                    </div>
                                    <div>
                                        <span class="font-medium">{{ selectedGuest.full_name }}</span>
                                        <span v-if="selectedGuest.phone" class="ml-2 text-slate-500">{{ selectedGuest.phone }}</span>
                                        <span class="ml-2 rounded-full bg-success/10 px-1.5 text-xs text-success">
                                            {{ selectedGuest.visits }} visita{{ selectedGuest.visits === 1 ? '' : 's' }}
                                        </span>
                                    </div>
                                </div>
                                <a href="#" class="text-slate-400 hover:text-slate-600" @click.prevent="clearGuest">
                                    <Lucide icon="X" class="h-4 w-4" />
                                </a>
                            </div>
                            <div v-else class="relative">
                                <Lucide icon="Search" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                <FormInput
                                    id="res-guest-search"
                                    v-model="guestQuery"
                                    type="text"
                                    class="pl-9"
                                    placeholder="Buscar por nombre o teléfono…"
                                    autocomplete="off"
                                    @input="onGuestQuery"
                                />
                                <div
                                    v-if="guestHits.length"
                                    class="absolute z-30 mt-1 w-full rounded-md border border-slate-200 bg-white shadow-lg dark:border-darkmode-400 dark:bg-darkmode-600"
                                >
                                    <a
                                        v-for="hit in guestHits"
                                        :key="hit.id"
                                        href="#"
                                        class="flex items-center justify-between px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-darkmode-400"
                                        @click.prevent="pickGuest(hit)"
                                    >
                                        <span>
                                            <span class="font-medium">{{ hit.full_name ?? 'Sin nombre' }}</span>
                                            <span v-if="hit.phone" class="ml-2 text-slate-500">{{ hit.phone }}</span>
                                        </span>
                                        <span class="flex items-center gap-1.5">
                                            <span v-if="hit.is_blacklisted" class="rounded-full bg-danger/10 px-1.5 text-xs text-danger">Lista negra</span>
                                            <span class="rounded-full bg-slate-100 px-1.5 text-xs text-slate-500 dark:bg-darkmode-400">{{ hit.visits }}</span>
                                        </span>
                                    </a>
                                </div>
                            </div>
                            <p v-if="selectedGuest?.is_blacklisted" class="mt-1.5 rounded-md bg-danger/10 px-2 py-1.5 text-xs text-danger">
                                En lista negra: {{ selectedGuest.blacklist_reason }}
                            </p>
                        </div>

                        <template v-if="!selectedGuest">
                            <div>
                                <FormLabel htmlFor="res-guest">Nombre (nuevo huésped)</FormLabel>
                                <div class="relative">
                                    <Lucide icon="User" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="res-guest" v-model="form.guest_name" type="text" class="pl-9" placeholder="Nombre" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="res-phone">Teléfono</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Phone" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="res-phone" v-model="form.guest_phone" type="text" class="pl-9" placeholder="+52…" />
                                </div>
                            </div>
                        </template>

                        <div class="col-span-full grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-3">
                            <div>
                                <FormLabel htmlFor="res-adults">Adultos</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Users" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="res-adults" v-model.number="form.adults" type="number" min="1" max="20" class="pl-9" />
                                </div>
                            </div>
                            <div>
                                <FormLabel htmlFor="res-children">Niños</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Baby" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="res-children" v-model.number="form.children" type="number" min="0" max="20" class="pl-9" />
                                </div>
                            </div>
                            <div v-if="!walkIn" class="col-span-2 sm:col-span-1">
                                <FormLabel htmlFor="res-eta">Llegada estimada</FormLabel>
                                <div class="relative">
                                    <Lucide icon="Clock" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                    <FormInput id="res-eta" v-model="form.eta" type="time" class="pl-9" />
                                </div>
                                <FormHelp v-if="errors.eta" class="text-danger">{{ errors.eta }}</FormHelp>
                            </div>
                        </div>

                        <!-- Sección: vehículo y notas -->
                        <div class="col-span-full mt-2 flex items-center gap-2 border-t border-slate-200/60 pt-5 text-xs font-medium uppercase tracking-wide text-slate-400 dark:border-darkmode-400">
                            <Lucide icon="StickyNote" class="h-3.5 w-3.5" /> Vehículo y notas
                        </div>
                        <div>
                            <FormLabel htmlFor="res-plate">
                                Placas del vehículo <span class="text-slate-400">(opcional)</span>
                            </FormLabel>
                            <div class="relative">
                                <Lucide icon="Car" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                <FormInput id="res-plate" v-model="form.vehicle_plate" type="text" class="pl-9" placeholder="ABC-123-D" />
                            </div>
                        </div>
                        <div>
                            <FormLabel htmlFor="res-vehicle">Vehículo</FormLabel>
                            <div class="relative">
                                <Lucide icon="CarFront" class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400" />
                                <FormInput id="res-vehicle" v-model="form.vehicle_desc" type="text" class="pl-9" placeholder="Versa gris, moto roja…" />
                            </div>
                        </div>

                        <div>
                            <FormLabel htmlFor="res-notes">Notas internas (staff)</FormLabel>
                            <FormTextarea id="res-notes" v-model="form.notes" placeholder="Contexto de la reserva, acuerdos…" />
                        </div>
                        <div v-if="!walkIn">
                            <FormLabel htmlFor="res-guest-notes">Peticiones del huésped</FormLabel>
                            <FormTextarea id="res-guest-notes" v-model="form.guest_notes" placeholder="Piso alto, cuna, llega tarde…" />
                        </div>

                        <div v-if="!walkIn && !editingReservationId" class="col-span-full rounded-lg bg-slate-50 p-3 dark:bg-darkmode-700">
                            <FormCheck>
                                <FormCheck.Input id="res-confirmed" v-model="form.confirmed" type="checkbox" />
                                <FormCheck.Label htmlFor="res-confirmed">Confirmar de inmediato</FormCheck.Label>
                            </FormCheck>
                            <p v-if="!form.confirmed" class="mt-1.5 text-xs text-slate-500">
                                Se creará como hold: aparta la habitación 30 minutos mientras se confirma.
                            </p>
                        </div>

                        <p v-if="modalError" class="col-span-full rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">
                            {{ modalError }}
                        </p>
                    </div>

                    <!-- Footer -->
                    <div class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showCreate = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving || (walkIn && !form.room_id)"
                        >
                            <Lucide :icon="walkIn ? 'Zap' : 'Check'" class="mr-2 h-4 w-4" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : walkIn
                                      ? 'Ocupar ahora'
                                      : editingReservationId
                                        ? 'Guardar cambios'
                                        : 'Crear reserva'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <Slideover
            :open="selectedReservation !== null"
            @close="selectedReservationId = null"
        >
            <Slideover.Panel
                class="w-full overflow-hidden rounded-[1rem_0_0_1rem/1.25rem_0_0_1.25rem] sm:w-[640px]"
            >
                <template v-if="selectedReservation">
                    <Slideover.Title class="px-6 py-5">
                        <div
                            class="flex w-full items-start justify-between gap-4"
                        >
                            <div>
                                <div class="flex items-center gap-3">
                                    <h2 class="text-base font-medium">
                                        Reserva {{ selectedReservation.code }}
                                    </h2>
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs"
                                        :class="statusFor(selectedReservation.status).class"
                                    >
                                        <Lucide
                                            :icon="statusFor(selectedReservation.status).icon"
                                            class="h-3 w-3"
                                        />
                                        {{ selectedReservation.status_label }}
                                    </span>
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500"
                                >
                                    <span
                                        >Registro #{{
                                            selectedReservation.id
                                        }}</span
                                    >
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            channelBadge[
                                                selectedReservation
                                                    .source_channel
                                            ] ?? 'bg-slate-100 text-slate-600'
                                        "
                                    >
                                        {{
                                            channelLabel[
                                                selectedReservation
                                                    .source_channel
                                            ] ??
                                            selectedReservation.source_channel
                                        }}
                                    </span>
                                </div>
                            </div>
                            <button
                                class="rounded-full p-2 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
                                @click="selectedReservationId = null"
                            >
                                <Lucide icon="X" class="h-4 w-4" />
                            </button>
                        </div>
                    </Slideover.Title>

                    <Slideover.Description class="space-y-5 px-6 py-5">
                        <section
                            class="rounded-2xl border border-slate-200/70 bg-slate-50/70 p-4"
                        >
                            <h3 class="text-sm font-medium">Huésped</h3>
                            <div class="mt-3">
                                <div class="text-base font-medium">
                                    {{
                                        selectedReservation.guest_name ??
                                        'Anónimo'
                                    }}
                                </div>
                                <div
                                    class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500"
                                >
                                    <span
                                        v-if="selectedReservation.guest_phone"
                                        >{{
                                            selectedReservation.guest_phone
                                        }}</span
                                    >
                                    <span
                                        v-if="selectedReservation.guest_email"
                                        >{{
                                            selectedReservation.guest_email
                                        }}</span
                                    >
                                    <span>{{ paxLabel(selectedReservation) }}</span>
                                    <span
                                        v-if="selectedReservation.vehicle_plate"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Lucide icon="Car" class="h-3.5 w-3.5" />
                                        {{ selectedReservation.vehicle_plate }}
                                        <template v-if="selectedReservation.vehicle_desc">
                                            ({{ selectedReservation.vehicle_desc }})
                                        </template>
                                    </span>
                                </div>
                                <Link
                                    v-if="selectedReservation.guest_id"
                                    :href="
                                        route(
                                            'tenant.guests.show',
                                            selectedReservation.guest_id,
                                        )
                                    "
                                    class="mt-3 inline-flex items-center text-sm text-primary"
                                >
                                    <Lucide
                                        icon="UserRound"
                                        class="mr-2 h-4 w-4"
                                    />
                                    Ver perfil del huésped
                                </Link>
                            </div>
                        </section>

                        <section
                            class="rounded-2xl border border-slate-200/70 p-4"
                        >
                            <h3 class="text-sm font-medium">Detalle</h3>
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Folio</dt>
                                    <dd class="mt-1 font-medium">
                                        {{ selectedReservation.code }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Habitación</dt>
                                    <dd class="mt-1 font-medium">
                                        {{
                                            selectedReservation.room ??
                                            'Por asignar'
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Tipo</dt>
                                    <dd class="mt-1 font-medium">
                                        {{
                                            selectedReservation.room_type ?? '—'
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Llegada</dt>
                                    <dd class="mt-1 font-medium">
                                        {{ selectedReservation.starts_at }}
                                        <span
                                            v-if="selectedReservation.eta"
                                            class="block text-xs font-normal text-slate-500"
                                            >ETA {{ selectedReservation.eta }}</span
                                        >
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Salida</dt>
                                    <dd class="mt-1 font-medium">
                                        {{ selectedReservation.ends_at }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Tarifa</dt>
                                    <dd class="mt-1 font-medium">
                                        {{
                                            selectedReservation.rate_plan ?? '—'
                                        }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-slate-50/80 p-3">
                                    <dt class="text-slate-500">Total</dt>
                                    <dd class="mt-1 font-medium">
                                        ${{ selectedReservation.total_amount }}
                                    </dd>
                                </div>
                            </dl>
                            <p
                                v-if="selectedReservation.hold_expires_at"
                                class="mt-3 text-xs text-slate-500"
                            >
                                Hold vigente hasta
                                {{ selectedReservation.hold_expires_at }}
                            </p>
                            <div
                                v-if="selectedReservation.cancellation_reason"
                                class="mt-3 rounded-md bg-danger/5 px-3 py-2 text-sm text-danger"
                            >
                                <span class="font-medium">Motivo:</span>
                                {{ selectedReservation.cancellation_reason }}
                            </div>
                            <div
                                v-if="selectedReservation.guest_notes"
                                class="mt-3 text-sm"
                            >
                                <div class="text-xs text-slate-500">
                                    Peticiones del huésped
                                </div>
                                <p class="whitespace-pre-line text-slate-600">
                                    {{ selectedReservation.guest_notes }}
                                </p>
                            </div>
                            <div
                                v-if="selectedReservation.notes"
                                class="mt-3 text-sm"
                            >
                                <div class="text-xs text-slate-500">
                                    Notas internas
                                </div>
                                <p class="whitespace-pre-line text-slate-600">
                                    {{ selectedReservation.notes }}
                                </p>
                            </div>
                        </section>

                        <section
                            class="rounded-2xl border border-slate-200/70 p-4"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <h3 class="text-sm font-medium">
                                        Timeline
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Actividad reciente de la reserva.
                                    </p>
                                </div>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500"
                                >
                                    {{ selectedReservation.timeline.length }}
                                    evento{{
                                        selectedReservation.timeline.length ===
                                        1
                                            ? ''
                                            : 's'
                                    }}
                                </span>
                            </div>

                            <div
                                v-if="selectedReservation.timeline.length"
                                class="mt-4 space-y-3"
                            >
                                <div
                                    v-for="event in selectedReservation.timeline"
                                    :key="event.id"
                                    class="rounded-xl bg-slate-50/80 p-3"
                                >
                                    <div class="font-medium">
                                        {{ event.message }}
                                    </div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ event.by ?? 'Sistema' }} ·
                                        {{ event.at ?? '—' }}
                                    </div>
                                </div>
                            </div>
                            <p v-else class="mt-4 text-sm text-slate-500">
                                Sin actividad registrada todavía.
                            </p>
                        </section>
                    </Slideover.Description>

                    <Slideover.Footer
                        v-if="canManage && selectedIsActionable"
                        class="flex flex-wrap justify-end gap-2 bg-slate-50/80"
                    >
                        <Button
                            variant="outline-primary"
                            @click="openEdit(selectedReservation)"
                        >
                            <Lucide icon="Pencil" class="mr-2 h-4 w-4" />
                            Editar
                        </Button>
                        <Button
                            v-if="selectedReservation.status === 'pending'"
                            variant="outline-primary"
                            @click="askConfirm(selectedReservation)"
                        >
                            <Lucide icon="CircleCheck" class="mr-2 h-4 w-4" />
                            Confirmar
                        </Button>
                        <Button
                            variant="primary"
                            @click="askCheckIn(selectedReservation)"
                        >
                            <Lucide icon="LogIn" class="mr-2 h-4 w-4" />
                            Check-in
                        </Button>
                        <Button
                            variant="outline-warning"
                            @click="askNoShow(selectedReservation)"
                        >
                            <Lucide icon="UserX" class="mr-2 h-4 w-4" />
                            No-show
                        </Button>
                        <Button
                            variant="danger"
                            @click="askCancel(selectedReservation)"
                        >
                            <Lucide icon="Ban" class="mr-2 h-4 w-4" />
                            Cancelar
                        </Button>
                    </Slideover.Footer>
                </template>
            </Slideover.Panel>
        </Slideover>
    </RazeLayout>
</template>
