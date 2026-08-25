<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string; timezone: string };
    settings: {
        check_in_time: string;
        check_out_time: string;
        currency: string;
        currency_secondary: string | null;
        exchange_rate: number | null;
    };
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    timezone: props.property.timezone,
    check_in_time: props.settings.check_in_time,
    check_out_time: props.settings.check_out_time,
    currency: props.settings.currency,
    currency_mode: props.settings.currency_secondary ? 'both' : 'single',
    currency_secondary: props.settings.currency_secondary ?? 'USD',
    exchange_rate: props.settings.exchange_rate ?? '',
});

const iconInput =
    'absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.3] text-slate-400';

const rateExample = computed(() =>
    form.currency_mode === 'both' && form.exchange_rate !== ''
        ? Number(form.exchange_rate)
        : null,
);

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        // El PATCH hace merge: esta pantalla solo manda lo suyo.
        await axios.patch(`/api/properties/${props.property.id}`, {
            timezone: form.timezone,
            settings: {
                check_in_time: form.check_in_time || null,
                check_out_time: form.check_out_time || null,
                currency: form.currency || null,
                currency_secondary:
                    form.currency_mode === 'both'
                        ? form.currency_secondary
                        : null,
                exchange_rate:
                    form.currency_mode === 'both' && form.exchange_rate !== ''
                        ? Number(form.exchange_rate)
                        : null,
            },
        });
        toast.success('Guardado', 'Horarios y moneda actualizados.');
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) =>
                    (errors[key.replace('settings.', '')] = (
                        msgs as string[]
                    )[0]),
            );
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Horarios y moneda">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-wrap items-center justify-between gap-4 p-5"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <div
                        class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Clock" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">Horarios y moneda</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            A qué hora entra y sale el huésped, en qué moneda
                            cobras y con qué reloj corre la operación.
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.general-settings')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Datos generales
                </Button>
            </div>

            <div class="mt-5 grid grid-cols-12 items-start gap-6">
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Clock"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Horarios de la casa
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Se usan cuando la tarifa no define los suyos.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Check-in desde</label
                                >
                                <FormInput
                                    v-model="form.check_in_time"
                                    type="time"
                                />
                                <FormHelp
                                    v-if="errors.check_in_time"
                                    class="text-danger"
                                    >{{ errors.check_in_time }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-sm"
                                    >Check-out hasta</label
                                >
                                <FormInput
                                    v-model="form.check_out_time"
                                    type="time"
                                />
                                <FormHelp
                                    v-if="errors.check_out_time"
                                    class="text-danger"
                                    >{{ errors.check_out_time }}</FormHelp
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Globe"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-base font-medium">
                                    Moneda y zona horaria
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                En qué moneda cobras y con qué reloj corren los
                                cortes y los plazos.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label class="mb-1 block text-sm">Moneda</label>
                                <FormSelect v-model="form.currency">
                                    <option value="MXN">
                                        Peso mexicano (MXN)
                                    </option>
                                    <option value="USD">Dólar (USD)</option>
                                </FormSelect>
                                <FormHelp
                                    v-if="errors.currency"
                                    class="text-danger"
                                    >{{ errors.currency }}</FormHelp
                                >
                            </div>
                            <!-- Doble moneda: muestra el "aprox" en la otra divisa -->
                            <div class="mt-4">
                                <label class="mb-1 block text-sm"
                                    >¿Mostrar precios en dos monedas?</label
                                >
                                <FormSelect v-model="form.currency_mode">
                                    <option value="single">
                                        Solo {{ form.currency }}
                                    </option>
                                    <option value="both">
                                        Ambas (con tipo de cambio)
                                    </option>
                                </FormSelect>
                                <FormHelp
                                    >El cobro siempre es en {{ form.currency }};
                                    la segunda moneda se muestra como referencia
                                    ("aprox").</FormHelp
                                >
                            </div>
                            <div
                                v-if="form.currency_mode === 'both'"
                                class="mt-3 grid grid-cols-1 gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 p-3 sm:grid-cols-2 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Segunda moneda</label
                                    >
                                    <FormSelect
                                        v-model="form.currency_secondary"
                                    >
                                        <option value="USD">Dólar (USD)</option>
                                        <option value="MXN">
                                            Peso mexicano (MXN)
                                        </option>
                                    </FormSelect>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm"
                                        >Tipo de cambio</label
                                    >
                                    <FormInput
                                        v-model="form.exchange_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        placeholder="18.00"
                                    />
                                    <FormHelp
                                        >1 {{ form.currency_secondary }} =
                                        {{ form.exchange_rate || '…' }}
                                        {{ form.currency }}</FormHelp
                                    >
                                    <FormHelp
                                        v-if="errors.exchange_rate"
                                        class="text-danger"
                                        >{{ errors.exchange_rate }}</FormHelp
                                    >
                                </div>
                            </div>
                            <div class="mt-4">
                                <label class="mb-1 block text-sm"
                                    >Zona horaria</label
                                >
                                <div class="relative">
                                    <Lucide icon="Globe" :class="iconInput" />
                                    <FormInput
                                        v-model="form.timezone"
                                        type="text"
                                        class="pl-9"
                                        placeholder="America/Mexico_City"
                                    />
                                </div>
                                <FormHelp
                                    v-if="errors.timezone"
                                    class="text-danger"
                                    >{{ errors.timezone }}</FormHelp
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="saving"
                        @click="submit"
                    >
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
