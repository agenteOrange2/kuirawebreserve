<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormDateTime,
    FormHelp,
    FormInput,
    FormLabel,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { usePropertyMode } from '@/composables/usePropertyMode';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useToasts } from '@/composables/useToasts';

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
    advance_error: string | null;
    rooms: { id: number; number: string }[];
}

const props = defineProps<{
    open: boolean;
    room: WalkInRoom | null;
    /** Fianza del hotel (/ajustes/metodos-pago); 0 = ajuste apagado. */
    guaranteeAmount: number;
    /** walkin_charge=checkin: el hospedaje se cobra al registrar la llegada. */
    chargeOnCheckin: boolean;
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
    subset,
} = useCounterMethods();
// La fianza se cobra en la mano: efectivo o terminal, nunca transferencia.
const guaranteeMethods = subset(['cash', 'card']);

const modalRoom = ref<WalkInRoom | null>(null);

const ratePlanId = ref<number | null>(null);
const endsAt = ref('');
const endsAuto = ref(true);
const people = ref(1);
const extraConcepts = ref<string[]>([]);
const guestName = ref('');
const guestPhone = ref('');
const guestEmail = ref('');
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
const guaranteeMethod = ref<'cash' | 'card'>('cash');
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
    () => guaranteeAdjusted.value && !guaranteeReason.value.trim(),
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
        plate.value = '';
        vehicleBrand.value = '';
        vehicleModel.value = '';
        vehicleColor.value = '';
        knownVehicle.value = null;
        paymentMethod.value = firstMethod.value;
        paymentReference.value = '';
        guaranteeEditing.value = false;
        guaranteeAmountInput.value = props.guaranteeAmount;
        guaranteeReason.value = '';
        guaranteeMethod.value = (guaranteeMethods.value[0]?.key ?? 'cash') as
            | 'cash'
            | 'card';
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

const maxPeople = computed(() => modalRoom.value?.capacity ?? 20);

const extraGuestsFee = computed(() => {
    const included = modalRoom.value?.included_occupancy;
    const fee = modalRoom.value?.extra_guest_fee ?? 0;
    if (!included || !fee || people.value <= included) return 0;
    return (people.value - included) * fee * units.value;
});

const optionalTotal = computed(() =>
    (modalRoom.value?.optional_charges ?? [])
        .filter((charge) => extraConcepts.value.includes(charge.concept))
        .reduce((sum, charge) => sum + charge.amount, 0),
);

const total = computed(
    () =>
        (selectedPlan.value?.price ?? 0) * units.value +
        extraGuestsFee.value +
        optionalTotal.value,
);

const peopleHint = computed(() => {
    const room = modalRoom.value;
    if (!room) return '';
    const parts: string[] = [];
    if (room.capacity) parts.push(`Hasta ${room.capacity} personas`);
    if (room.included_occupancy && room.extra_guest_fee) {
        parts.push(
            `incluye ${room.included_occupancy} · extra ${money(room.extra_guest_fee)} c/u`,
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
        !guaranteeBlocked.value,
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
            // El cobro al llegar lo decide el ajuste del hotel, no el modal.
            payment_method: props.chargeOnCheckin
                ? coerceMethod(paymentMethod.value)
                : undefined,
            payment_reference:
                props.chargeOnCheckin &&
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
            guarantee_amount: guaranteeAdjusted.value
                ? Number(guaranteeAmountInput.value || 0)
                : undefined,
            guarantee_reason: guaranteeAdjusted.value
                ? guaranteeReason.value.trim()
                : undefined,
        });

        toast.success(
            `Habitación ${roomNumber} ocupada`,
            props.chargeOnCheckin
                ? `Llegada registrada y hospedaje cobrado (${money(total.value)}).`
                : 'Llegada registrada; el hospedaje se cobra al registrar la salida.',
        );
        emit('registered');
        emit('close');
    } catch (error: any) {
        const data = error.response?.data;
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
    if (event.key === 'Escape' && props.open) {
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
    <Dialog :open="open" size="xl" static-backdrop @close="emit('close')">
        <Dialog.Panel class="sm:w-[92%] lg:w-[940px] xl:w-[1080px]">
            <div class="flex max-h-[86vh] flex-col">
                <!-- Header -->
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 sm:px-7 sm:py-5 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="LogIn" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium sm:text-lg">
                            Llegó sin reserva — {{ roomLabel }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500 sm:text-sm">
                            Entra ahora y el cuarto queda ocupado
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>

                <!-- Body -->
                <div
                    class="flex-1 space-y-7 overflow-y-auto px-5 py-6 sm:px-7 [&_input:not([type=checkbox]):not([type=radio])]:min-h-11 [&_select]:min-h-11"
                >
                    <!-- Tarifa -->
                    <section>
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
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
                                    :class="
                                        ratePlanId === plan.id
                                            ? 'text-primary'
                                            : ''
                                    "
                                    >{{ plan.name }}</span
                                >
                                <span
                                    class="mt-0.5 block text-xs text-slate-500"
                                    >{{ plan.duration_label }}</span
                                >
                                <span
                                    class="mt-2 block text-lg leading-none font-semibold"
                                    >{{ money(plan.price) }}</span
                                >
                            </button>
                        </div>
                        <div
                            v-else
                            class="flex items-start gap-2 rounded-lg bg-danger/10 px-3.5 py-3 text-sm text-danger"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
                            Este tipo de habitación no tiene tarifas activas;
                            agrégalas en Zonas y tipos.
                        </div>
                    </section>

                    <!-- Entrada y salida -->
                    <section
                        class="border-t border-slate-200/60 pt-6 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                            Entrada y salida
                        </div>
                        <div class="grid gap-x-6 gap-y-5 lg:grid-cols-2">
                            <div>
                                <FormLabel class="flex items-center gap-2">
                                    Entrada
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-medium text-success"
                                    >
                                        <Lucide icon="Clock" class="h-3 w-3" />
                                        ahora
                                    </span>
                                </FormLabel>
                                <div
                                    class="flex min-h-11 items-center gap-2 rounded-md border border-slate-200/70 bg-slate-50/70 px-3 text-sm font-medium dark:border-darkmode-400 dark:bg-darkmode-700"
                                >
                                    <Lucide
                                        icon="CalendarCheck2"
                                        class="h-4 w-4 shrink-0 stroke-[1.3] text-slate-400"
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
                                    class="flex items-center gap-2"
                                >
                                    Salida prevista
                                    <span
                                        v-if="endsAuto && endsAt"
                                        title="Calculada según la duración de la tarifa. Puedes ajustarla a mano."
                                        class="inline-flex cursor-help items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                    >
                                        <Lucide
                                            icon="Sparkles"
                                            class="h-3 w-3"
                                        />
                                        auto
                                    </span>
                                </FormLabel>
                                <FormDateTime
                                    id="walkin-ends"
                                    v-model="endsAt"
                                    @change="endsAuto = false"
                                />
                                <FormHelp
                                    >Se calcula con la tarifa; ajústala si se
                                    quedan más.</FormHelp
                                >
                            </div>
                        </div>
                        <div
                            v-if="roomBusy"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-warning/10 px-3.5 py-3 text-sm text-warning"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
                            La {{ modalRoom?.number }} tiene algo agendado
                            dentro de ese rango; adelanta la salida o registra
                            la llegada en otra habitación.
                        </div>
                        <p
                            v-else-if="availLoading"
                            class="mt-4 text-xs text-slate-500"
                        >
                            Revisando que la habitación esté libre en ese rango…
                        </p>
                    </section>

                    <!-- Personas y cargos -->
                    <section
                        class="border-t border-slate-200/60 pt-6 dark:border-darkmode-400"
                    >
                        <div class="grid gap-x-6 gap-y-6 lg:grid-cols-2">
                            <div>
                                <div
                                    class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
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
                                        @click="
                                            people = Math.max(1, people - 1)
                                        "
                                    >
                                        <Lucide icon="Minus" class="h-4 w-4" />
                                    </Button>
                                    <span
                                        class="w-10 text-center text-base font-semibold"
                                        >{{ people }}</span
                                    >
                                    <Button
                                        type="button"
                                        variant="outline-secondary"
                                        class="h-9 w-9 rounded-lg p-0"
                                        :disabled="people >= maxPeople"
                                        @click="
                                            people = Math.min(
                                                maxPeople,
                                                people + 1,
                                            )
                                        "
                                    >
                                        <Lucide icon="Plus" class="h-4 w-4" />
                                    </Button>
                                </div>
                                <FormHelp v-if="peopleHint">{{
                                    peopleHint
                                }}</FormHelp>
                            </div>
                            <div v-if="modalRoom?.optional_charges.length">
                                <div
                                    class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide
                                        icon="CirclePlus"
                                        class="h-3.5 w-3.5"
                                    />
                                    Cargos opcionales
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button
                                        v-for="charge in modalRoom.optional_charges"
                                        :key="charge.concept"
                                        type="button"
                                        class="rounded-full border px-3.5 py-2 text-xs font-medium transition"
                                        :class="
                                            extraConcepts.includes(
                                                charge.concept,
                                            )
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
                        class="border-t border-slate-200/60 pt-6 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="User" class="h-3.5 w-3.5" />
                            Huésped
                        </div>
                        <div
                            class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3"
                        >
                            <div>
                                <FormLabel htmlFor="walkin-name"
                                    >Nombre completo</FormLabel
                                >
                                <FormInput
                                    id="walkin-name"
                                    v-model="guestName"
                                    placeholder="Nombre y apellidos"
                                    maxlength="255"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="walkin-phone"
                                    >Teléfono</FormLabel
                                >
                                <FormInput
                                    id="walkin-phone"
                                    v-model="guestPhone"
                                    type="tel"
                                    placeholder="6561234567"
                                    maxlength="30"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="walkin-email"
                                    >Correo (opcional)</FormLabel
                                >
                                <FormInput
                                    id="walkin-email"
                                    v-model="guestEmail"
                                    type="email"
                                    placeholder="correo@ejemplo.com"
                                    maxlength="255"
                                />
                            </div>
                        </div>
                        <FormHelp
                            >Con nombre y teléfono queda su ficha en el CRM y se
                            le puede avisar; puedes dejarlo en blanco si solo
                            pasa la noche.</FormHelp
                        >
                    </section>

                    <!-- Vehículo: la ficha completa, no solo la placa -->
                    <section
                        class="border-t border-slate-200/60 pt-6 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Car" class="h-3.5 w-3.5" />
                            Vehículo (opcional)
                        </div>
                        <div
                            class="grid gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-4"
                        >
                            <div>
                                <FormLabel htmlFor="walkin-plate"
                                    >Placas</FormLabel
                                >
                                <FormInput
                                    id="walkin-plate"
                                    v-model="plate"
                                    class="uppercase"
                                    placeholder="ABC-123-D"
                                    maxlength="20"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="walkin-brand"
                                    >Marca</FormLabel
                                >
                                <FormInput
                                    id="walkin-brand"
                                    v-model="vehicleBrand"
                                    placeholder="Nissan"
                                    maxlength="40"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="walkin-model"
                                    >Modelo</FormLabel
                                >
                                <FormInput
                                    id="walkin-model"
                                    v-model="vehicleModel"
                                    placeholder="Versa"
                                    maxlength="40"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="walkin-color"
                                    >Color</FormLabel
                                >
                                <FormInput
                                    id="walkin-color"
                                    v-model="vehicleColor"
                                    placeholder="Blanco"
                                    maxlength="30"
                                />
                            </div>
                        </div>
                        <div
                            v-if="knownVehicle?.is_blacklisted"
                            class="mt-4 flex items-start gap-2 rounded-lg bg-danger/10 px-3.5 py-3 text-sm text-danger"
                        >
                            <Lucide
                                icon="Ban"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
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
                            class="mt-4 flex items-start gap-2 rounded-lg bg-info/10 px-3.5 py-3 text-sm text-info"
                        >
                            <Lucide
                                icon="History"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
                            <span>
                                Este carro ya se hospedó
                                {{ knownVehicle.visits }}
                                {{
                                    knownVehicle.visits === 1 ? 'vez' : 'veces'
                                }}
                                <template v-if="knownVehicle.label"
                                    >— {{ knownVehicle.label }}</template
                                >.
                            </span>
                        </div>
                        <FormHelp v-else-if="plateTooShort" class="text-warning"
                            >La placa se ve incompleta: con menos de 4
                            caracteres no se arma su ficha y marca, modelo y
                            color no se guardan para la próxima
                            visita.</FormHelp
                        >
                        <FormHelp v-else
                            >La placa arma su ficha; marca, modelo y color
                            quedan guardados para la próxima visita.</FormHelp
                        >
                    </section>

                    <!-- Cobro del hospedaje: lo decide /ajustes/metodos-pago -->
                    <section
                        class="border-t border-slate-200/60 pt-6 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Wallet" class="h-3.5 w-3.5" />
                            Cobro del hospedaje
                        </div>
                        <template v-if="chargeOnCheckin">
                            <p class="mb-3 text-xs text-slate-500 sm:text-sm">
                                El hospedaje se cobra ahora y entra a tu corte;
                                al registrar la salida solo se cobran los
                                consumos.
                            </p>
                            <div
                                class="grid gap-2.5"
                                :class="
                                    paymentMethods.length > 2
                                        ? 'sm:grid-cols-3'
                                        : 'sm:grid-cols-2'
                                "
                            >
                                <button
                                    v-for="method in paymentMethods"
                                    :key="method.key"
                                    type="button"
                                    class="flex min-h-12 items-center justify-center gap-2 rounded-lg border text-sm font-medium transition"
                                    :class="
                                        paymentMethod === method.key
                                            ? 'border-primary bg-primary/5 text-primary'
                                            : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                                    "
                                    @click="paymentMethod = method.key"
                                >
                                    <Lucide
                                        :icon="method.icon"
                                        class="h-4 w-4"
                                    />
                                    {{ method.label }}
                                </button>
                            </div>
                            <div
                                v-if="paymentMethod !== 'cash'"
                                class="mt-4 sm:max-w-sm"
                            >
                                <FormLabel htmlFor="walkin-reference"
                                    >Referencia (opcional)</FormLabel
                                >
                                <FormInput
                                    id="walkin-reference"
                                    v-model="paymentReference"
                                    placeholder="Autorización o folio"
                                    maxlength="100"
                                />
                            </div>
                        </template>
                        <p v-else class="text-xs text-slate-500 sm:text-sm">
                            Este hotel cobra al final: la estancia nace con
                            saldo y se liquida al registrar la salida, junto con
                            los consumos.
                        </p>
                    </section>

                    <!-- Fianza: monto fijo del ajuste, no se teclea aquí -->
                    <section
                        v-if="guaranteeAmount > 0"
                        class="border-t border-slate-200/60 pt-6 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-3 flex flex-wrap items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="ShieldCheck" class="h-3.5 w-3.5" />
                            Fianza
                            {{
                                money(
                                    guaranteeAdjusted
                                        ? Number(guaranteeAmountInput || 0)
                                        : guaranteeAmount,
                                )
                            }}
                            <button
                                v-if="
                                    !guaranteeEditing && guaranteeMethods.length
                                "
                                type="button"
                                class="text-xs font-medium text-primary normal-case hover:underline"
                                @click="guaranteeEditing = true"
                            >
                                Cobrar otro monto
                            </button>
                        </div>

                        <div
                            v-if="guaranteeEditing"
                            class="mb-3 rounded-lg bg-slate-50 p-3 sm:max-w-md dark:bg-darkmode-700"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-500"
                                    >Cobrar</span
                                >
                                <span class="text-xs text-slate-500">$</span>
                                <FormInput
                                    v-model="guaranteeAmountInput"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="!w-28 text-center"
                                />
                                <button
                                    type="button"
                                    class="ml-auto text-xs font-medium text-slate-400 hover:text-slate-600"
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
                                class="mt-2"
                                placeholder="Motivo del ajuste (queda en el registro del pago)"
                            />
                            <p
                                v-if="guaranteeBlocked"
                                class="mt-1 text-xs text-danger"
                            >
                                Sin motivo no se puede cobrar un monto distinto
                                al de la política.
                            </p>
                        </div>
                        <div
                            v-if="guaranteeMethods.length"
                            class="grid gap-2.5 sm:max-w-md sm:grid-cols-2"
                        >
                            <button
                                v-for="method in guaranteeMethods"
                                :key="method.key"
                                type="button"
                                class="flex min-h-12 items-center justify-center gap-2 rounded-lg border text-sm font-medium transition"
                                :class="
                                    guaranteeMethod === method.key
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                                "
                                @click="
                                    guaranteeMethod = method.key as
                                        | 'cash'
                                        | 'card'
                                "
                            >
                                <Lucide :icon="method.icon" class="h-4 w-4" />
                                {{ method.label }}
                            </button>
                        </div>
                        <!-- La fianza se recibe en la mano: si la recepción no
                             acepta ni efectivo ni terminal, no hay con qué. -->
                        <p v-else class="text-sm text-warning">
                            La fianza se cobra en efectivo o con terminal, y la
                            recepción no tiene ninguno de los dos activo en
                            Ajustes → Métodos de pago; esta llegada se registra
                            sin fianza.
                        </p>
                        <FormHelp v-if="guaranteeMethods.length"
                            >Depósito en garantía: no es venta y se devuelve al
                            registrar la salida.</FormHelp
                        >
                    </section>

                    <p
                        v-if="modalError"
                        class="rounded-lg bg-danger/10 px-3.5 py-3 text-sm text-danger"
                    >
                        {{ modalError }}
                    </p>
                </div>

                <!-- Footer. Se apila en móvil: con el plano en pantalla
                     completa este modal también se usa desde el teléfono. -->
                <div
                    class="flex flex-col gap-3 border-t border-slate-200/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7 dark:border-darkmode-400"
                >
                    <div class="text-xs text-slate-500">
                        <span
                            class="block text-sm font-medium text-slate-600 dark:text-slate-300"
                            >Total estimado: {{ money(total) }}</span
                        >
                        <span class="block"
                            >{{ unitsLabel }} ·
                            {{ money(selectedPlan?.price ?? 0) }} por periodo;
                            el sistema confirma el total exacto.</span
                        >
                    </div>
                    <Button
                        variant="primary"
                        class="min-h-12 shrink-0 rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{
                            saving
                                ? 'Registrando…'
                                : chargeOnCheckin
                                  ? `Registrar y cobrar ${money(total)}`
                                  : 'Registrar llegada'
                        }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
