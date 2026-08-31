<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import { useToasts } from '@/composables/useToasts';

/**
 * Registro exprés de caseta (modo motel, spec-modo-motel): tap en el cuarto
 * libre → tarifa en botones grandes → placa o identificación (con foto) →
 * un solo botón "Registrar y cobrar". Nunca sale del plano; el servidor
 * recalcula el total (extraChargeLines) — lo mostrado es un estimado.
 *
 * OJO: el room se COPIA al abrir (modalRoom): el prop viene del computed
 * selectedRoom del plano y se anula si cierran el slideover por debajo.
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

interface ExpressRoom {
    id: number;
    number: string;
    room_type: string | null;
    capacity: number | null;
    included_occupancy: number | null;
    extra_guest_fee: number | null;
    optional_charges: { concept: string; amount: number }[];
    rate_plans: RatePlanOption[];
}

interface StagedPhoto {
    file: File;
    url: string;
}

const props = defineProps<{
    open: boolean;
    room: ExpressRoom | null;
    guaranteeAmount: number;
    /**
     * Motel puro: la caseta abre el acceso y el encargado cobra en la
     * habitación. En "ambos" decide quien atiende, así que el interruptor se
     * ve pero arranca en "cobra la caseta", como el hotel de siempre.
     */
    collectorDefault: 'caseta' | 'encargado';
}>();

const emit = defineEmits<{
    close: [];
    registered: [];
}>();

const toast = useToasts();
const saving = ref(false);

// Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
// Políticas): sin terminal en la caseta, "Tarjeta" no se ofrece.
const {
    methods: paymentMethods,
    first: firstMethod,
    coerce: coerceMethod,
    subset,
} = useCounterMethods();
// La fianza se recibe en la mano: efectivo o terminal, nunca transferencia.
const guaranteeMethods = subset(['cash', 'card']);

// Snapshot del cuarto: inmune a que el slideover se cierre por debajo.
const modalRoom = ref<ExpressRoom | null>(null);

const ratePlanId = ref<number | null>(null);
// 'vehicle' = llegó en carro (placa); 'foot' = va a pie (identificación).
const arrival = ref<'vehicle' | 'foot'>('vehicle');
const plate = ref('');
const vehicleDesc = ref('');
// Ficha del vehículo (registro de placas del motel): marca y modelo se
// capturan estructurados y viven en `vehicles`, no en la estancia.
const vehicleBrand = ref('');
const vehicleModel = ref('');
const vehicleColor = ref('');
// Lo que ya sabemos de esa placa: se consulta al teclearla, para que la
// segunda visita no vuelva a pedir lo mismo — y para avisar si está vetada.
const knownVehicle = ref<{
    plate: string;
    label: string | null;
    visits: number;
    is_blacklisted: boolean;
    blacklist_reason: string | null;
} | null>(null);
const guestName = ref('');
const docType = ref('ine');
const docNumber = ref('');
const stagedPhotos = ref<StagedPhoto[]>([]);
const people = ref(1);
const extraConcepts = ref<string[]>([]);
const paymentMethod = ref<CounterMethod>('cash');
// Quién tiene el dinero en la mano. Con 'encargado' la llegada nace SIN cobro
// y sin sellar: el acceso se abre ya, y placa, marca, modelo y color se
// capturan cuando el encargado regrese con el papel.
const collector = ref<'caseta' | 'encargado'>('caseta');
const paymentReference = ref('');
const guaranteeMethod = ref<'cash' | 'card'>('cash');

const documentTypes: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Otro documento',
};

function clearPhotos() {
    stagedPhotos.value.forEach((photo) => URL.revokeObjectURL(photo.url));
    stagedPhotos.value = [];
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
        // La lista ya viene ordenada por precio: la más barata preseleccionada.
        ratePlanId.value = modalRoom.value.rate_plans[0]?.id ?? null;
        arrival.value = 'vehicle';
        plate.value = '';
        vehicleDesc.value = '';
        vehicleBrand.value = '';
        vehicleModel.value = '';
        vehicleColor.value = '';
        knownVehicle.value = null;
        guestName.value = '';
        docType.value = 'ine';
        docNumber.value = '';
        clearPhotos();
        people.value = 1;
        extraConcepts.value = [];
        paymentMethod.value = firstMethod.value;
        paymentReference.value = '';
        guaranteeMethod.value = (guaranteeMethods.value[0]?.key ?? 'cash') as
            | 'cash'
            | 'card';
        collector.value = props.collectorDefault;
    },
);

// Al teclear la placa se pregunta si ese carro ya vino: la segunda visita
// autocompleta marca y modelo (sin pisar lo que el cajero ya escribió) y una
// placa vetada avisa antes de cobrar, no después.
let plateTimer: ReturnType<typeof setTimeout> | null = null;

watch(plate, (value) => {
    if (plateTimer) {
        clearTimeout(plateTimer);
    }

    const term = value.trim();

    if (term.length < 4) {
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
            // Sin registro de placas (o sin permiso) el exprés sigue igual.
            knownVehicle.value = null;
        }
    }, 400);
});

const maxPeople = computed(() => modalRoom.value?.capacity ?? 20);

const selectedPlan = computed(
    () =>
        modalRoom.value?.rate_plans.find(
            (plan) => plan.id === ratePlanId.value,
        ) ?? null,
);

// Estimado de personas extra (el servidor recalcula con extraChargeLines).
const extraGuestsFee = computed(() => {
    const included = modalRoom.value?.included_occupancy;
    const fee = modalRoom.value?.extra_guest_fee ?? 0;
    if (!included || !fee || people.value <= included) return 0;
    return (people.value - included) * fee;
});

const optionalTotal = computed(() =>
    (modalRoom.value?.optional_charges ?? [])
        .filter((charge) => extraConcepts.value.includes(charge.concept))
        .reduce((sum, charge) => sum + charge.amount, 0),
);

const total = computed(
    () =>
        (selectedPlan.value?.price ?? 0) +
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

const footNameMissing = computed(
    () => arrival.value === 'foot' && guestName.value.trim() === '',
);

const laterCapture = computed(() => collector.value === 'encargado');

const canSubmit = computed(
    () =>
        !saving.value &&
        !!ratePlanId.value &&
        // Con captura diferida no se exige el nombre: justo eso es lo que se
        // va a anotar en la habitación.
        (laterCapture.value || !footNameMissing.value),
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

function stagePhoto(event: Event) {
    const files = (event.target as HTMLInputElement).files;
    if (!files) return;
    Array.from(files).forEach((file) => {
        if (file.type.startsWith('image/') && stagedPhotos.value.length < 2) {
            stagedPhotos.value.push({ file, url: URL.createObjectURL(file) });
        }
    });
    (event.target as HTMLInputElement).value = '';
}

function removePhoto(index: number) {
    URL.revokeObjectURL(stagedPhotos.value[index].url);
    stagedPhotos.value.splice(index, 1);
}

async function uploadPhotos(stayId: number): Promise<boolean> {
    for (const photo of stagedPhotos.value) {
        const data = new FormData();
        data.append('file', photo.file);
        await axios.post(`/api/stays/${stayId}/id-document`, data);
    }
    return true;
}

async function submit() {
    if (!modalRoom.value || !canSubmit.value) return;
    saving.value = true;
    const roomNumber = modalRoom.value.number;
    try {
        const { data } = await axios.post<{ id: number }>('/api/stays', {
            room_id: modalRoom.value.id,
            rate_plan_id: ratePlanId.value,
            num_people: people.value,
            guest_name:
                arrival.value === 'foot' ? guestName.value.trim() : undefined,
            vehicle_plate:
                arrival.value === 'vehicle' && plate.value.trim() !== ''
                    ? plate.value.trim().toUpperCase()
                    : undefined,
            vehicle_desc:
                arrival.value === 'vehicle' && vehicleDesc.value.trim() !== ''
                    ? vehicleDesc.value.trim()
                    : undefined,
            vehicle_brand:
                arrival.value === 'vehicle' && vehicleBrand.value.trim() !== ''
                    ? vehicleBrand.value.trim()
                    : undefined,
            vehicle_model:
                arrival.value === 'vehicle' && vehicleModel.value.trim() !== ''
                    ? vehicleModel.value.trim()
                    : undefined,
            vehicle_color:
                arrival.value === 'vehicle' && vehicleColor.value.trim() !== ''
                    ? vehicleColor.value.trim()
                    : undefined,
            id_document_type:
                arrival.value === 'foot' && docNumber.value.trim() !== ''
                    ? docType.value
                    : arrival.value === 'foot' && stagedPhotos.value.length
                      ? docType.value
                      : undefined,
            id_document_number:
                arrival.value === 'foot' && docNumber.value.trim() !== ''
                    ? docNumber.value.trim()
                    : undefined,
            extra_charges: extraConcepts.value.length
                ? extraConcepts.value
                : undefined,
            arrival_pending: laterCapture.value,
            // Se guarda aunque no haya datos todavía: es lo que evita que el
            // diálogo de completar vuelva a preguntar en carro o a pie.
            arrival_mode: arrival.value,
            payment_method: laterCapture.value
                ? undefined
                : coerceMethod(paymentMethod.value),
            payment_reference:
                !laterCapture.value &&
                paymentMethod.value !== 'cash' &&
                paymentReference.value.trim() !== ''
                    ? paymentReference.value.trim()
                    : undefined,
            guarantee_method:
                props.guaranteeAmount > 0 && guaranteeMethods.value.length
                    ? guaranteeMethod.value
                    : undefined,
        });

        let photosOk = true;
        if (arrival.value === 'foot' && stagedPhotos.value.length) {
            try {
                await uploadPhotos(data.id);
            } catch {
                photosOk = false;
            }
        }

        if (photosOk) {
            toast.success(
                `Habitación ${roomNumber} ocupada`,
                `Registrado y cobrado ${money(total.value)} en ${
                    paymentMethod.value === 'cash'
                        ? 'efectivo'
                        : paymentMethod.value === 'card'
                          ? 'tarjeta'
                          : 'transferencia'
                }.`,
            );
        } else {
            toast.error(
                'Registrado y cobrado, pero la foto no subió',
                `La ${roomNumber} quedó ocupada; vuelve a intentar la foto desde la ficha del cuarto.`,
            );
        }
        clearPhotos();
        emit('registered');
        emit('close');
    } catch (error: any) {
        toast.error(
            'No se pudo registrar',
            error.response?.data?.message ??
                'Revisa los datos e intenta de nuevo.',
        );
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
    <!-- staticBackdrop: es un formulario largo y con dinero de por medio;
         un clic afuera no debe tirarlo (el theme hace un "zoom" de aviso). -->
    <Dialog :open="open" size="lg" static-backdrop @close="emit('close')">
        <Dialog.Panel>
            <div class="flex max-h-[85vh] flex-col">
                <!-- Header -->
                <div
                    class="flex items-center gap-3.5 border-b border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <Lucide icon="Zap" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-medium">
                            Registrar llegada — {{ modalRoom?.number }}
                        </h2>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ modalRoom?.room_type ?? 'Habitación' }} ·
                            registro exprés con cobro en la llegada
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        aria-label="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-5 w-5" />
                    </button>
                </div>

                <!-- Body -->
                <div class="flex-1 space-y-5 overflow-y-auto px-4 py-4">
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
                                    class="mt-1.5 block text-base leading-none font-semibold"
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

                    <!-- Quién cobra. En la caseta de un motel el dinero lo
                         recibe el encargado en la habitación, no aquí: con eso
                         puesto el acceso se abre ya y la captura queda
                         pendiente. -->
                    <div>
                        <div
                            class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="HandCoins" class="h-3.5 w-3.5" />
                            Quién cobra
                        </div>
                        <div class="grid grid-cols-2 gap-2.5">
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-2.5 text-left transition"
                                :class="
                                    collector === 'caseta'
                                        ? 'border-primary bg-primary/5'
                                        : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                                "
                                @click="collector = 'caseta'"
                            >
                                <span class="block text-sm font-medium"
                                    >Aquí mismo</span
                                >
                                <span
                                    class="mt-0.5 block text-xs text-slate-500"
                                    >Se cobra y se captura todo ahora</span
                                >
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-2.5 text-left transition"
                                :class="
                                    collector === 'encargado'
                                        ? 'border-primary bg-primary/5'
                                        : 'border-slate-200/70 hover:border-slate-300 dark:border-darkmode-400'
                                "
                                @click="collector = 'encargado'"
                            >
                                <span class="block text-sm font-medium"
                                    >El encargado, en la habitación</span
                                >
                                <span
                                    class="mt-0.5 block text-xs text-slate-500"
                                    >Abre el acceso; los datos y el cobro se
                                    capturan después</span
                                >
                            </button>
                        </div>
                        <p
                            v-if="laterCapture"
                            class="mt-2.5 flex items-start gap-2 rounded-lg bg-pending/10 px-3.5 py-2.5 text-xs text-pending"
                        >
                            <Lucide
                                icon="ClipboardPen"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            La habitación quedará marcada como "falta capturar"
                            hasta que regrese el papel con la placa y el cobro.
                            Lo de abajo es opcional.
                        </p>
                    </div>

                    <!-- Llegada: carro o a pie -->
                    <div>
                        <div
                            class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CarFront" class="h-3.5 w-3.5" />
                            Llegada
                        </div>
                        <div
                            class="mb-3 inline-flex gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                        >
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-[0.5rem] px-3.5 py-1.5 text-sm font-medium transition"
                                :class="
                                    arrival === 'vehicle'
                                        ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                                "
                                @click="arrival = 'vehicle'"
                            >
                                <Lucide
                                    icon="CarFront"
                                    class="h-4 w-4 stroke-[1.3]"
                                />
                                En carro
                            </button>
                            <button
                                type="button"
                                class="flex items-center gap-2 rounded-[0.5rem] px-3.5 py-1.5 text-sm font-medium transition"
                                :class="
                                    arrival === 'foot'
                                        ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                        : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'
                                "
                                @click="arrival = 'foot'"
                            >
                                <Lucide
                                    icon="Footprints"
                                    class="h-4 w-4 stroke-[1.3]"
                                />
                                A pie
                            </button>
                        </div>

                        <!-- En carro: la placa manda y por eso ocupa el
                             renglón completo y se lee grande; marca, modelo y
                             color van juntos abajo como lo secundario que son.
                             Antes compartían retícula y la placa quedaba del
                             mismo tamaño que un campo opcional. -->
                        <div v-if="arrival === 'vehicle'" class="space-y-4">
                            <div>
                                <FormLabel htmlFor="express-plate"
                                    >Placas</FormLabel
                                >
                                <FormInput
                                    id="express-plate"
                                    v-model="plate"
                                    class="h-12 text-center text-base font-semibold tracking-[0.25em] uppercase"
                                    placeholder="ABC-123-D"
                                    maxlength="20"
                                />
                                <FormHelp
                                    >Con la placa basta; nombre y teléfono no se
                                    piden.</FormHelp
                                >
                            </div>

                            <div>
                                <div
                                    class="mb-2 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    <Lucide icon="Car" class="h-3.5 w-3.5" />
                                    Vehículo (opcional)
                                </div>
                                <div class="grid gap-3 sm:grid-cols-3">
                                    <FormInput
                                        v-model="vehicleBrand"
                                        aria-label="Marca"
                                        placeholder="Marca"
                                        maxlength="40"
                                    />
                                    <FormInput
                                        v-model="vehicleModel"
                                        aria-label="Modelo"
                                        placeholder="Modelo"
                                        maxlength="40"
                                    />
                                    <FormInput
                                        v-model="vehicleColor"
                                        aria-label="Color"
                                        placeholder="Color"
                                        maxlength="30"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Lo que ya se sabía de esa placa: la segunda visita
                             no vuelve a preguntar, y si está vetada se avisa
                             ANTES de registrar. -->
                        <div
                            v-if="arrival === 'vehicle' && knownVehicle"
                            class="flex items-start gap-2.5 rounded-lg border p-3 text-sm"
                            :class="
                                knownVehicle.is_blacklisted
                                    ? 'border-danger/30 bg-danger/5'
                                    : 'border-info/30 bg-info/5'
                            "
                        >
                            <Lucide
                                :icon="
                                    knownVehicle.is_blacklisted
                                        ? 'ShieldAlert'
                                        : 'History'
                                "
                                class="mt-0.5 h-4 w-4 shrink-0"
                                :class="
                                    knownVehicle.is_blacklisted
                                        ? 'text-danger'
                                        : 'text-info'
                                "
                            />
                            <div class="min-w-0">
                                <div class="font-medium">
                                    <template v-if="knownVehicle.is_blacklisted"
                                        >Esta placa está vetada</template
                                    >
                                    <template v-else
                                        >Ya estuvo aquí
                                        {{ knownVehicle.visits }}
                                        {{
                                            knownVehicle.visits === 1
                                                ? 'vez'
                                                : 'veces'
                                        }}</template
                                    >
                                </div>
                                <p
                                    class="mt-0.5 text-slate-600 dark:text-slate-300"
                                >
                                    {{
                                        knownVehicle.is_blacklisted
                                            ? (knownVehicle.blacklist_reason ??
                                              'Revisa con el encargado antes de registrar.')
                                            : (knownVehicle.label ??
                                              'Sin datos del vehículo capturados.')
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- A pie: nombre + identificación con foto. Condición
                             EXPLÍCITA y no `v-else`: el aviso de placa conocida
                             quedó entre las dos ramas y el v-else se emparejó
                             con él, así que en carro también se pintaban estos
                             campos. En carro solo se pide el vehículo. -->
                        <div v-if="arrival === 'foot'" class="space-y-3">
                            <div>
                                <FormLabel htmlFor="express-guest-name"
                                    >Nombre completo</FormLabel
                                >
                                <FormInput
                                    id="express-guest-name"
                                    v-model="guestName"
                                    placeholder="Nombre y apellidos del huésped"
                                    maxlength="255"
                                />
                                <FormHelp
                                    v-if="footNameMissing"
                                    class="text-danger"
                                    >El huésped a pie se registra con su
                                    nombre.</FormHelp
                                >
                            </div>
                            <div class="grid items-start gap-3 sm:grid-cols-2">
                                <div>
                                    <FormLabel htmlFor="express-doc-type"
                                        >Identificación</FormLabel
                                    >
                                    <FormSelect
                                        id="express-doc-type"
                                        v-model="docType"
                                    >
                                        <option
                                            v-for="(
                                                label, key
                                            ) in documentTypes"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ label }}
                                        </option>
                                    </FormSelect>
                                </div>
                                <div>
                                    <FormLabel htmlFor="express-doc-number"
                                        >Número (opcional)</FormLabel
                                    >
                                    <FormInput
                                        id="express-doc-number"
                                        v-model="docNumber"
                                        placeholder="Número del documento"
                                        maxlength="60"
                                    />
                                </div>
                            </div>
                            <div>
                                <div
                                    class="flex flex-wrap items-center gap-2.5"
                                >
                                    <div
                                        v-for="(photo, index) in stagedPhotos"
                                        :key="photo.url"
                                        class="relative h-16 w-24 overflow-hidden rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                                    >
                                        <img
                                            :src="photo.url"
                                            alt="Documento"
                                            class="h-full w-full object-cover"
                                        />
                                        <button
                                            type="button"
                                            class="absolute top-1 right-1 flex h-5 w-5 items-center justify-center rounded-full bg-slate-950/70 text-white"
                                            title="Quitar foto"
                                            @click="removePhoto(index)"
                                        >
                                            <Lucide icon="X" class="h-3 w-3" />
                                        </button>
                                    </div>
                                    <label
                                        v-if="stagedPhotos.length < 2"
                                        class="flex h-16 w-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-300/80 text-slate-500 transition hover:border-primary hover:text-primary dark:border-darkmode-400"
                                    >
                                        <Lucide icon="Camera" class="h-5 w-5" />
                                        <span class="text-[10px] font-medium">{{
                                            stagedPhotos.length
                                                ? 'Reverso'
                                                : 'Foto INE'
                                        }}</span>
                                        <input
                                            type="file"
                                            accept="image/*"
                                            capture="environment"
                                            class="hidden"
                                            @change="stagePhoto"
                                        />
                                    </label>
                                </div>
                                <FormHelp
                                    >Toma la foto del documento; queda guardada
                                    de forma privada y solo la ve el personal
                                    con permiso.</FormHelp
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Personas y cargos opcionales -->
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
                                    class="w-9 text-center text-base font-semibold"
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
                            <FormHelp v-if="extraGuestsFee > 0"
                                >Se suman {{ money(extraGuestsFee) }} por
                                personas extra.</FormHelp
                            >
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

                    <!-- Cobro -->
                    <div v-if="!laterCapture">
                        <div
                            class="mb-2.5 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Banknote" class="h-3.5 w-3.5" />
                            Cobro
                        </div>
                        <div
                            class="grid gap-2.5"
                            :class="
                                paymentMethods.length > 2
                                    ? 'grid-cols-3'
                                    : 'grid-cols-2'
                            "
                        >
                            <button
                                v-for="method in paymentMethods"
                                :key="method.key"
                                type="button"
                                class="flex flex-col items-center gap-1.5 rounded-lg border py-2.5 text-sm font-medium transition"
                                :class="
                                    paymentMethod === method.key
                                        ? 'border-primary bg-primary/5 text-primary'
                                        : 'border-slate-200/70 text-slate-500 hover:border-slate-300 dark:border-darkmode-400'
                                "
                                @click="paymentMethod = method.key"
                            >
                                <Lucide :icon="method.icon" class="h-4 w-4" />
                                {{ method.label }}
                            </button>
                        </div>
                        <FormInput
                            v-if="paymentMethod !== 'cash'"
                            v-model="paymentReference"
                            class="mt-2.5"
                            placeholder="Referencia o folio del voucher (opcional)"
                            maxlength="100"
                        />
                        <div
                            v-if="
                                guaranteeAmount > 0 && guaranteeMethods.length
                            "
                            class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-slate-300/70 px-3.5 py-2.5 text-sm dark:border-darkmode-400"
                        >
                            <span
                                class="flex items-center gap-2 text-slate-600 dark:text-slate-300"
                            >
                                <Lucide
                                    icon="ShieldCheck"
                                    class="h-4 w-4 text-slate-400"
                                />
                                Fianza {{ money(guaranteeAmount) }}
                            </span>
                            <div
                                class="ml-auto inline-flex gap-1 rounded-[0.5rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                            >
                                <button
                                    v-for="method in guaranteeMethods"
                                    :key="method.key"
                                    type="button"
                                    class="rounded-[0.4rem] px-2.5 py-1 text-xs font-medium transition"
                                    :class="
                                        guaranteeMethod === method.key
                                            ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                            : 'text-slate-500'
                                    "
                                    @click="
                                        guaranteeMethod = method.key as
                                            | 'cash'
                                            | 'card'
                                    "
                                >
                                    {{ method.label }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer. En móvil se apila: desde que el plano se abre en
                     pantalla completa en el teléfono, este modal se usa de
                     verdad ahí y en una sola fila la leyenda quedaba en una
                     columna de tres palabras al lado del botón. -->
                <div
                    class="flex flex-col gap-3 border-t border-slate-200/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-darkmode-400"
                >
                    <div class="text-xs text-slate-500">
                        <span class="block">{{
                            laterCapture
                                ? `Quedan ${money(total)} por cobrar en la habitación.`
                                : 'El total exacto lo confirma el sistema.'
                        }}</span>
                        <span v-if="guaranteeAmount > 0" class="block"
                            >+ {{ money(guaranteeAmount) }} de fianza (se
                            devuelve al salir).</span
                        >
                    </div>
                    <Button
                        v-if="ratePlanId"
                        variant="primary"
                        class="min-h-10 shrink-0 rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="!canSubmit"
                        @click="submit"
                    >
                        <Lucide
                            :icon="laterCapture ? 'DoorOpen' : 'Zap'"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{
                            saving
                                ? 'Registrando…'
                                : laterCapture
                                  ? 'Abrir acceso'
                                  : `Registrar y cobrar ${money(total)}`
                        }}
                    </Button>
                    <Button
                        v-else
                        variant="outline-secondary"
                        class="min-h-10 shrink-0 rounded-[0.5rem] text-xs"
                        disabled
                    >
                        <Lucide
                            icon="TriangleAlert"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        Sin tarifa activa
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
