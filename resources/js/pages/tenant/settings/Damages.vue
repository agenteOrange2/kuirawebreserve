<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormLabel } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Catálogo de daños: lo que se cobra cuando se revisa la habitación antes de
 * dejar salir al cliente.
 *
 * Existe para que el cobro sea parejo entre turnos: sin lista, cada quien pone
 * el precio que se le ocurre y el cliente reclama con razón. Al registrar la
 * salida se elige de aquí —aparecen como pastillas— o se escribe uno libre
 * para el caso que nadie previó.
 *
 * La captura es por MODAL (alta y edición) y cada acción guarda de inmediato:
 * no hay botón global de "guardar catálogo" que se pueda olvidar.
 */
interface DamageRow {
    concept: string;
    amount: number;
}

const props = defineProps<{
    property: { id: number; name: string };
    damages: { concept: string; amount: number }[];
    isMotel: boolean;
}>();

const toast = useToasts();
const saving = ref(false);
const rows = ref<DamageRow[]>(props.damages.map((damage) => ({ ...damage })));

/** Los que casi todos acaban capturando; el precio lo pone cada hotel. */
const SUGERIDOS = [
    'Toalla',
    'Sábana',
    'Control de TV',
    'Llave o tarjeta',
    'Cerradura',
    'Cristal o espejo',
    'Colchón',
];

const money = (value: number) =>
    Number(value || 0).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });

const conPrecio = computed(
    () => rows.value.filter((row) => Number(row.amount) > 0).length,
);

const faltanSugeridos = computed(() => {
    const yaEstan = rows.value.map((row) => row.concept.trim().toLowerCase());

    return SUGERIDOS.filter(
        (concept) => !yaEstan.includes(concept.toLowerCase()),
    );
});

/**
 * Persiste el catálogo completo (el backend guarda el arreglo entero en
 * settings.damage_catalog). Si el servidor rechaza, la lista vuelve a como
 * estaba para no mostrar un estado que no existe.
 */
async function persist(previous: DamageRow[], okTitle: string, okDetail: string) {
    saving.value = true;

    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                damage_catalog: rows.value.map((row) => ({
                    concept: row.concept.trim(),
                    amount: Number(row.amount) || 0,
                })),
            },
        });
        toast.success(okTitle, okDetail);

        return true;
    } catch (error: any) {
        rows.value = previous;
        toast.error(
            'No se pudo guardar',
            error.response?.data?.message ?? 'Revisa el concepto y el monto.',
        );

        return false;
    } finally {
        saving.value = false;
    }
}

// ── Modal de alta/edición ──
const showForm = ref(false);
const editingIndex = ref<number | null>(null);
const form = reactive({ concept: '', amount: '' as string | number });
const formError = ref<string | null>(null);

function openCreate() {
    editingIndex.value = null;
    form.concept = '';
    form.amount = '';
    formError.value = null;
    showForm.value = true;
}

function openEdit(index: number) {
    editingIndex.value = index;
    form.concept = rows.value[index].concept;
    form.amount = rows.value[index].amount || '';
    formError.value = null;
    showForm.value = true;
}

async function submitForm() {
    const concept = form.concept.trim();

    if (!concept) {
        formError.value = 'Escribe qué se dañó.';

        return;
    }

    // El mismo concepto dos veces confunde al cobrar: se avisa aquí, no en
    // la caseta a media salida.
    const duplicated = rows.value.some(
        (row, index) =>
            index !== editingIndex.value &&
            row.concept.trim().toLowerCase() === concept.toLowerCase(),
    );

    if (duplicated) {
        formError.value = 'Ese concepto ya está en el catálogo.';

        return;
    }

    const previous = rows.value.map((row) => ({ ...row }));
    const amount = Number(form.amount) || 0;

    if (editingIndex.value !== null) {
        rows.value[editingIndex.value] = { concept, amount };
    } else {
        rows.value.push({ concept, amount });
    }

    const ok = await persist(
        previous,
        editingIndex.value !== null ? 'Concepto actualizado' : 'Concepto agregado',
        'Aparece al registrar la salida de una habitación.',
    );

    if (ok) {
        showForm.value = false;
    }
}

// ── Modal de confirmación al quitar ──
const deletingIndex = ref<number | null>(null);

async function confirmDelete() {
    if (deletingIndex.value === null) return;

    const previous = rows.value.map((row) => ({ ...row }));
    const removed = rows.value[deletingIndex.value];
    rows.value.splice(deletingIndex.value, 1);

    const ok = await persist(
        previous,
        'Concepto quitado',
        `"${removed.concept}" ya no aparecerá al cobrar.`,
    );

    if (ok) {
        deletingIndex.value = null;
    }
}

/** Alta rápida de los típicos que falten (sin precio; se pone al editar o cobrar). */
async function addSuggested() {
    if (!faltanSugeridos.value.length) return;

    const previous = rows.value.map((row) => ({ ...row }));
    faltanSugeridos.value.forEach((concept) =>
        rows.value.push({ concept, amount: 0 }),
    );

    await persist(
        previous,
        'Típicos agregados',
        'Edita cada uno para ponerle tu precio.',
    );
}
</script>

<template>
    <RazeLayout title="Daños">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5 sm:gap-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary sm:h-14 sm:w-14"
                    >
                        <Lucide icon="Hammer" class="h-5 w-5 sm:h-7 sm:w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-lg font-medium sm:text-xl">Daños</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ rows.length }}
                            {{ rows.length === 1 ? 'concepto' : 'conceptos' }}
                            <span v-if="rows.length"
                                >· {{ conPrecio }} con precio</span
                            >
                            · {{ property.name }}
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.hotel-settings')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Ajustes
                </Button>
            </div>

            <!-- Dónde se usa esto, con la pinta que va a tener allá: una lista
                 de precios sin contexto no se entiende hasta que alguien la
                 topa a media salida. -->
            <div class="box box--stacked mt-5 grid grid-cols-12 gap-5 p-5">
                <div class="col-span-12 xl:col-span-7">
                    <h2 class="text-base font-medium">
                        Dónde aparece esta lista
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Al registrar la salida de una habitación, en la
                        <strong>revisión de la habitación</strong>. Se toca el
                        concepto, se ajusta el importe si hace falta y se agrega
                        a la cuenta: el huésped no sale sin pagarlo, queda
                        registrado como incidencia y se puede vetar a quien lo
                        hizo.
                    </p>
                    <p
                        v-if="!isMotel"
                        class="mt-3 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <Lucide
                            icon="Info"
                            class="mt-0.5 h-4 w-4 shrink-0 text-slate-400"
                        />
                        La revisión al salir es un paso de la operación de
                        motel. Este hotel no la tiene activa, así que el
                        catálogo se guarda pero el cargo se agrega a mano en la
                        cuenta.
                    </p>
                </div>
                <div class="col-span-12 xl:col-span-5">
                    <div
                        class="rounded-xl border border-slate-200/70 bg-slate-50/70 p-4 dark:border-darkmode-400 dark:bg-darkmode-700/50"
                    >
                        <div
                            class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            Así se ve al cobrar
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span
                                v-for="row in rows.slice(0, 4)"
                                :key="row.concept"
                                class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs text-slate-600 dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                                >{{ row.concept }} ·
                                {{ money(Number(row.amount)) }}</span
                            >
                            <span
                                v-if="!rows.length"
                                class="rounded-full border border-dashed border-slate-300 px-3 py-1 text-xs text-slate-400"
                                >Toalla · $180.00</span
                            >
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-5 flex flex-col p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-medium">
                            Conceptos y precio sugerido
                        </h2>
                        <p class="mt-1 text-sm text-slate-500">
                            El precio se puede ajustar al cobrarlo: esto es la
                            referencia, no una camisa de fuerza.
                        </p>
                    </div>
                    <div
                        v-if="rows.length"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <Button
                            v-if="faltanSugeridos.length"
                            type="button"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] bg-white"
                            :disabled="saving"
                            @click="addSuggested"
                        >
                            <Lucide icon="Sparkles" class="mr-2 h-4 w-4" />
                            Agregar los típicos
                        </Button>
                        <Button
                            type="button"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            @click="openCreate"
                        >
                            <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                            Agregar concepto
                        </Button>
                    </div>
                </div>

                <!-- Estado vacío: arrancar de cero frente a un formulario en
                     blanco es justo donde esto se queda sin llenar. -->
                <div
                    v-if="!rows.length"
                    class="mt-4 rounded-xl border border-dashed border-slate-300/70 px-4 py-8 text-center dark:border-darkmode-400"
                >
                    <div
                        class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                    >
                        <Lucide icon="Hammer" class="h-6 w-6" />
                    </div>
                    <p class="mt-3 text-sm font-medium">
                        Todavía no hay conceptos
                    </p>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                        Arma la lista de lo que cobras para que todos los turnos
                        cobren lo mismo. Puedes empezar con los típicos y
                        ponerles tu precio.
                    </p>
                    <div
                        class="mt-4 flex flex-col items-center justify-center gap-2 sm:flex-row"
                    >
                        <Button
                            type="button"
                            variant="primary"
                            class="rounded-[0.5rem]"
                            :disabled="saving"
                            @click="addSuggested"
                        >
                            <Lucide icon="Sparkles" class="mr-2 h-4 w-4" />
                            Agregar los típicos
                        </Button>
                        <Button
                            type="button"
                            variant="outline-secondary"
                            class="rounded-[0.5rem] bg-white"
                            @click="openCreate"
                        >
                            <Lucide icon="Plus" class="mr-2 h-4 w-4" />
                            Agregar uno
                        </Button>
                    </div>
                </div>

                <div
                    v-else
                    class="mt-4 divide-y divide-dashed divide-slate-300/70"
                >
                    <div
                        v-for="(row, index) in rows"
                        :key="`${row.concept}-${index}`"
                        class="flex items-center gap-3 py-3"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Hammer" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">
                                {{ row.concept }}
                            </div>
                            <div class="mt-0.5 text-xs">
                                <span
                                    v-if="Number(row.amount) > 0"
                                    class="text-slate-500"
                                    >{{ money(Number(row.amount)) }}</span
                                >
                                <span v-else class="text-slate-400"
                                    >Sin precio — se captura al cobrarlo</span
                                >
                            </div>
                        </div>
                        <button
                            type="button"
                            title="Editar concepto"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-primary/10 hover:text-primary"
                            @click="openEdit(index)"
                        >
                            <Lucide icon="Pencil" class="h-4 w-4" />
                        </button>
                        <button
                            type="button"
                            title="Quitar este concepto"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                            @click="deletingIndex = index"
                        >
                            <Lucide icon="Trash2" class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div
                    class="mt-5 border-t border-slate-200/70 pt-4 dark:border-darkmode-400"
                >
                    <p class="text-xs text-slate-500">
                        Cada cambio se guarda al momento. Los conceptos sin
                        precio también sirven: el importe se captura al
                        cobrarlos.
                    </p>
                </div>
            </div>
        </div>

        <!-- Modal agregar/editar concepto -->
        <Dialog :open="showForm" @close="showForm = false">
            <Dialog.Panel>
                <form class="flex flex-col" @submit.prevent="submitForm">
                    <div
                        class="flex items-center gap-3.5 border-b border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide
                                :icon="
                                    editingIndex !== null ? 'Pencil' : 'Plus'
                                "
                                class="h-5 w-5"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-medium">
                                {{
                                    editingIndex !== null
                                        ? 'Editar concepto'
                                        : 'Agregar concepto'
                                }}
                            </h2>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Aparece como pastilla en la revisión al
                                registrar la salida
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                            @click="showForm = false"
                        >
                            <Lucide icon="X" class="h-5 w-5" />
                        </button>
                    </div>
                    <div class="space-y-4 px-6 py-5">
                        <div>
                            <FormLabel htmlFor="damage-concept"
                                >Qué se dañó</FormLabel
                            >
                            <FormInput
                                id="damage-concept"
                                v-model="form.concept"
                                type="text"
                                maxlength="80"
                                placeholder="Toalla, sábana, control de TV…"
                            />
                        </div>
                        <div>
                            <FormLabel htmlFor="damage-amount"
                                >Precio sugerido</FormLabel
                            >
                            <div class="relative">
                                <span
                                    class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-400"
                                    >$</span
                                >
                                <FormInput
                                    id="damage-amount"
                                    v-model="form.amount"
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="pl-7"
                                    placeholder="0"
                                />
                            </div>
                            <FormHelp
                                >El precio se puede ajustar al cobrarlo; sin
                                precio, el importe se captura en ese
                                momento.</FormHelp
                            >
                        </div>
                        <p
                            v-if="formError"
                            class="rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                        >
                            {{ formError }}
                        </p>
                    </div>
                    <div
                        class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-6 py-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            :disabled="saving || !form.concept.trim()"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                saving
                                    ? 'Guardando…'
                                    : editingIndex !== null
                                      ? 'Guardar'
                                      : 'Agregar'
                            }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal quitar concepto -->
        <Dialog :open="deletingIndex !== null" @close="deletingIndex = null">
            <Dialog.Panel>
                <div v-if="deletingIndex !== null" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Quitar "{{
                                    rows[deletingIndex]?.concept
                                }}"?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Deja de aparecer en la revisión al registrar la
                                salida. Los cobros ya hechos no se tocan.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deletingIndex = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="saving"
                            @click="confirmDelete"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ saving ? 'Quitando…' : 'Sí, quitar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
