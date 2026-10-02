<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTime,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string; timezone: string };
    settings: {
        check_in_time: string;
        check_out_time: string;
        night_cutoff_time: string | null;
        currency: string;
        currency_secondary: string | null;
        exchange_rate: number | null;
        support_hours_enabled: boolean;
        support_hours_open: string;
        support_hours_close: string;
        support_hours_days: number[];
        support_alert_phone: { code: string; number: string } | null;
        default_alert_phone: { code: string; number: string } | null;
    };
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    timezone: props.property.timezone,
    check_in_time: props.settings.check_in_time,
    check_out_time: props.settings.check_out_time,
    night_cutoff_enabled: Boolean(props.settings.night_cutoff_time),
    night_cutoff_time: props.settings.night_cutoff_time ?? '07:00',
    currency: props.settings.currency,
    currency_mode: props.settings.currency_secondary ? 'both' : 'single',
    currency_secondary: props.settings.currency_secondary ?? 'USD',
    exchange_rate: props.settings.exchange_rate ?? '',
    support_hours_enabled: props.settings.support_hours_enabled,
    support_hours_open: props.settings.support_hours_open,
    support_hours_close: props.settings.support_hours_close,
    support_hours_days: [...props.settings.support_hours_days],
    alert_code: props.settings.support_alert_phone?.code ?? '52',
    alert_number: props.settings.support_alert_phone?.number ?? '',
});

const WEEKDAYS = [
    { iso: 1, label: 'Lun' },
    { iso: 2, label: 'Mar' },
    { iso: 3, label: 'Mié' },
    { iso: 4, label: 'Jue' },
    { iso: 5, label: 'Vie' },
    { iso: 6, label: 'Sáb' },
    { iso: 7, label: 'Dom' },
];

function toggleDay(iso: number) {
    const days = form.support_hours_days;
    const at = days.indexOf(iso);
    if (at === -1) days.push(iso);
    // Sin días no hay horario que respetar: siempre queda al menos uno.
    else if (days.length > 1) days.splice(at, 1);
}

const alertPhoneLabel = computed(() => {
    if (form.alert_number) return `+${form.alert_code} ${form.alert_number}`;
    const fallback = props.settings.default_alert_phone;
    return fallback ? `+${fallback.code} ${fallback.number}` : null;
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
                night_cutoff_time: form.night_cutoff_enabled
                    ? form.night_cutoff_time || null
                    : null,
                currency: form.currency || null,
                currency_secondary:
                    form.currency_mode === 'both'
                        ? form.currency_secondary
                        : null,
                exchange_rate:
                    form.currency_mode === 'both' && form.exchange_rate !== ''
                        ? Number(form.exchange_rate)
                        : null,
                support_hours_enabled: form.support_hours_enabled,
                support_hours_open: form.support_hours_open || null,
                support_hours_close: form.support_hours_close || null,
                support_hours_days: [...form.support_hours_days].sort(
                    (a, b) => a - b,
                ),
                support_alert_phone: form.alert_number
                    ? { code: form.alert_code, number: form.alert_number }
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
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Clock" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Horarios y moneda</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            A qué hora entra y sale el huésped, en qué moneda
                            cobras y con qué reloj corre la operación.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando
                         encima de la tarjeta. -->
                    <Link
                        :href="route('tenant.general-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Datos generales
                    </Link>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-12 items-start gap-5">
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Clock"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">
                                    Horarios de la casa
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Se usan cuando la tarifa no define los suyos.
                            </p>
                        </div>
                        <div class="space-y-4 p-5">
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Check-in desde</label
                                >
                                <FormTime
                                    v-model="form.check_in_time"
                                    input-class="h-9 text-xs"
                                />
                                <FormHelp
                                    v-if="errors.check_in_time"
                                    class="text-danger"
                                    >{{ errors.check_in_time }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Check-out hasta</label
                                >
                                <FormTime
                                    v-model="form.check_out_time"
                                    input-class="h-9 text-xs"
                                />
                                <FormHelp
                                    v-if="errors.check_out_time"
                                    class="text-danger"
                                    >{{ errors.check_out_time }}</FormHelp
                                >
                            </div>
                            <div
                                class="rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div class="text-xs">
                                        <div class="text-sm font-medium">
                                            Corte de madrugada
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Quien llega sin reserva antes de
                                            esta hora cuenta como la noche
                                            anterior y sale ese mismo día a la
                                            hora de check-out.
                                        </p>
                                    </div>
                                    <FormSwitch class="mt-1">
                                        <FormSwitch.Input
                                            :checked="form.night_cutoff_enabled"
                                            type="checkbox"
                                            @change="
                                                form.night_cutoff_enabled =
                                                    !form.night_cutoff_enabled
                                            "
                                        />
                                    </FormSwitch>
                                </div>
                                <div
                                    v-if="form.night_cutoff_enabled"
                                    class="mt-3"
                                >
                                    <FormTime
                                        v-model="form.night_cutoff_time"
                                        input-class="h-9 text-xs"
                                    />
                                    <FormHelp
                                        v-if="errors.night_cutoff_time"
                                        class="text-danger"
                                        >{{ errors.night_cutoff_time }}</FormHelp
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Globe"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">
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
                                <label class="mb-1 block text-xs">Moneda</label>
                                <FormSelect
                                    v-model="form.currency"
                                    class="h-9 text-xs"
                                >
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
                                <label class="mb-1 block text-xs"
                                    >¿Mostrar precios en dos monedas?</label
                                >
                                <FormSelect
                                    v-model="form.currency_mode"
                                    class="h-9 text-xs"
                                >
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
                                    <label class="mb-1 block text-xs"
                                        >Segunda moneda</label
                                    >
                                    <FormSelect
                                        v-model="form.currency_secondary"
                                        class="h-9 text-xs"
                                    >
                                        <option value="USD">Dólar (USD)</option>
                                        <option value="MXN">
                                            Peso mexicano (MXN)
                                        </option>
                                    </FormSelect>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs"
                                        >Tipo de cambio</label
                                    >
                                    <FormInput
                                        v-model="form.exchange_rate"
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        placeholder="18.00"
                                        class="h-9 text-xs"
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
                                <label class="mb-1 block text-xs"
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

                <!-- Horario de ATENCIÓN: quién contesta y a qué hora.
                     Ancho completo porque la fila de días no cabe en media. -->
                <div class="col-span-12">
                    <div class="box box--stacked">
                        <div
                            class="border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div class="flex items-center gap-2">
                                <Lucide
                                    icon="Headset"
                                    class="h-4 w-4 stroke-[1.5] text-primary"
                                />
                                <h2 class="text-sm font-medium">
                                    Horario de atención
                                </h2>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                Cuándo hay alguien para contestar el chat. No es
                                el check-in: es tu turno de trabajo.
                            </p>
                        </div>
                        <div class="p-5">
                            <div
                                class="flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div class="text-xs">
                                    <div class="text-sm font-medium">
                                        Atender solo en un horario
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Apagado, el asistente contesta como
                                        siempre. Encendido, fuera de esas horas
                                        sigue cotizando y apartando, pero le
                                        dice al huésped que el equipo retoma al
                                        día siguiente en vez de prometer que lo
                                        atienden en un momento.
                                    </p>
                                </div>
                                <FormSwitch class="mt-1">
                                    <FormSwitch.Input
                                        :checked="form.support_hours_enabled"
                                        type="checkbox"
                                        @change="
                                            form.support_hours_enabled =
                                                !form.support_hours_enabled
                                        "
                                    />
                                </FormSwitch>
                            </div>

                            <div
                                v-if="form.support_hours_enabled"
                                class="mt-4 grid grid-cols-12 gap-4"
                            >
                                <div class="col-span-6 sm:col-span-3">
                                    <label class="mb-1 block text-xs"
                                        >Abren a las</label
                                    >
                                    <FormTime
                                        v-model="form.support_hours_open"
                                        input-class="h-9 text-xs"
                                    />
                                    <FormHelp
                                        v-if="errors.support_hours_open"
                                        class="text-danger"
                                        >{{
                                            errors.support_hours_open
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-6 sm:col-span-3">
                                    <label class="mb-1 block text-xs"
                                        >Cierran a las</label
                                    >
                                    <FormTime
                                        v-model="form.support_hours_close"
                                        input-class="h-9 text-xs"
                                    />
                                    <FormHelp
                                        v-if="errors.support_hours_close"
                                        class="text-danger"
                                        >{{
                                            errors.support_hours_close
                                        }}</FormHelp
                                    >
                                </div>
                                <div class="col-span-12 sm:col-span-6">
                                    <label class="mb-1 block text-xs"
                                        >Días que atienden</label
                                    >
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-for="day in WEEKDAYS"
                                            :key="day.iso"
                                            type="button"
                                            class="h-9 rounded-full border px-3 text-[11px] font-medium transition"
                                            :class="
                                                form.support_hours_days.includes(
                                                    day.iso,
                                                )
                                                    ? 'border-primary/30 bg-primary/10 text-primary'
                                                    : 'border-slate-200 bg-white text-slate-500 hover:border-primary/30 dark:border-darkmode-400 dark:bg-darkmode-600'
                                            "
                                            @click="toggleDay(day.iso)"
                                        >
                                            {{ day.label }}
                                        </button>
                                    </div>
                                    <FormHelp
                                        >Los días que no marques cuentan como
                                        fuera de horario todo el día.</FormHelp
                                    >
                                </div>

                                <div class="col-span-12">
                                    <label class="mb-1 block text-xs"
                                        >WhatsApp para avisos del hotel</label
                                    >
                                    <div class="flex gap-2">
                                        <FormInput
                                            v-model="form.alert_code"
                                            type="text"
                                            class="h-9 w-16 text-xs"
                                            placeholder="52"
                                        />
                                        <FormInput
                                            v-model="form.alert_number"
                                            type="text"
                                            class="h-9 flex-1 text-xs"
                                            placeholder="6568508818"
                                        />
                                    </div>
                                    <FormHelp v-if="alertPhoneLabel"
                                        >Cuando entre una cotización fuera de
                                        horario, o el asistente pase una
                                        conversación a recepción, llega un
                                        mensaje a {{ alertPhoneLabel }} con el
                                        enlace a la bandeja.</FormHelp
                                    >
                                    <FormHelp v-else class="text-danger"
                                        >Sin número aquí ni teléfono del hotel
                                        en Contacto, el aviso solo queda en la
                                        campana del panel.</FormHelp
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="saving"
                        @click="submit"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
