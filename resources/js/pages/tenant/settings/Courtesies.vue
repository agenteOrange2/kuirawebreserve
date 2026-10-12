<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { courtesyMoney as money } from '@/components/courtesies/types';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Área AISLADA del módulo cortesías (/ajustes/cortesias): el tope mensual
 * de lo que se regala en hospedaje. Solo el propietario la abre. Sin tope
 * no hay límite; con tope, la cortesía que lo pasa pide confirmarse dos
 * veces (y queda marcada en el reporte).
 */
const props = defineProps<{
    cap: number | null;
    monthUsed: number;
    monthLabel: string;
}>();

const toast = useToasts();
const capInput = ref<string | number>(props.cap ?? '');
const saving = ref(false);

const normalized = computed(() => String(capInput.value ?? '').trim());
const invalid = computed(
    () =>
        normalized.value !== '' &&
        (Number.isNaN(Number(normalized.value)) ||
            Number(normalized.value) < 0),
);
const changed = computed(() => normalized.value !== String(props.cap ?? ''));

const percent = computed(() =>
    props.cap
        ? Math.min(100, Math.round((props.monthUsed / props.cap) * 100))
        : 0,
);

async function submit() {
    if (saving.value || invalid.value) return;

    saving.value = true;
    try {
        const { data } = await axios.patch('/api/courtesies/settings', {
            courtesy_monthly_cap:
                normalized.value === '' ? null : Number(normalized.value),
        });
        toast.success(
            'Tope guardado',
            data.courtesy_monthly_cap === null
                ? 'Las cortesías ya no tienen tope mensual.'
                : `Tope mensual: ${money(data.courtesy_monthly_cap)}.`,
        );
        router.reload({ only: ['cap', 'monthUsed'] });
    } catch (error: any) {
        toast.error(
            'No se pudo guardar',
            error.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Cortesías">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-pending/10 bg-pending/10 text-pending"
                    >
                        <Lucide icon="HandHeart" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Cortesías</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuánto se puede regalar en hospedaje al mes. Solo el
                            propietario lo cambia.
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 md:flex md:items-center">
                    <Link
                        :href="route('tenant.hotel-settings')"
                        class="inline-flex h-9 items-center justify-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a Ajustes
                    </Link>
                    <Button
                        :as="Link"
                        :href="route('tenant.reservations.courtesies')"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs dark:bg-darkmode-600"
                    >
                        <Lucide icon="ListChecks" class="mr-1.5 h-3.5 w-3.5" />
                        Ver cortesías
                    </Button>
                </div>
            </div>

            <form class="mt-4 grid grid-cols-12 gap-5" @submit.prevent="submit">
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked h-full p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Gauge" class="h-3.5 w-3.5" />
                            Tope mensual
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Suma de lo aprobado en el mes. Vacío = sin tope.
                            Pasarlo no se bloquea: el propietario lo confirma
                            una segunda vez y queda marcado en el reporte.
                        </p>
                        <div class="flex flex-wrap items-start gap-2">
                            <div class="relative w-full sm:w-56">
                                <Lucide
                                    icon="DollarSign"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 text-slate-400"
                                />
                                <FormInput
                                    v-model="capInput"
                                    type="number"
                                    inputmode="decimal"
                                    min="0"
                                    step="0.01"
                                    class="h-9 pl-9 text-xs"
                                    placeholder="Sin tope"
                                />
                            </div>
                            <Button
                                type="submit"
                                variant="primary"
                                class="h-9 w-full rounded-[0.5rem] px-5 text-xs sm:w-auto"
                                :disabled="saving || invalid || !changed"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{ saving ? 'Guardando…' : 'Guardar tope' }}
                            </Button>
                        </div>
                        <p v-if="invalid" class="mt-2 text-xs text-danger">
                            Escribe un monto en pesos, o déjalo vacío.
                        </p>
                    </div>
                </div>

                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked h-full p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CalendarDays" class="h-3.5 w-3.5" />
                            {{ monthLabel }}
                        </div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="text-sm font-medium">{{
                                money(monthUsed)
                            }}</span>
                            <span class="text-xs text-slate-500">
                                {{
                                    cap
                                        ? `regalado de ${money(cap)}`
                                        : 'regalado, sin tope'
                                }}
                            </span>
                        </div>
                        <div
                            v-if="cap"
                            class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    percent >= 100 ? 'bg-danger' : 'bg-pending'
                                "
                                :style="{ width: `${percent}%` }"
                            />
                        </div>
                        <p class="mt-3 text-xs text-slate-500">
                            Quién puede pedir cortesías se decide en Usuarios →
                            Roles con el permiso "Solicitar cortesías". Aprobar
                            es siempre del propietario.
                        </p>
                    </div>
                </div>
            </form>
        </div>
    </RazeLayout>
</template>
