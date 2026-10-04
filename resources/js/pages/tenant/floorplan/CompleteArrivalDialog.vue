<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormSelect } from '@/components/Base/Form';
import type { CounterMethod } from '@/composables/useCounterMethods';
import { useCounterMethods } from '@/composables/useCounterMethods';
import Lucide from '@/components/Base/Lucide';
import { formatMoney } from './format';
import PlanModal from './PlanModal.vue';
import type { RoomData } from './types';

/**
 * Segundo momento de la caseta de motel: regresó el papel.
 *
 * La caseta abrió el acceso con la tarifa; aquí se anota lo que el encargado
 * levantó en la habitación —placa, marca, modelo y color, o la identificación
 * si llegaron a pie— y se marca el cobro que él hizo. Ese cobro entra al corte
 * de QUIEN LO CAPTURA, que es quien tiene el dinero.
 *
 * Se puede sellar sin datos: el cliente que no quiso darlos existe, y dejar la
 * habitación marcada como "falta capturar" para siempre es peor que registrar
 * que no hubo datos.
 */
const props = defineProps<{
    open: boolean;
    room: RoomData | null;
    /** Lo que falta de hospedaje, para decirlo antes de marcar el cobro. */
    pending: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'done', message: string): void;
    (e: 'error', message: string): void;
}>();

const arrival = ref<'vehicle' | 'foot'>('vehicle');
// Lo eligió la caseta al abrir el acceso: aquí NO se vuelve a preguntar. El
// selector solo aparece si de plano no se guardó (estancias viejas) o si quien
// captura dice que la realidad fue otra.
const askArrival = ref(false);
const plate = ref('');
const brand = ref('');
const model = ref('');
const color = ref('');
const guestName = ref('');
const docType = ref('ine');
const docNumber = ref('');
const collected = ref(true);
const method = ref<CounterMethod>('cash');
const reference = ref('');
const saving = ref(false);

// Formas de cobro que acepta la recepción (/ajustes/metodos-pago →
// Políticas): lo que el hotel no acepta ni se ofrece aquí.
const {
    methods: counterMethods,
    first: firstMethod,
    coerce: coerceMethod,
} = useCounterMethods();

const documentTypes: Record<string, string> = {
    ine: 'INE',
    pasaporte: 'Pasaporte',
    licencia: 'Licencia',
    otro: 'Otro documento',
};

// Cada apertura empieza limpia: arrastrar la placa de la llegada anterior es
// justo cómo se le achaca un carro a quien no era.
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        const known = props.room?.active_stay?.arrival_mode ?? null;
        arrival.value = known ?? 'vehicle';
        askArrival.value = known === null;
        plate.value = '';
        brand.value = '';
        model.value = '';
        color.value = '';
        guestName.value = '';
        docType.value = 'ine';
        docNumber.value = '';
        collected.value = props.pending > 0;
        method.value = firstMethod.value;
        reference.value = '';
    },
);

const stayId = computed(() => props.room?.active_stay?.id ?? null);

// El número que se ve se queda mientras el modal se cierra (el plano suelta el
// cuarto en el mismo instante y el modal desaparecía de golpe).
const shownNumber = ref(props.room?.number ?? '');
watch(
    () => props.room,
    (room) => {
        if (room) {
            shownNumber.value = room.number;
        }
    },
);

async function submit(sinDatos = false) {
    if (saving.value || stayId.value === null) {
        return;
    }

    saving.value = true;

    try {
        await axios.patch(`/api/stays/${stayId.value}/arrival`, {
            vehicle_plate:
                !sinDatos && arrival.value === 'vehicle' && plate.value.trim()
                    ? plate.value.trim().toUpperCase()
                    : null,
            vehicle_brand:
                !sinDatos && arrival.value === 'vehicle'
                    ? brand.value.trim() || null
                    : null,
            vehicle_model:
                !sinDatos && arrival.value === 'vehicle'
                    ? model.value.trim() || null
                    : null,
            vehicle_color:
                !sinDatos && arrival.value === 'vehicle'
                    ? color.value.trim() || null
                    : null,
            id_document_type:
                !sinDatos && arrival.value === 'foot' && docNumber.value.trim()
                    ? docType.value
                    : null,
            id_document_number:
                !sinDatos && arrival.value === 'foot'
                    ? docNumber.value.trim() || null
                    : null,
            arrival_mode: arrival.value,
            payment_method:
                collected.value && props.pending > 0 ? method.value : null,
            payment_reference:
                collected.value && method.value !== 'cash'
                    ? reference.value.trim() || null
                    : null,
            notes: sinDatos ? 'El cliente no dio datos' : null,
        });

        emit(
            'done',
            collected.value && props.pending > 0
                ? `Registro completo y ${formatMoney(props.pending)} cobrados`
                : 'Registro completo',
        );
    } catch (error: any) {
        emit(
            'error',
            error.response?.data?.message ??
                'No se pudo completar el registro.',
        );
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PlanModal
        :open="open"
        icon="ClipboardPen"
        :title="`Completar el registro de la ${shownNumber}`"
        subtitle="Lo que levantó el encargado en la habitación."
        :busy="saving"
        @close="emit('close')"
    >
        <!-- Lo que ya eligió la caseta, como dato, no como pregunta. -->
        <div
            v-if="!askArrival"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
        >
            <Lucide
                :icon="arrival === 'vehicle' ? 'CarFront' : 'Footprints'"
                class="h-4 w-4 shrink-0 text-slate-400"
            />
            <span class="text-sm font-medium">{{
                arrival === 'vehicle' ? 'Llegó en carro' : 'Llegó a pie'
            }}</span>
            <button
                type="button"
                class="ml-auto text-xs font-medium text-primary hover:underline"
                title="Corrige si la realidad no coincidió con lo que se anotó en la pluma"
                @click="askArrival = true"
            >
                Llegó de otra forma
            </button>
        </div>

        <div v-else>
            <div
                class="mb-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
            >
                Cómo llegó
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <button
                    type="button"
                    class="flex h-9 items-center justify-center gap-2 rounded-[0.5rem] border text-xs font-medium transition"
                    :class="
                        arrival === 'vehicle'
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-400'
                    "
                    @click="arrival = 'vehicle'"
                >
                    <Lucide icon="CarFront" class="h-3.5 w-3.5" />
                    En carro
                </button>
                <button
                    type="button"
                    class="flex h-9 items-center justify-center gap-2 rounded-[0.5rem] border text-xs font-medium transition"
                    :class="
                        arrival === 'foot'
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-slate-200/70 text-slate-500 hover:bg-slate-50 dark:border-darkmode-400 dark:hover:bg-darkmode-400'
                    "
                    @click="arrival = 'foot'"
                >
                    <Lucide icon="Footprints" class="h-3.5 w-3.5" />
                    A pie
                </button>
            </div>
        </div>

        <!-- En carro: la placa manda; marca, modelo y color son el resto de lo
             que anota el encargado. -->
        <div v-if="arrival === 'vehicle'" class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-3">
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-plate"
                    >Placa</label
                >
                <FormInput
                    id="arrival-plate"
                    v-model="plate"
                    type="text"
                    maxlength="20"
                    class="h-9 text-xs font-semibold tracking-[0.2em] uppercase"
                    placeholder="ABC-123-D"
                />
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-brand"
                    >Marca</label
                >
                <FormInput
                    id="arrival-brand"
                    v-model="brand"
                    type="text"
                    maxlength="40"
                    class="h-9 text-xs"
                    placeholder="Nissan"
                />
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-model"
                    >Modelo</label
                >
                <FormInput
                    id="arrival-model"
                    v-model="model"
                    type="text"
                    maxlength="40"
                    class="h-9 text-xs"
                    placeholder="Versa"
                />
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-color"
                    >Color</label
                >
                <FormInput
                    id="arrival-color"
                    v-model="color"
                    type="text"
                    maxlength="30"
                    class="h-9 text-xs"
                    placeholder="Gris"
                />
            </div>
        </div>

        <!-- A pie: la identificación, como en el registro exprés. -->
        <div v-else class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-name"
                    >Nombre como viene en la identificación</label
                >
                <FormInput
                    id="arrival-name"
                    v-model="guestName"
                    type="text"
                    maxlength="255"
                    class="h-9 text-xs"
                />
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-doc-type"
                    >Documento</label
                >
                <FormSelect
                    id="arrival-doc-type"
                    v-model="docType"
                    class="h-9 text-xs"
                >
                    <option
                        v-for="(label, key) in documentTypes"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </FormSelect>
            </div>
            <div>
                <label
                    class="mb-1.5 block text-xs font-medium text-slate-600 dark:text-slate-300"
                    for="arrival-doc-number"
                    >Número (opcional)</label
                >
                <FormInput
                    id="arrival-doc-number"
                    v-model="docNumber"
                    type="text"
                    maxlength="60"
                    class="h-9 text-xs"
                />
            </div>
        </div>

        <!-- El cobro que hizo el encargado. -->
        <div
            v-if="pending > 0"
            class="rounded-lg border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
        >
            <label class="flex cursor-pointer items-center gap-2.5">
                <FormCheck.Input v-model="collected" type="checkbox" />
                <span
                    class="text-xs font-medium text-slate-700 dark:text-slate-200"
                    >Ya cobró {{ formatMoney(pending) }}</span
                >
            </label>

            <div v-if="collected" class="mt-3 grid gap-3 sm:grid-cols-2">
                <FormSelect
                    v-model="method"
                    class="h-9 text-xs"
                    aria-label="Forma de cobro"
                >
                    <option
                        v-for="m in counterMethods"
                        :key="m.key"
                        :value="m.key"
                    >
                        {{ m.label }}
                    </option>
                </FormSelect>
                <FormInput
                    v-if="method !== 'cash'"
                    v-model="reference"
                    type="text"
                    maxlength="100"
                    class="h-9 text-xs"
                    placeholder="Referencia o folio del voucher"
                />
            </div>
            <p v-else class="mt-2 text-xs text-slate-500">
                Sin marcar el cobro, la habitación se queda con saldo y se cobra
                al registrar la salida.
            </p>
        </div>

        <template #footer>
            <Button
                variant="outline-secondary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="saving"
                title="Sella el registro dejando constancia de que no hubo datos"
                @click="submit(true)"
            >
                No dieron datos
            </Button>
            <Button
                variant="primary"
                class="h-9 rounded-[0.5rem] px-5 text-xs"
                :disabled="saving"
                @click="submit(false)"
            >
                <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                {{ saving ? 'Guardando…' : 'Completar registro' }}
            </Button>
        </template>
    </PlanModal>
</template>
