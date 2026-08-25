<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput, FormSelect } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { formatMoney } from './format';
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
const method = ref<'cash' | 'card' | 'transfer'>('cash');
const reference = ref('');
const saving = ref(false);

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
        method.value = 'cash';
        reference.value = '';
    },
);

const stayId = computed(() => props.room?.active_stay?.id ?? null);

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
    <Dialog :open="open" size="lg" @close="emit('close')">
        <Dialog.Panel v-if="room">
            <div
                class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                >
                    <Lucide icon="ClipboardPen" class="h-5 w-5 text-primary" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-medium">
                        Completar el registro de la {{ room.number }}
                    </h2>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Lo que levantó el encargado en la habitación.
                    </p>
                </div>
            </div>

            <div class="space-y-5 px-6 py-5">
                <!-- Lo que ya eligió la caseta, como dato, no como pregunta. -->
                <div
                    v-if="!askArrival"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-slate-200/70 px-4 py-3 dark:border-darkmode-400"
                >
                    <Lucide
                        :icon="
                            arrival === 'vehicle' ? 'CarFront' : 'Footprints'
                        "
                        class="h-4 w-4 shrink-0 text-slate-400"
                    />
                    <span class="text-sm font-medium">{{
                        arrival === 'vehicle' ? 'Llegó en carro' : 'Llegó a pie'
                    }}</span>
                    <button
                        type="button"
                        class="ml-auto text-xs text-primary hover:underline"
                        title="Corrige si la realidad no coincidió con lo que se anotó en la pluma"
                        @click="askArrival = true"
                    >
                        Llegó de otra forma
                    </button>
                </div>

                <div v-else class="grid grid-cols-2 gap-2.5">
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-3 text-sm font-medium transition"
                        :class="
                            arrival === 'vehicle'
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                        "
                        @click="arrival = 'vehicle'"
                    >
                        <Lucide icon="CarFront" class="mr-2 inline h-4 w-4" />
                        Llegó en carro
                    </button>
                    <button
                        type="button"
                        class="rounded-lg border px-3 py-3 text-sm font-medium transition"
                        :class="
                            arrival === 'foot'
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-slate-200/70 text-slate-500 dark:border-darkmode-400'
                        "
                        @click="arrival = 'foot'"
                    >
                        <Lucide icon="Footprints" class="mr-2 inline h-4 w-4" />
                        Llegó a pie
                    </button>
                </div>

                <!-- En carro: la placa manda; marca, modelo y color son el
                     resto de lo que anota el encargado. -->
                <template v-if="arrival === 'vehicle'">
                    <div>
                        <label
                            class="text-xs text-slate-500"
                            for="arrival-plate"
                            >Placa</label
                        >
                        <FormInput
                            id="arrival-plate"
                            v-model="plate"
                            type="text"
                            maxlength="20"
                            class="mt-1 text-center text-lg font-semibold tracking-[0.2em] uppercase"
                            placeholder="ABC-123-D"
                        />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label
                                class="text-xs text-slate-500"
                                for="arrival-brand"
                                >Marca</label
                            >
                            <FormInput
                                id="arrival-brand"
                                v-model="brand"
                                type="text"
                                maxlength="40"
                                class="mt-1"
                                placeholder="Nissan"
                            />
                        </div>
                        <div>
                            <label
                                class="text-xs text-slate-500"
                                for="arrival-model"
                                >Modelo</label
                            >
                            <FormInput
                                id="arrival-model"
                                v-model="model"
                                type="text"
                                maxlength="40"
                                class="mt-1"
                                placeholder="Versa"
                            />
                        </div>
                        <div>
                            <label
                                class="text-xs text-slate-500"
                                for="arrival-color"
                                >Color</label
                            >
                            <FormInput
                                id="arrival-color"
                                v-model="color"
                                type="text"
                                maxlength="30"
                                class="mt-1"
                                placeholder="Gris"
                            />
                        </div>
                    </div>
                </template>

                <!-- A pie: la identificación, como en el registro exprés. -->
                <template v-else>
                    <div>
                        <label class="text-xs text-slate-500" for="arrival-name"
                            >Nombre como viene en la identificación</label
                        >
                        <FormInput
                            id="arrival-name"
                            v-model="guestName"
                            type="text"
                            maxlength="255"
                            class="mt-1"
                        />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label
                                class="text-xs text-slate-500"
                                for="arrival-doc-type"
                                >Documento</label
                            >
                            <FormSelect
                                id="arrival-doc-type"
                                v-model="docType"
                                class="mt-1"
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
                                class="text-xs text-slate-500"
                                for="arrival-doc-number"
                                >Número (opcional)</label
                            >
                            <FormInput
                                id="arrival-doc-number"
                                v-model="docNumber"
                                type="text"
                                maxlength="60"
                                class="mt-1"
                            />
                        </div>
                    </div>
                </template>

                <!-- El cobro que hizo el encargado. -->
                <div
                    v-if="pending > 0"
                    class="rounded-xl border border-slate-200/70 p-4 dark:border-darkmode-400"
                >
                    <label class="flex items-center gap-3">
                        <input
                            v-model="collected"
                            type="checkbox"
                            class="rounded border-slate-300"
                        />
                        <span class="text-sm font-medium"
                            >Ya cobró {{ formatMoney(pending) }}</span
                        >
                    </label>

                    <div v-if="collected" class="mt-3 space-y-2">
                        <FormSelect v-model="method">
                            <option value="cash">Efectivo</option>
                            <option value="card">Tarjeta / terminal</option>
                            <option value="transfer">Transferencia</option>
                        </FormSelect>
                        <FormInput
                            v-if="method !== 'cash'"
                            v-model="reference"
                            type="text"
                            maxlength="100"
                            placeholder="Referencia o folio del voucher (opcional)"
                        />
                    </div>
                    <p v-else class="mt-2 text-xs text-slate-500">
                        Sin marcar el cobro, la habitación se queda con saldo y
                        se cobra al registrar la salida.
                    </p>
                </div>
            </div>

            <div
                class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
            >
                <Button
                    variant="outline-secondary"
                    class="min-h-11 rounded-[0.5rem]"
                    :disabled="saving"
                    title="Sella el registro dejando constancia de que no hubo datos"
                    @click="submit(true)"
                >
                    No dieron datos
                </Button>
                <Button
                    variant="primary"
                    class="min-h-11 rounded-[0.5rem]"
                    :disabled="saving"
                    @click="submit(false)"
                >
                    {{ saving ? 'Guardando…' : 'Completar registro' }}
                </Button>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
