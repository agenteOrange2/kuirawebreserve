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
    FormHelp,
    FormInput,
    FormLabel,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import CourtesySection from '@/components/courtesies/CourtesySection.vue';
import {
    courtesyFormReady,
    courtesyPayload,
    emptyCourtesyForm,
    estimateCourtesy,
} from '@/components/courtesies/types';
import type { CourtesyForm } from '@/components/courtesies/types';
import { useCourtesyAccess } from '@/components/courtesies/useCourtesyAccess';
import { useCounterMethods } from '@/composables/useCounterMethods';
import type { GuestHit } from '@/composables/useGuestSearch';
import { useGuestSearch } from '@/composables/useGuestSearch';
import { usePropertyMode } from '@/composables/usePropertyMode';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useToasts } from '@/composables/useToasts';
import GuestMatches from './GuestMatches.vue';
import PlanModal from './PlanModal.vue';

/**
 * Llegó sin reserva, SIN salir del plano. Antes este botón mandaba a
 * /reservas con ?intent=walkin: quien está parado frente al plano vendiendo
 * un cuarto perdía la pantalla donde estaba trabajando. Ahora el registro
 * completo (nombre, contacto, vehículo, cobro) vive aquí, igual que el
 * exprés del motel y la reserva para otra fecha.
 *
 * La entrada es AHORA —la fija el servidor en CreateWalkInStay— y por eso se
 * muestra a la vista con reloj vivo en vez de un campo vacío: el mostrador
 * necesita ver con qué hora está quedando registrada la estancia. La salida
 * prevista nace calculada de la tarifa y se puede ajustar.
 *
 * OJO: el room se COPIA al abrir (modalRoom), mismo motivo que el exprés y
 * la reserva: el prop viene del computed del plano y se anula si el
 * slideover se cierra por debajo.
 */

interface RatePlanOption {
    id: number;
    name: string;
    type: string;
    price: number;
    duration_minutes: number | null;
    duration_label: string;
    duration_unit: string | null;
    duration_value: number | null;
    /** Personas de esta tarifa; null = las del cuarto. */
    included_people?: number | null;
    max_people?: number | null;
}

interface WalkInRoom {
    id: number;
    number: string;
    room_type: string | null;
    capacity: number | null;
    included_occupancy: number | null;
    extra_guest_fee: number | null;
    optional_charges: { concept: string; amount: number }[];
    /** Hora de salida del tipo (HH:mm); ancla la salida de tarifas por noche. */
    check_out_time: string | null;
    rate_plans: RatePlanOption[];
}

interface AvailabilityData {
    starts_at: string;
    ends_at: string;
    units: number;
    duration_label: string;
    total: number;
    regular_total?: number;
    advance_error: string | null;
    rooms: { id: number; number: string; base_total?: number }[];
}

const props = defineProps<{
    open: boolean;
    room: WalkInRoom | null;
    /** Fianza del hotel (/ajustes/metodos-pago); 0 = ajuste apagado. */
    guaranteeAmount: number;
    /** walkin_charge=checkin: el hospedaje se cobra al registrar la llegada. */
    chargeOnCheckin: boolean;
    /** Corte de madrugada "HH:MM" (Ajustes → Horarios); null = sin corte. */
    nightCutoffTime?: string | null;
}>();

const emit = defineEmits<{
    close: [];
    registered: [];
}>();

const toast = useToasts();
const saving = ref(false);

// El registro de placas es del modo motel: en un hotel puro la consulta
// responde 403, así que ni se pregunta (los campos del carro sí se capturan
// igual — la ficha se arma en el servidor con lo que se teclee).
const { hasMotel } = usePropertyMode();

// Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
// Políticas): si el hotel no tiene terminal, "Tarjeta" no se ofrece.
const {
    methods: paymentMethods,
    first: firstMethod,
    coerce: coerceMethod,
} = useCounterMethods();
// La fianza se cobra con lo que acepte la recepción de este hotel: estuvo
// cableada a efectivo y terminal "porque se recibe en la mano", y eso dejaba
// fuera al que cobra depósitos por transferencia.
const guaranteeMethods = paymentMethods;

const modalRoom = ref<WalkInRoom | null>(null);

const ratePlanId = ref<number | null>(null);
const endsAt = ref('');
const endsAuto = ref(true);
const people = ref(1);
const extraConcepts = ref<string[]>([]);
const guestName = ref('');
const guestPhone = ref('');
const guestEmail = ref('');
// Huésped que ya vino: se busca al teclear nombre o teléfono y, al elegirlo,
// llena los tres campos y la ficha queda ligada por guest_id en vez de
// depender de que el teléfono se teclee igual que la vez pasada.
const selectedGuest = ref<GuestHit | null>(null);
const {
    hits: guestHits,
    searching: guestSearching,
    search: searchGuests,
    reset: resetGuestSearch,
} = useGuestSearch();
// Ficha del vehículo: lo estructurado vive en `vehicles` (VehicleRegistry),
// no en la estancia. Se captura completo aquí porque es el único momento en
// que el carro está enfrente.
const plate = ref('');
const vehicleBrand = ref('');
const vehicleModel = ref('');
const vehicleColor = ref('');
const knownVehicle = ref<{
    plate: string;
    label: string | null;
    visits: number;
    is_blacklisted: boolean;
    blacklist_reason: string | null;
} | null>(null);
const paymentMethod = ref<CounterMethod>('cash');
const paymentReference = ref('');
const guaranteeMethod = ref<CounterMethod>('cash');
// Folio del comprobante: obligatorio en transferencia, que es por donde se
// devuelve el depósito días después.
const guaranteeReference = ref('');
// Ajuste del monto en el mostrador. El caso real: el grupo que llega por
// varias habitaciones y negocia el depósito. El motivo es obligatorio —
// quien devuelva ese dinero días después solo va a tener esta nota.
const guaranteeEditing = ref(false);
const guaranteeAmountInput = ref<number | string>(0);
const guaranteeReason = ref('');
const guaranteeAdjusted = computed(
    () =>
        guaranteeEditing.value &&
        Math.round(Number(guaranteeAmountInput.value || 0) * 100) !==
            Math.round(props.guaranteeAmount * 100),
);
const guaranteeBlocked = computed(
    () =>
        (guaranteeAdjusted.value && !guaranteeReason.value.trim()) ||
        // Una fianza por transferencia sin folio queda cobrada y sin manera
        // de devolverse: el servidor la rechaza y el botón lo dice antes.
        (guaranteeMethod.value === 'transfer' &&
            !guaranteeReference.value.trim()),
);
const availability = ref<AvailabilityData | null>(null);
const availLoading = ref(false);
const modalError = ref<string | null>(null);

const UNIT_MINUTES: Record<string, number> = {
    minute: 1,
    hour: 60,
    day: 1440,
    week: 10080,
};

function toLocalInput(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** La habitación se nombra completa: el número solo no dice qué se vende. */
const roomLabel = computed(() => {
    const room = modalRoom.value;
    if (!room) return '';
    return room.room_type ? `${room.number} (${room.room_type})` : room.number;
});

/* --- La hora de entrada, a la vista y viva ---------------------------
 * El servidor sella check_in_at = now(), así que aquí no hay campo que
 * llenar: hay un reloj que decir. Se refresca cada 30 s mientras el modal
 * está abierto para que un formulario que se quedó abierto no muestre una
 * hora vieja, y la salida automática se recalcula con él.
 */
const now = ref(new Date());
let clock: ReturnType<typeof setInterval> | null = null;

const arrivalLabel = computed(() =>
    new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'long',
        timeStyle: 'short',
    }).format(now.value),
);

watch(
    () => props.open,
    (open) => {
        if (clock) {
            clearInterval(clock);
            clock = null;
        }

        if (!open || !props.room) return;

        modalRoom.value = {
            ...props.room,
            optional_charges: props.room.optional_charges.map((c) => ({
                ...c,
            })),
            rate_plans: props.room.rate_plans.map((p) => ({ ...p })),
        };
        // La lista ya viene ordenada por precio: la más barata preseleccionada.
        ratePlanId.value = modalRoom.value.rate_plans[0]?.id ?? null;
        now.value = new Date();
        endsAuto.value = true;
        autoFillEnd();
        people.value = 1;
        extraConcepts.value = [];
        guestName.value = '';
        guestPhone.value = '';
        guestEmail.value = '';
        selectedGuest.value = null;
        resetGuestSearch();
        plate.value = '';
        vehicleBrand.value = '';
        vehicleModel.value = '';
        vehicleColor.value = '';
        knownVehicle.value = null;
        paymentMethod.value = firstMethod.value;
        paymentReference.value = '';
        courtesyOn.value = false;
        courtesyForm.value = emptyCourtesyForm();
        courtesyCapWarning.value = null;
        guaranteeEditing.value = false;
        guaranteeAmountInput.value = props.guaranteeAmount;
        guaranteeReason.value = '';
        guaranteeMethod.value = firstMethod.value;
        guaranteeReference.value = '';
        availability.value = null;
        modalError.value = null;

        clock = setInterval(() => {
            now.value = new Date();
        }, 30000);
    },
);

const selectedPlan = computed(
    () =>
        modalRoom.value?.rate_plans.find(
            (plan) => plan.id === ratePlanId.value,
        ) ?? null,
);

/**
 * Espejo de RatePlan::suggestedEnd — noche = día siguiente a la hora de
 * salida del tipo (12:00 si no tiene); mes = calendario; resto = minutos de
 * la duración.
 */
function suggestedEndFor(plan: RatePlanOption, start: Date): Date {
    const end = new Date(start);
    if (plan.type === 'night') {
        const [outHour, outMinute] = (
            modalRoom.value?.check_out_time ?? '12:00'
        )
            .split(':')
            .map(Number);
        // Antes del corte de madrugada cuenta como la noche anterior: sale
        // hoy a la hora de check-out.
        const sameDayEnd = new Date(start);
        sameDayEnd.setHours(outHour || 0, outMinute || 0, 0, 0);
        if (props.nightCutoffTime) {
            const [cutHour, cutMinute] = props.nightCutoffTime
                .split(':')
                .map(Number);
            const cutoff = new Date(start);
            cutoff.setHours(cutHour || 0, cutMinute || 0, 0, 0);
            if (start < cutoff && start < sameDayEnd) return sameDayEnd;
        }
        end.setDate(end.getDate() + 1);
        end.setHours(outHour || 0, outMinute || 0, 0, 0);
        return end;
    }
    if (plan.duration_unit === 'month') {
        end.setMonth(end.getMonth() + (plan.duration_value ?? 1));
        return end;
    }
    const minutes =
        plan.duration_minutes ??
        (plan.duration_value ?? 1) *
            (UNIT_MINUTES[plan.duration_unit ?? 'minute'] ?? 1);
    end.setMinutes(end.getMinutes() + (minutes || 60));
    return end;
}

function autoFillEnd() {
    const plan = selectedPlan.value;
    if (!plan) return;
    // Si ya la tocaron a mano, es suya: ni el cambio de tarifa ni el tic del
    // reloj se la vuelven a pisar (borrar la hora para reescribirla dejaría
    // el campo vacío medio segundo, y con eso bastaba para perder el dato).
    if (!endsAuto.value) return;
    endsAt.value = toLocalInput(suggestedEndFor(plan, now.value));
    endsAuto.value = true;
}

watch(ratePlanId, () => {
    availability.value = null;
    autoFillEnd();
});

// El tic del reloj solo recalcula la salida automática. Si además tirara la
// disponibilidad, con tarifas por noche (donde la salida no se mueve) el
// aviso de "esta habitación ya tiene algo agendado" se apagaría a los 30 s
// y no se volvería a consultar nunca.
watch(now, () => {
    if (!props.open) return;
    autoFillEnd();
});

/** Espejo de RatePlan::unitsFor para el estimado. */
const units = computed(() => {
    if (availability.value) return availability.value.units;
    const plan = selectedPlan.value;
    if (!plan || !endsAt.value) return 1;
    const start = now.value;
    const end = new Date(endsAt.value);
    if (end <= start) return 1;
    if (plan.type === 'night') {
        const days = Math.round(
            (new Date(
                end.getFullYear(),
                end.getMonth(),
                end.getDate(),
            ).getTime() -
                new Date(
                    start.getFullYear(),
                    start.getMonth(),
                    start.getDate(),
                ).getTime()) /
                86400000,
        );
        return Math.max(1, days);
    }
    if (plan.duration_unit === 'month') {
        let months =
            (end.getFullYear() - start.getFullYear()) * 12 +
            (end.getMonth() - start.getMonth());
        if (end.getDate() > start.getDate()) months += 1;
        return Math.max(1, months);
    }
    const unitMinutes =
        plan.duration_minutes ??
        (plan.duration_value ?? 1) *
            (UNIT_MINUTES[plan.duration_unit ?? 'minute'] ?? 1);
    return Math.max(
        1,
        Math.ceil(
            (end.getTime() - start.getTime()) / 60000 / (unitMinutes || 60),
        ),
    );
});

// La tarifa puede traer sus propias personas (la Sencilla por horas admite
// 3 e incluye 2; la Doble por horas, solo 2): manda sobre la del cuarto.
const maxPeople = computed(
    () => selectedPlan.value?.max_people ?? modalRoom.value?.capacity ?? 20,
);
const includedPeople = computed(
    () =>
        selectedPlan.value?.included_people ??
        modalRoom.value?.included_occupancy ??
        null,
);
watch(maxPeople, (max) => {
    if (people.value > max) people.value = max;
});

const extraGuestsFee = computed(() => {
    const included = includedPeople.value;
    const fee = modalRoom.value?.extra_guest_fee ?? 0;
    if (!included || !fee || people.value <= included) return 0;
    return (people.value - included) * fee * units.value;
});

const optionalTotal = computed(() =>
    (modalRoom.value?.optional_charges ?? [])
        .filter((charge) => extraConcepts.value.includes(charge.concept))
        .reduce((sum, charge) => sum + charge.amount, 0),
);

// Hospedaje con el precio del servidor en cuanto llega la disponibilidad
// (temporadas, descuento por semana/mes, ajuste del cuarto); mientras, el
// precio base de la tarifa.
const stayPrice = computed(() => {
    const avail = availability.value;
    if (avail && avail.units === units.value) {
        const room = avail.rooms.find((r) => r.id === modalRoom.value?.id);
        return room?.base_total ?? avail.total;
    }
    return (selectedPlan.value?.price ?? 0) * units.value;
});

const total = computed(
    () => stayPrice.value + extraGuestsFee.value + optionalTotal.value,
);

// ── Cortesía en el mismo alta (módulo cortesias) ──
// La del propietario baja el hospedaje antes de cobrarlo. La del resto
// queda esperando al propietario: entonces no se cobra al llegar, se cobra
// al salir con lo que él decida.
const courtesyOn = ref(false);
const courtesyForm = ref<CourtesyForm>(emptyCourtesyForm());
/** 409 del servidor: el siguiente envío confirma que pasa el tope. */
const courtesyCapWarning = ref<string | null>(null);
const courtesyAccess = useCourtesyAccess();
const courtesyEnabled = computed(() => courtesyAccess.value.enabled);
const courtesyIsOwner = computed(() => courtesyAccess.value.is_owner);

/** Estimado de lo que se descuenta: solo hospedaje (tarifa y persona extra). */
const courtesyDiscount = computed(() =>
    courtesyOn.value && courtesyIsOwner.value
        ? estimateCourtesy(
              courtesyForm.value,
              stayPrice.value + extraGuestsFee.value,
          )
        : 0,
);

/** Pedida al propietario: el hospedaje se cobra al salir. */
const courtesyDefersCharge = computed(
    () => courtesyOn.value && !courtesyIsOwner.value,
);
const chargesNow = computed(
    () => props.chargeOnCheckin && !courtesyDefersCharge.value,
);
const chargeTotal = computed(() =>
    Math.max(0, total.value - courtesyDiscount.value),
);

watch(
    () => [courtesyForm.value.kind, courtesyForm.value.value, courtesyOn.value],
    () => (courtesyCapWarning.value = null),
);

const peopleHint = computed(() => {
    const room = modalRoom.value;
    if (!room) return '';
    const parts: string[] = [];
    if (maxPeople.value < 20) parts.push(`Hasta ${maxPeople.value} personas`);
    if (
        includedPeople.value &&
        room.extra_guest_fee &&
        includedPeople.value < maxPeople.value
    ) {
        parts.push(
            `incluye ${includedPeople.value} · extra ${money(room.extra_guest_fee)} c/u`,
        );
    }
    return parts.join(' · ');
});

/**
 * La estancia guarda una descripción de una línea; la ficha estructurada
 * (marca, modelo, color) vive en `vehicles`. Se arma de lo capturado para
 * que el plano y el folio digan qué carro es sin ir a buscar la ficha.
 */
const vehicleDesc = computed(() => {
    const name = [vehicleBrand.value.trim(), vehicleModel.value.trim()]
        .filter(Boolean)
        .join(' ');
    return [name, vehicleColor.value.trim()]
        .filter(Boolean)
        .join(' · ')
        .slice(0, 100);
});

// Espejo de Vehicle::normalizePlate: con menos de 4 caracteres útiles el
// servidor no crea ficha (marca, modelo y color se perderían en silencio),
// así que se avisa mientras se teclea.
const plateTooShort = computed(() => {
    const useful = plate.value.replace(/[^a-zA-Z0-9]/g, '');
    return useful.length > 0 && useful.length < 4;
});

// Al teclear la placa se pregunta si ese carro ya vino: la segunda visita
// autocompleta marca y modelo (sin pisar lo que ya se escribió) y una placa
// vetada avisa antes de cobrar, no después. El endpoint es del registro de
// placas (modo motel): donde no aplica, simplemente no contesta y el
// registro sigue igual.
let plateTimer: ReturnType<typeof setTimeout> | null = null;

watch(plate, (value) => {
    if (plateTimer) clearTimeout(plateTimer);

    const term = value.trim();

    if (!hasMotel.value || term.length < 4) {
        knownVehicle.value = null;

        return;
    }

    plateTimer = setTimeout(async () => {
        try {
            const { data } = await axios.get('/api/vehicles/lookup', {
                params: { plate: term },
            });
            knownVehicle.value = data ?? null;

            if (data) {
                vehicleBrand.value = vehicleBrand.value || (data.brand ?? '');
                vehicleModel.value = vehicleModel.value || (data.model ?? '');
                vehicleColor.value = vehicleColor.value || (data.color ?? '');
            }
        } catch {
            knownVehicle.value = null;
        }
    }, 400);
});

// Disponibilidad en vivo con debounce, solo para avisar: la habitación es la
// que se tocó en el plano y no se cambia por debajo. Si el rango elegido
// choca con una reserva que ya tiene, se dice antes de cobrar.
let availTimer: ReturnType<typeof setTimeout> | null = null;

watch([() => props.open, ratePlanId, endsAt], () => {
    if (availTimer) clearTimeout(availTimer);
    if (!props.open || !ratePlanId.value) return;
    availTimer = setTimeout(searchAvailability, 400);
});

async function searchAvailability() {
    if (!ratePlanId.value) return;
    availLoading.value = true;
    try {
        const { data } = await axios.get<AvailabilityData>(
            '/api/availability',
            {
                params: {
                    rate_plan_id: ratePlanId.value,
                    starts_at: new Date().toISOString(),
                    ends_at: endsAt.value || undefined,
                },
            },
        );
        availability.value = data;
        // advance_error es la antelación mínima de la tarifa: al walk-in no
        // le aplica — el huésped ya está parado en el mostrador.
    } catch {
        // Sin consulta el registro sigue: el servidor es el que decide.
        availability.value = null;
    } finally {
        availLoading.value = false;
    }
}

const roomBusy = computed(
    () =>
        availability.value !== null &&
        modalRoom.value !== null &&
        !availability.value.rooms.some(
            (room) => room.id === modalRoom.value?.id,
        ),
);

const canSubmit = computed(
    () =>
        !saving.value &&
        !!ratePlanId.value &&
        !!modalRoom.value &&
        // Un ajuste de fianza sin motivo lo rechaza el servidor con 422:
        // más vale que el botón lo diga antes de intentarlo.
        !guaranteeBlocked.value &&
        (!courtesyOn.value || courtesyFormReady(courtesyForm.value)),
);

// Mismo criterio que el estimado de /reservas: "2 noches", "3 × 12 horas".
const unitsLabel = computed(() => {
    const plan = selectedPlan.value;
    if (!plan) return '';
    if (plan.type === 'night') {
        return `${units.value} ${units.value === 1 ? 'noche' : 'noches'}`;
    }
    return units.value === 1
        ? plan.duration_label
        : `${units.value} × ${plan.duration_label}`;
});

const money = (n: number) =>
    '$' +
    new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n ?? 0);

function toggleConcept(concept: string) {
    const index = extraConcepts.value.indexOf(concept);
    if (index >= 0) extraConcepts.value.splice(index, 1);
    else extraConcepts.value.push(concept);
}

// Cambiar el nombre de un huésped ya ligado lo suelta: es otra persona.
// Corregir teléfono o correo no — el servidor completa lo que le falte a su
// ficha sin pisar lo que ya tiene.
function onGuestInput(field: 'name' | 'phone') {
    if (selectedGuest.value) {
        if (field !== 'name') return;
        selectedGuest.value = null;
    }
    searchGuests(field === 'name' ? guestName.value : guestPhone.value);
}

function pickGuest(hit: GuestHit) {
    selectedGuest.value = hit;
    guestName.value = hit.full_name ?? guestName.value;
    guestPhone.value = hit.phone ?? guestPhone.value;
    guestEmail.value = hit.email ?? guestEmail.value;
    resetGuestSearch();
}

function clearGuest() {
    selectedGuest.value = null;
    guestName.value = '';
    guestPhone.value = '';
    guestEmail.value = '';
    resetGuestSearch();
    nextTick(() => document.getElementById('walkin-name')?.focus());
}

async function submit() {
    if (!modalRoom.value || !canSubmit.value) return;
    saving.value = true;
    modalError.value = null;
    const roomNumber = modalRoom.value.number;
    try {
        await axios.post('/api/stays', {
            room_id: modalRoom.value.id,
            rate_plan_id: ratePlanId.value,
            planned_end_at: endsAt.value || undefined,
            num_people: people.value,
            guest_id: selectedGuest.value?.id,
            guest_name: guestName.value.trim() || undefined,
            guest_phone: guestPhone.value.trim() || undefined,
            guest_email: guestEmail.value.trim() || undefined,
            vehicle_plate: plate.value.trim()
                ? plate.value.trim().toUpperCase()
                : undefined,
            vehicle_desc: vehicleDesc.value || undefined,
            vehicle_brand: vehicleBrand.value.trim() || undefined,
            vehicle_model: vehicleModel.value.trim() || undefined,
            vehicle_color: vehicleColor.value.trim() || undefined,
            extra_charges: extraConcepts.value.length
                ? extraConcepts.value
                : undefined,
            // El cobro al llegar lo decide el ajuste del hotel, no el modal
            // (salvo la cortesía pedida al propietario: se cobra al salir).
            payment_method: chargesNow.value
                ? coerceMethod(paymentMethod.value)
                : undefined,
            payment_reference:
                chargesNow.value &&
                paymentMethod.value !== 'cash' &&
                paymentReference.value.trim() !== ''
                    ? paymentReference.value.trim()
                    : undefined,
            // Fianza activa: el monto default lo pone el ajuste del hotel;
            // solo viaja si el mostrador lo ajustó, y entonces con su motivo
            // (el servidor lo exige).
            guarantee_method:
                props.guaranteeAmount > 0 && guaranteeMethods.value.length
                    ? guaranteeMethod.value
                    : undefined,
            guarantee_reference:
                guaranteeMethod.value === 'transfer'
                    ? guaranteeReference.value.trim() || undefined
                    : undefined,
            guarantee_amount: guaranteeAdjusted.value
                ? Number(guaranteeAmountInput.value || 0)
                : undefined,
            guarantee_reason: guaranteeAdjusted.value
                ? guaranteeReason.value.trim()
                : undefined,
            courtesy: courtesyPayload(
                courtesyEnabled.value && courtesyOn.value,
                courtesyForm.value,
                courtesyCapWarning.value !== null,
            ),
        });

        toast.success(
            `Habitación ${roomNumber} ocupada`,
            courtesyDefersCharge.value
                ? 'Llegada registrada y cortesía solicitada al propietario; el hospedaje se cobra al salir.'
                : chargesNow.value && chargeTotal.value > 0
                  ? `Llegada registrada y hospedaje cobrado (${money(chargeTotal.value)}).`
                  : chargesNow.value
                    ? 'Llegada registrada con cortesía: no hay hospedaje que cobrar.'
                    : 'Llegada registrada; el hospedaje se cobra al registrar la salida.',
        );
        emit('registered');
        emit('close');
    } catch (error: any) {
        const data = error.response?.data;

        if (error.response?.status === 409) {
            courtesyCapWarning.value = data?.message ?? 'Pasa el tope del mes.';
            return;
        }

        modalError.value =
            data?.message ??
            (data?.errors
                ? (Object.values(data.errors)[0] as string[])[0]
                : null) ??
            'No se pudo registrar la llegada.';
    } finally {
        saving.value = false;
    }
}

// El fondo es estático para que un clic afuera no tire el formulario, pero Esc
// sí debe cerrar: la tecla es una decisión, no un resbalón del ratón. El
// Dialog del theme se la traga junto con el clic, así que se escucha aquí.
function onEscape(event: KeyboardEvent) {
    if (event.key === 'Escape' && props.open && !saving.value) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onEscape));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onEscape);
    if (clock) clearInterval(clock);
    if (availTimer) clearTimeout(availTimer);
    if (plateTimer) clearTimeout(plateTimer);
});
</script>

<template>
    <!-- staticBackdrop: formulario largo y con dinero de por medio; un clic
         afuera no debe tirarlo. Ancho de verdad: son cinco bloques de captura
         y en 600px se amontonaban unos sobre otros. -->
    <PlanModal
        :open="open"
        icon="LogIn"
        :title="`Llegó sin reserva · ${roomLabel}`"
        subtitle="Entra ahora y el cuarto queda ocupado."
        panel-class="sm:w-[94vw] lg:w-[940px] xl:w-[1080px]"
        static-backdrop
        :busy="saving"
        @close="emit('close')"
    >
        <!-- Tarifa -->
        <section>
            <div
                class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="Clock" class="h-3.5 w-3.5" />
                Tarifa
            </div>
            <div
                v-if="modalRoom?.rate_plans.length"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
            >
                <button
                    v-for="plan in modalRoom.rate_plans"
                    :key="plan.id"
                    type="button"
                    class="rounded-lg border p-3.5 text-left transition"
                    :class="
                        ratePlanId === plan.id
                            ? 'border-primary bg-primary/5'
                            : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                    "
                    @click="ratePlanId = plan.id"
                >
                    <span
                        class="block truncate text-sm font-medium"
                        :class="ratePlanId === plan.id ? 'text-primary' : ''"
                        >{{ plan.name }}</span
                    >
                    <span class="mt-0.5 block text-xs text-slate-500">{{
                        plan.duration_label
                    }}</span>
                    <span
                        class="mt-2 block text-sm leading-none font-semibold"
                        >{{ money(plan.price) }}</span
                    >
                </button>
            </div>
            <div
                v-else
                class="flex items-start gap-2 rounded-lg bg-danger/10 px-3.5 py-2.5 text-xs text-danger"
            >
                <Lucide icon="TriangleAlert" class="mt-0.5 h-4 w-4 shrink-0" />
                Este tipo de habitación no tiene tarifas activas; agrégalas en
                Zonas y tipos.
            </div>
        </section>

        <!-- Entrada y salida -->
        <section
            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div
                class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                Entrada y salida
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <div>
                    <FormLabel
                        class="mb-1.5 flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300"
                    >
                        Entrada
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                        >
                            <Lucide icon="Clock" class="h-3 w-3" />
                            ahora
                        </span>
                    </FormLabel>
                    <div
                        class="flex h-9 items-center gap-2 rounded-md border border-slate-200/70 bg-slate-50/70 px-3 text-xs font-medium dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide
                            icon="CalendarCheck2"
                            class="h-4 w-4 shrink-0 text-slate-400"
                        />
                        {{ arrivalLabel }}
                    </div>
                    <FormHelp
                        >La estancia se sella con esta hora al
                        guardar.</FormHelp
                    >
                </div>
                <div>
                    <FormLabel
                        htmlFor="walkin-ends"
                        class="mb-1.5 flex items-center gap-2 text-xs font-medium text-slate-600 dark:text-slate-300"
                    >
                        Salida prevista
                        <span
                            v-if="endsAuto && endsAt"
                            title="Calculada según la duración de la tarifa. Puedes ajustarla a mano."
                            class="inline-flex cursor-help items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                        >
                            <Lucide icon="Sparkles" class="h-3 w-3" />
                            auto
                        </span>
                    </FormLabel>
                    <FormDateTime
                        input-class="h-9 text-xs"
                        id="walkin-ends"
                        v-model="endsAt"
                        @change="endsAuto = false"
                    />
                    <FormHelp
                        >Se calcula con la tarifa; ajústala si se quedan
                        más.</FormHelp
                    >
                </div>
            </div>
            <div
                v-if="roomBusy"
                class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3.5 py-2.5 text-xs text-warning"
            >
                <Lucide icon="TriangleAlert" class="mt-0.5 h-4 w-4 shrink-0" />
                La {{ modalRoom?.number }} tiene algo agendado dentro de ese
                rango; adelanta la salida o registra la llegada en otra
                habitación.
            </div>
            <p v-else-if="availLoading" class="mt-4 text-xs text-slate-500">
                Revisando que la habitación esté libre en ese rango…
            </p>
        </section>

        <!-- Personas y cargos -->
        <section
            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div class="grid gap-4 lg:grid-cols-2">
                <div>
                    <div
                        class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        <Lucide icon="Users" class="h-3.5 w-3.5" />
                        Personas
                    </div>
                    <div class="flex items-center gap-3">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 w-9 rounded-lg p-0"
                            :disabled="people <= 1"
                            @click="people = Math.max(1, people - 1)"
                        >
                            <Lucide icon="Minus" class="h-4 w-4" />
                        </Button>
                        <span class="w-10 text-center text-sm font-semibold">{{
                            people
                        }}</span>
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="h-9 w-9 rounded-lg p-0"
                            :disabled="people >= maxPeople"
                            @click="people = Math.min(maxPeople, people + 1)"
                        >
                            <Lucide icon="Plus" class="h-4 w-4" />
                        </Button>
                    </div>
                    <FormHelp v-if="peopleHint">{{ peopleHint }}</FormHelp>
                </div>
                <div v-if="modalRoom?.optional_charges.length">
                    <div
                        class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                    >
                        <Lucide icon="CirclePlus" class="h-3.5 w-3.5" />
                        Cargos opcionales
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="charge in modalRoom.optional_charges"
                            :key="charge.concept"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                extraConcepts.includes(charge.concept)
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-slate-200/70 text-slate-500 hover:border-slate-300 dark:border-darkmode-400'
                            "
                            @click="toggleConcept(charge.concept)"
                        >
                            {{ charge.concept }} ·
                            {{ money(charge.amount) }}
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Huésped -->
        <section
            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div
                class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="User" class="h-3.5 w-3.5" />
                Huésped
            </div>
            <GuestMatches
                v-if="selectedGuest"
                class="mb-3"
                :hits="[]"
                :selected="selectedGuest"
                @clear="clearGuest"
            />
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-name"
                        >Nombre completo</FormLabel
                    >
                    <div class="relative">
                        <Lucide
                            :icon="guestSearching ? 'LoaderCircle' : 'Search'"
                            class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-3.5 w-3.5 text-slate-400"
                            :class="guestSearching ? 'animate-spin' : ''"
                        />
                        <FormInput
                            class="h-9 pl-9 text-xs"
                            id="walkin-name"
                            v-model="guestName"
                            placeholder="Nombre y apellidos"
                            maxlength="255"
                            autocomplete="off"
                            @input="onGuestInput('name')"
                        />
                    </div>
                </div>
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-phone"
                        >Teléfono</FormLabel
                    >
                    <FormInput
                        class="h-9 text-xs"
                        id="walkin-phone"
                        v-model="guestPhone"
                        autocomplete="off"
                        @input="onGuestInput('phone')"
                        type="tel"
                        placeholder="6561234567"
                        maxlength="30"
                    />
                </div>
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-email"
                        >Correo (opcional)</FormLabel
                    >
                    <FormInput
                        class="h-9 text-xs"
                        id="walkin-email"
                        v-model="guestEmail"
                        type="email"
                        placeholder="correo@ejemplo.com"
                        maxlength="255"
                    />
                </div>
            </div>
            <GuestMatches
                v-if="!selectedGuest && guestHits.length"
                class="mt-3"
                :hits="guestHits"
                :selected="null"
                @pick="pickGuest"
            />
            <FormHelp v-else
                >Teclea nombre o teléfono: si ya vino antes aparece para
                elegirlo. Con nombre y teléfono queda su ficha en el CRM; puedes
                dejarlo en blanco si solo pasa la noche.</FormHelp
            >
        </section>

        <!-- Vehículo: la ficha completa, no solo la placa -->
        <section
            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div
                class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="Car" class="h-3.5 w-3.5" />
                Vehículo (opcional)
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-plate"
                        >Placas</FormLabel
                    >
                    <FormInput
                        id="walkin-plate"
                        v-model="plate"
                        class="h-9 text-xs uppercase"
                        placeholder="ABC-123-D"
                        maxlength="20"
                    />
                </div>
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-brand"
                        >Marca</FormLabel
                    >
                    <FormInput
                        class="h-9 text-xs"
                        id="walkin-brand"
                        v-model="vehicleBrand"
                        placeholder="Nissan"
                        maxlength="40"
                    />
                </div>
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-model"
                        >Modelo</FormLabel
                    >
                    <FormInput
                        class="h-9 text-xs"
                        id="walkin-model"
                        v-model="vehicleModel"
                        placeholder="Versa"
                        maxlength="40"
                    />
                </div>
                <div>
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-color"
                        >Color</FormLabel
                    >
                    <FormInput
                        class="h-9 text-xs"
                        id="walkin-color"
                        v-model="vehicleColor"
                        placeholder="Blanco"
                        maxlength="30"
                    />
                </div>
            </div>
            <div
                v-if="knownVehicle?.is_blacklisted"
                class="mt-4 flex items-start gap-2 rounded-lg bg-danger/10 px-3.5 py-2.5 text-xs text-danger"
            >
                <Lucide icon="Ban" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Esta placa está vetada.
                    <template v-if="knownVehicle.blacklist_reason">
                        Motivo:
                        {{ knownVehicle.blacklist_reason }}.
                    </template>
                </span>
            </div>
            <div
                v-else-if="knownVehicle && knownVehicle.visits > 0"
                class="mt-4 flex items-start gap-2 rounded-lg bg-info/10 px-3.5 py-2.5 text-xs text-info"
            >
                <Lucide icon="History" class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Este carro ya se hospedó
                    {{ knownVehicle.visits }}
                    {{ knownVehicle.visits === 1 ? 'vez' : 'veces' }}
                    <template v-if="knownVehicle.label"
                        >— {{ knownVehicle.label }}</template
                    >.
                </span>
            </div>
            <FormHelp v-else-if="plateTooShort" class="text-warning"
                >La placa se ve incompleta: con menos de 4 caracteres no se arma
                su ficha y marca, modelo y color no se guardan para la próxima
                visita.</FormHelp
            >
            <FormHelp v-else
                >La placa arma su ficha; marca, modelo y color quedan guardados
                para la próxima visita.</FormHelp
            >
        </section>

        <!-- Cortesía (módulo cortesias): gratis o con descuento -->
        <CourtesySection
            v-model:on="courtesyOn"
            v-model:form="courtesyForm"
            :lodging="stayPrice + extraGuestsFee"
            :disabled="saving"
            :cap-warning="courtesyCapWarning"
            pending-note="Se le pide al propietario. La llegada se registra igual y el hospedaje se cobra al salir, con lo que él decida."
        />

        <!-- Cobro del hospedaje: lo decide /ajustes/metodos-pago -->
        <section
            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div
                class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="Wallet" class="h-3.5 w-3.5" />
                Cobro del hospedaje
            </div>
            <p
                v-if="chargeOnCheckin && courtesyDefersCharge"
                class="text-xs text-slate-500"
            >
                Con la cortesía esperando al propietario, el hospedaje se cobra
                al registrar la salida.
            </p>
            <template v-else-if="chargeOnCheckin">
                <p class="mb-3 text-xs text-slate-500">
                    El hospedaje se cobra ahora y entra a tu corte; al registrar
                    la salida solo se cobran los consumos.
                </p>
                <div
                    class="grid gap-2.5"
                    :class="
                        paymentMethods.length > 2
                            ? 'grid-cols-2 sm:grid-cols-3'
                            : 'grid-cols-2'
                    "
                >
                    <button
                        v-for="method in paymentMethods"
                        :key="method.key"
                        type="button"
                        class="flex h-9 items-center justify-center gap-2 rounded-[0.5rem] border text-xs font-medium transition"
                        :class="
                            paymentMethod === method.key
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                        "
                        @click="paymentMethod = method.key"
                    >
                        <Lucide :icon="method.icon" class="h-3.5 w-3.5" />
                        {{ method.label }}
                    </button>
                </div>
                <div v-if="paymentMethod !== 'cash'" class="mt-4 sm:max-w-sm">
                    <FormLabel
                        class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                        htmlFor="walkin-reference"
                        >Referencia (opcional)</FormLabel
                    >
                    <FormInput
                        class="h-9 text-xs"
                        id="walkin-reference"
                        v-model="paymentReference"
                        placeholder="Autorización o folio"
                        maxlength="100"
                    />
                </div>
            </template>
            <p v-else class="text-xs text-slate-500">
                Este hotel cobra al final: la estancia nace con saldo y se
                liquida al registrar la salida, junto con los consumos.
            </p>
        </section>

        <!-- Fianza: monto fijo del ajuste, no se teclea aquí -->
        <section
            v-if="guaranteeAmount > 0"
            class="border-t border-slate-200/60 pt-5 dark:border-darkmode-400"
        >
            <div
                class="mb-3 flex flex-wrap items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                <Lucide icon="ShieldCheck" class="h-3.5 w-3.5" />
                Fianza
                <button
                    v-if="!guaranteeEditing && guaranteeMethods.length"
                    type="button"
                    class="ml-auto text-xs font-medium text-primary normal-case hover:underline"
                    @click="guaranteeEditing = true"
                >
                    Cobrar otro monto
                </button>
            </div>
            <p
                v-if="guaranteeMethods.length"
                class="mb-3 text-xs text-slate-500"
            >
                Depósito en garantía de
                <span class="font-medium text-slate-700 dark:text-slate-200">{{
                    money(
                        guaranteeAdjusted
                            ? Number(guaranteeAmountInput || 0)
                            : guaranteeAmount,
                    )
                }}</span
                >: no es venta y se devuelve al registrar la salida.
            </p>

            <div
                v-if="guaranteeEditing"
                class="mb-3 rounded-lg bg-slate-50 p-3 sm:max-w-md dark:bg-darkmode-700"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-slate-500">Cobrar</span>
                    <span class="text-xs text-slate-500">$</span>
                    <FormInput
                        v-model="guaranteeAmountInput"
                        type="number"
                        step="0.01"
                        min="0"
                        class="h-9 !w-28 text-center text-xs"
                    />
                    <button
                        type="button"
                        class="ml-auto text-xs font-medium text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
                        @click="
                            guaranteeEditing = false;
                            guaranteeAmountInput = guaranteeAmount;
                            guaranteeReason = '';
                        "
                    >
                        Usar {{ money(guaranteeAmount) }}
                    </button>
                </div>
                <FormInput
                    v-if="guaranteeAdjusted"
                    v-model="guaranteeReason"
                    type="text"
                    maxlength="255"
                    class="mt-2 h-9 text-xs"
                    placeholder="Motivo del ajuste (queda en el registro del pago)"
                />
                <p v-if="guaranteeBlocked" class="mt-1 text-xs text-danger">
                    Sin motivo no se puede cobrar un monto distinto al de la
                    política.
                </p>
            </div>
            <div
                v-if="guaranteeMethods.length"
                class="grid gap-2.5"
                :class="
                    guaranteeMethods.length > 2
                        ? 'grid-cols-2 sm:grid-cols-3'
                        : 'grid-cols-2'
                "
            >
                <button
                    v-for="method in guaranteeMethods"
                    :key="method.key"
                    type="button"
                    class="flex h-9 items-center justify-center gap-2 rounded-[0.5rem] border text-xs font-medium transition"
                    :class="
                        guaranteeMethod === method.key
                            ? 'border-primary bg-primary/5 text-primary'
                            : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                    "
                    @click="guaranteeMethod = method.key"
                >
                    <Lucide :icon="method.icon" class="h-3.5 w-3.5" />
                    {{ method.label }}
                </button>
            </div>
            <!-- Sin ninguna forma de cobro activa no hay con qué
                             recibir el depósito. -->
            <p v-else class="text-xs text-warning">
                La recepción no tiene ninguna forma de cobro activa en Ajustes →
                Métodos de pago; esta llegada se registra sin fianza.
            </p>
            <!-- El folio es lo único con lo que se puede devolver
                             una fianza recibida por transferencia: el efectivo
                             sale del cajón, esto hay que regresarlo a una
                             cuenta días después. -->
            <div v-if="guaranteeMethod === 'transfer'" class="mt-4 sm:max-w-sm">
                <FormLabel
                    class="mb-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                    htmlFor="walkin-guarantee-reference"
                    >Folio o referencia del comprobante</FormLabel
                >
                <FormInput
                    class="h-9 text-xs"
                    id="walkin-guarantee-reference"
                    v-model="guaranteeReference"
                    placeholder="SPEI 123456"
                    maxlength="100"
                />
                <FormHelp
                    :class="guaranteeReference.trim() ? '' : 'text-warning'"
                    >Sin el folio no vas a poder devolverla.</FormHelp
                >
            </div>
        </section>

        <p
            v-if="modalError"
            class="rounded-lg bg-danger/10 px-3.5 py-2.5 text-xs text-danger"
        >
            {{ modalError }}
        </p>

        <template #footer-start>
            <span
                class="block text-sm font-medium text-slate-700 dark:text-slate-200"
                >Total estimado {{ money(chargeTotal) }}</span
            >
            <span class="hidden sm:block"
                >{{ unitsLabel }} ·
                {{ money(stayPrice / Math.max(1, units)) }} por periodo; el
                sistema confirma el total exacto.</span
            >
        </template>
        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="saving"
                @click="emit('close')"
            >
                Cancelar
            </Button>
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="!canSubmit"
                @click="submit"
            >
                <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                {{
                    saving
                        ? 'Registrando…'
                        : courtesyCapWarning
                          ? 'Sí, pasar el tope'
                          : chargesNow && chargeTotal > 0
                            ? `Registrar y cobrar ${money(chargeTotal)}`
                            : 'Registrar llegada'
                }}
            </Button>
        </template>
    </PlanModal>
</template>
