<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';

/**
 * Reserva para otra fecha SIN salir del plano (modo motel, spec-modo-motel):
 * tarifa en botones grandes, salida sugerida automática, disponibilidad en
 * vivo y datos mínimos (nombre + teléfono; correo opcional). Nace confirmada
 * — sin apartados con hold — y ofrece cobrar ahí mismo ("todos cobran al
 * llegar o reservar"). El room se copia al abrir (mismo motivo que el
 * modal exprés: el computed del plano se anula si cierran el slideover).
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

interface ReserveRoom {
    id: number;
    number: string;
    room_type: string | null;
    capacity: number | null;
    included_occupancy: number | null;
    extra_guest_fee: number | null;
    optional_charges: { concept: string; amount: number }[];
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
    room: ReserveRoom | null;
}>();

const emit = defineEmits<{
    close: [];
    reserved: [];
}>();

const toast = useToasts();
const saving = ref(false);

const modalRoom = ref<ReserveRoom | null>(null);

const ratePlanId = ref<number | null>(null);
const startsAt = ref('');
const endsAt = ref('');
const endsAuto = ref(true);
const roomId = ref<number | null>(null);
const people = ref(1);
const extraConcepts = ref<string[]>([]);
const guestName = ref('');
const guestPhone = ref('');
const guestEmail = ref('');
const plate = ref('');
const chargeNow = ref(false);
const chargeMethod = ref<'cash' | 'card'>('cash');
const chargeAmount = ref<string | number>('');
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

watch(
    () => props.open,
    (open) => {
        if (!open || !props.room) return;
        modalRoom.value = {
            ...props.room,
            optional_charges: props.room.optional_charges.map((c) => ({
                ...c,
            })),
            rate_plans: props.room.rate_plans.map((p) => ({ ...p })),
        };
        ratePlanId.value = modalRoom.value.rate_plans[0]?.id ?? null;
        // Próxima media hora (mismo default que el modal de reservas).
        const start = new Date();
        start.setMinutes(
            start.getMinutes() + (30 - (start.getMinutes() % 30)),
            0,
            0,
        );
        startsAt.value = toLocalInput(start);
        endsAuto.value = true;
        autoFillEnd();
        roomId.value = modalRoom.value.id;
        people.value = 1;
        extraConcepts.value = [];
        guestName.value = '';
        guestPhone.value = '';
        guestEmail.value = '';
        plate.value = '';
        chargeNow.value = false;
        chargeMethod.value = 'cash';
        chargeAmount.value = '';
        availability.value = null;
        modalError.value = null;
    },
);

const selectedPlan = computed(
    () =>
        modalRoom.value?.rate_plans.find(
            (plan) => plan.id === ratePlanId.value,
        ) ?? null,
);

/**
 * Espejo de RatePlan::suggestedEnd — noche = día siguiente 12:00; mes =
 * calendario; resto = minutos de la duración.
 */
function suggestedEndFor(plan: RatePlanOption, start: Date): Date {
    const end = new Date(start);
    if (plan.type === 'night') {
        end.setDate(end.getDate() + 1);
        end.setHours(12, 0, 0, 0);
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
    if (!plan || !startsAt.value) return;
    if (!endsAuto.value && endsAt.value) return;
    endsAt.value = toLocalInput(
        suggestedEndFor(plan, new Date(startsAt.value)),
    );
    endsAuto.value = true;
}

watch([ratePlanId, startsAt], () => {
    availability.value = null;
    autoFillEnd();
});

/** Espejo de RatePlan::unitsFor para el estimado. */
const units = computed(() => {
    if (availability.value) return availability.value.units;
    const plan = selectedPlan.value;
    if (!plan || !startsAt.value || !endsAt.value) return 1;
    const start = new Date(startsAt.value);
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

// Disponibilidad en vivo con debounce (patrón del modal de reservas).
let availTimer: number | undefined;
watch([() => props.open, ratePlanId, startsAt, endsAt], () => {
    if (!props.open || !ratePlanId.value || !startsAt.value) return;
    window.clearTimeout(availTimer);
    availTimer = window.setTimeout(searchAvailability, 400);
});

async function searchAvailability() {
    if (!ratePlanId.value || !startsAt.value) return;
    availLoading.value = true;
    modalError.value = null;
    try {
        const { data } = await axios.get<AvailabilityData>(
            '/api/availability',
            {
                params: {
                    rate_plan_id: ratePlanId.value,
                    starts_at: startsAt.value,
                    ends_at: endsAt.value || undefined,
                },
            },
        );
        availability.value = data;
        if (!data.rooms.some((room) => room.id === roomId.value)) {
            roomId.value = data.rooms[0]?.id ?? null;
        }
        if (data.advance_error) {
            modalError.value = data.advance_error;
        }
    } catch (error: any) {
        modalError.value =
            error.response?.data?.message ??
            'No se pudo consultar la disponibilidad.';
    } finally {
        availLoading.value = false;
    }
}

const tappedRoomBusy = computed(
    () =>
        availability.value !== null &&
        modalRoom.value !== null &&
        !availability.value.rooms.some(
            (room) => room.id === modalRoom.value?.id,
        ),
);

watch([chargeNow, total], () => {
    if (
        chargeNow.value &&
        (chargeAmount.value === '' || chargeAmount.value === 0)
    ) {
        chargeAmount.value = total.value;
    }
});

const canSubmit = computed(
    () =>
        !saving.value &&
        !!ratePlanId.value &&
        !!roomId.value &&
        !!startsAt.value &&
        guestName.value.trim() !== '' &&
        guestPhone.value.trim() !== '' &&
        (!chargeNow.value || Number(chargeAmount.value) > 0),
);

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
    if (!canSubmit.value) return;
    saving.value = true;
    modalError.value = null;
    try {
        const { data } = await axios.post<{ id: number; code: string }>(
            '/api/reservations',
            {
                rate_plan_id: ratePlanId.value,
                room_id: roomId.value,
                starts_at: startsAt.value,
                ends_at: endsAt.value || undefined,
                guest_name: guestName.value.trim(),
                guest_phone: guestPhone.value.trim(),
                guest_email: guestEmail.value.trim() || undefined,
                vehicle_plate: plate.value.trim()
                    ? plate.value.trim().toUpperCase()
                    : undefined,
                adults: people.value,
                children: 0,
                extra_charges: extraConcepts.value,
                confirmed: true,
            },
        );

        let chargeOk = true;
        if (chargeNow.value) {
            try {
                await axios.post(`/api/reservations/${data.id}/payments`, {
                    amount: Number(chargeAmount.value),
                    method: chargeMethod.value,
                });
            } catch {
                chargeOk = false;
            }
        }

        if (chargeOk) {
            toast.success(
                `Reserva ${data.code} confirmada`,
                chargeNow.value
                    ? `Cobrados ${money(Number(chargeAmount.value))} en ${chargeMethod.value === 'cash' ? 'efectivo' : 'tarjeta'}.`
                    : 'Se cobra cuando llegue el huésped.',
            );
        } else {
            toast.error(
                'Reserva creada, pero el cobro no se registró',
                `La ${data.code} quedó confirmada; cobra al llegar o desde Reservas.`,
            );
        }
        emit('reserved');
        emit('close');
    } catch (error: any) {
        const data = error.response?.data;
        modalError.value =
            data?.message ??
            (data?.errors
                ? (Object.values(data.errors)[0] as string[])[0]
                : null) ??
            'No se pudo crear la reserva.';
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
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));
</script>

<template>
    <!-- staticBackdrop: formulario largo; un clic afuera no lo tira. -->
    <Dialog :open="open" size="lg" static-backdrop @close="emit('close')">
        <Dialog.Panel>
            <div class="flex max-h-[85vh] flex-col">
                <!-- Header -->
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="CalendarPlus" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            Reservar — {{ modalRoom?.number }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ modalRoom?.room_type ?? 'Habitación' }} · nace
                            confirmada; se cobra ahora o al llegar
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
                    <!-- Tarifa -->
                    <div>
                        <div
                            class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Clock" class="h-3.5 w-3.5" />
                            Tarifa
                        </div>
                        <div
                            v-if="modalRoom?.rate_plans.length"
                            class="grid grid-cols-2 gap-2.5 sm:grid-cols-3"
                        >
                            <button
                                v-for="plan in modalRoom.rate_plans"
                                :key="plan.id"
                                type="button"
                                class="rounded-lg border p-3 text-left transition"
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
                                    class="mt-1.5 block text-lg leading-none font-semibold"
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
                    </div>

                    <!-- Fechas -->
                    <div>
                        <div
                            class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                            ¿Cuándo?
                        </div>
                        <div class="grid items-start gap-3 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="reserve-starts"
                                    >Llegada</FormLabel
                                >
                                <FormInput
                                    id="reserve-starts"
                                    v-model="startsAt"
                                    type="datetime-local"
                                />
                            </div>
                            <div>
                                <FormLabel
                                    htmlFor="reserve-ends"
                                    class="flex items-center gap-2"
                                >
                                    Salida
                                    <span
                                        v-if="endsAuto && endsAt"
                                        class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary"
                                    >
                                        <Lucide
                                            icon="Sparkles"
                                            class="h-3 w-3"
                                        />
                                        auto
                                    </span>
                                </FormLabel>
                                <FormInput
                                    id="reserve-ends"
                                    v-model="endsAt"
                                    type="datetime-local"
                                    @change="endsAuto = false"
                                />
                            </div>
                        </div>
                        <div class="mt-2.5">
                            <div
                                v-if="tappedRoomBusy"
                                class="mb-2 flex items-start gap-2 rounded-lg bg-warning/10 px-3.5 py-2.5 text-sm text-warning"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                />
                                La {{ modalRoom?.number }} no está libre en ese
                                rango; elige otra habitación de la lista.
                            </div>
                            <FormLabel htmlFor="reserve-room"
                                >Habitación</FormLabel
                            >
                            <FormSelect
                                id="reserve-room"
                                v-model="roomId"
                                :disabled="
                                    !availability || !availability.rooms.length
                                "
                            >
                                <option
                                    v-if="!availability"
                                    :value="modalRoom?.id ?? null"
                                >
                                    {{ modalRoom?.number }} (consultando…)
                                </option>
                                <option
                                    v-for="room in availability?.rooms ?? []"
                                    :key="room.id"
                                    :value="room.id"
                                >
                                    {{ room.number }}
                                </option>
                            </FormSelect>
                            <FormHelp>
                                <span v-if="availLoading"
                                    >Consultando disponibilidad…</span
                                >
                                <span v-else-if="availability"
                                    >{{
                                        availability.rooms.length
                                    }}
                                    habitación(es) libre(s) del tipo en ese
                                    rango.</span
                                >
                                <span v-else
                                    >La disponibilidad se consulta sola al
                                    elegir tarifa y fechas.</span
                                >
                            </FormHelp>
                        </div>
                    </div>

                    <!-- Personas y cargos -->
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <div>
                            <div
                                class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide icon="Users" class="h-3.5 w-3.5" />
                                Personas
                            </div>
                            <div class="flex items-center gap-2.5">
                                <Button
                                    type="button"
                                    variant="outline-secondary"
                                    class="h-10 w-10 rounded-lg p-0"
                                    :disabled="people <= 1"
                                    @click="people = Math.max(1, people - 1)"
                                >
                                    <Lucide icon="Minus" class="h-4 w-4" />
                                </Button>
                                <span
                                    class="w-9 text-center text-lg font-semibold"
                                    >{{ people }}</span
                                >
                                <Button
                                    type="button"
                                    variant="outline-secondary"
                                    class="h-10 w-10 rounded-lg p-0"
                                    :disabled="people >= maxPeople"
                                    @click="
                                        people = Math.min(maxPeople, people + 1)
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
                                class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
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

                    <!-- Huésped -->
                    <div>
                        <div
                            class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="User" class="h-3.5 w-3.5" />
                            Huésped
                        </div>
                        <div class="grid items-start gap-3 sm:grid-cols-2">
                            <div>
                                <FormLabel htmlFor="reserve-name"
                                    >Nombre completo</FormLabel
                                >
                                <FormInput
                                    id="reserve-name"
                                    v-model="guestName"
                                    placeholder="Nombre y apellidos"
                                    maxlength="255"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="reserve-phone"
                                    >Teléfono</FormLabel
                                >
                                <FormInput
                                    id="reserve-phone"
                                    v-model="guestPhone"
                                    type="tel"
                                    placeholder="6561234567"
                                    maxlength="30"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="reserve-email"
                                    >Correo (opcional)</FormLabel
                                >
                                <FormInput
                                    id="reserve-email"
                                    v-model="guestEmail"
                                    type="email"
                                    placeholder="correo@ejemplo.com"
                                    maxlength="255"
                                />
                            </div>
                            <div>
                                <FormLabel htmlFor="reserve-plate"
                                    >Placas (opcional)</FormLabel
                                >
                                <FormInput
                                    id="reserve-plate"
                                    v-model="plate"
                                    class="uppercase"
                                    placeholder="ABC-123-D"
                                    maxlength="20"
                                />
                            </div>
                        </div>
                        <FormHelp
                            >Con nombre y teléfono basta para avisarle y
                            encontrar su reserva al llegar.</FormHelp
                        >
                    </div>

                    <!-- Cobrar ahora -->
                    <div>
                        <div
                            class="flex items-center justify-between gap-3 rounded-lg border border-slate-200/70 px-3.5 py-3 dark:border-darkmode-400"
                        >
                            <div
                                class="flex items-center gap-2 text-sm font-medium"
                            >
                                <Lucide
                                    icon="Banknote"
                                    class="h-4 w-4 text-slate-400"
                                />
                                Cobrar ahora
                            </div>
                            <FormSwitch>
                                <FormSwitch.Input
                                    v-model="chargeNow"
                                    type="checkbox"
                                />
                            </FormSwitch>
                        </div>
                        <div
                            v-if="chargeNow"
                            class="mt-3 grid items-start gap-3 sm:grid-cols-2"
                        >
                            <div>
                                <FormLabel htmlFor="reserve-amount"
                                    >Monto</FormLabel
                                >
                                <FormInput
                                    id="reserve-amount"
                                    v-model="chargeAmount"
                                    type="number"
                                    step="0.01"
                                    min="1"
                                />
                                <FormHelp
                                    >Precargado al total; bájalo si solo dejan
                                    un anticipo.</FormHelp
                                >
                            </div>
                            <div>
                                <FormLabel>Método</FormLabel>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        class="flex items-center justify-center gap-2 rounded-lg border py-2.5 text-sm font-medium transition"
                                        :class="
                                            chargeMethod === 'cash'
                                                ? 'border-primary bg-primary/5 text-primary'
                                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                                        "
                                        @click="chargeMethod = 'cash'"
                                    >
                                        <Lucide
                                            icon="Banknote"
                                            class="h-4 w-4"
                                        />
                                        Efectivo
                                    </button>
                                    <button
                                        type="button"
                                        class="flex items-center justify-center gap-2 rounded-lg border py-2.5 text-sm font-medium transition"
                                        :class="
                                            chargeMethod === 'card'
                                                ? 'border-primary bg-primary/5 text-primary'
                                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                                        "
                                        @click="chargeMethod = 'card'"
                                    >
                                        <Lucide
                                            icon="CreditCard"
                                            class="h-4 w-4"
                                        />
                                        Tarjeta
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="modalError"
                        class="rounded-lg bg-danger/10 px-3.5 py-2.5 text-sm text-danger"
                    >
                        {{ modalError }}
                    </p>
                </div>

                <!-- Footer. Se apila en móvil: con el plano en pantalla
                     completa este modal también se usa desde el teléfono. -->
                <div
                    class="flex flex-col gap-3 border-t border-slate-200/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-darkmode-400"
                >
                    <div class="text-xs text-slate-500">
                        <span
                            class="block font-medium text-slate-600 dark:text-slate-300"
                            >Total estimado: {{ money(total) }}</span
                        >
                        <span class="block"
                            >El sistema confirma el total exacto.</span
                        >
                    </div>
                    <Button
                        variant="primary"
                        class="min-h-11 shrink-0 rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{
                            saving
                                ? 'Guardando…'
                                : chargeNow
                                  ? `Reservar y cobrar ${money(Number(chargeAmount) || 0)}`
                                  : 'Confirmar reserva'
                        }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
