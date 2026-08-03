<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormCheck,
    FormHelp,
    FormInput,
    FormSelect,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        checkin_mode: 'manual' | 'auto' | 'both';
        hk_mode: 'manual' | 'auto' | 'both';
        hk_dirty_value: number;
        hk_dirty_unit: string;
        hk_cleaning_value: number;
        hk_cleaning_unit: string;
        day_close_no_checkin: 'dirty' | 'available' | 'none';
    };
    roomCounts: { reserved: number; dirty: number; cleaning: number };
    arrivalsToday: number;
}>();

const toast = useToasts();

const errors = reactive<Record<string, string>>({});
const saving = ref(false);

const form = reactive({
    checkin_mode: props.settings.checkin_mode,
    hk_mode: props.settings.hk_mode,
    hk_dirty_value: props.settings.hk_dirty_value,
    hk_dirty_unit: props.settings.hk_dirty_unit,
    hk_cleaning_value: props.settings.hk_cleaning_value,
    hk_cleaning_unit: props.settings.hk_cleaning_unit,
    day_close_no_checkin: props.settings.day_close_no_checkin,
});

const checkinOptions: {
    value: 'manual' | 'auto' | 'both';
    label: string;
    help: string;
}[] = [
    {
        value: 'manual',
        label: 'Manual',
        help: 'El personal registra cada llegada con el botón de check-in del plano o de la lista de reservas.',
    },
    {
        value: 'auto',
        label: 'Automático',
        help: 'A la hora de llegada, la reserva confirmada hace check-in sola: la estancia se abre y la habitación pasa a ocupada. Los botones de check-in se ocultan del panel — una llegada anticipada tendrá que esperar su hora.',
    },
    {
        value: 'both',
        label: 'Ambos',
        help: 'El personal registra la llegada cuando el huésped se presenta y, si a la hora nadie lo hizo, el sistema la registra solo.',
    },
];

const modeOptions: {
    value: 'manual' | 'auto' | 'both';
    label: string;
    help: string;
}[] = [
    {
        value: 'manual',
        label: 'Manual',
        help: 'El personal mueve cada habitación desde el plano: sucia a en limpieza cuando alguien entra a limpiar, y a disponible al terminar.',
    },
    {
        value: 'auto',
        label: 'Automático',
        help: 'Los cambios los da el reloj con los tiempos de abajo; los botones de limpieza desaparecen del plano para evitar dobles movimientos.',
    },
    {
        value: 'both',
        label: 'Ambos',
        help: 'El personal puede adelantar el cambio a mano y, si nadie lo hace, el reloj lo da solo al cumplirse el tiempo.',
    },
];

const dayCloseOptions: {
    value: 'dirty' | 'available' | 'none';
    label: string;
    help: string;
}[] = [
    {
        value: 'dirty',
        label: 'Se asume que se ocupó: pasarla a sucia',
        help: 'La reserva se marca completada y la habitación cae a sucia para que limpieza la revise. Recomendado cuando no se registra check-in en el panel.',
    },
    {
        value: 'available',
        label: 'Se asume que no llegó: liberarla',
        help: 'La reserva se marca no-show y la habitación vuelve a disponible. Recomendado cuando el check-in siempre se registra y una reservada vencida significa que nadie llegó.',
    },
    {
        value: 'none',
        label: 'No hacer nada',
        help: 'La habitación se queda en reservada y la reserva en confirmada hasta que alguien las gestione a mano.',
    },
];

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                checkin_mode: form.checkin_mode,
                hk_mode: form.hk_mode,
                hk_dirty_value: form.hk_dirty_value,
                hk_dirty_unit: form.hk_dirty_unit,
                hk_cleaning_value: form.hk_cleaning_value,
                hk_cleaning_unit: form.hk_cleaning_unit,
                day_close_no_checkin: form.day_close_no_checkin,
            },
        });
        toast.success('Guardado', 'La configuración de limpieza se actualizó.');
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
    <RazeLayout title="Operación del día">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <Link
                        href="/ajustes"
                        class="flex items-center gap-1.5 text-sm text-slate-500 hover:text-slate-700"
                    >
                        <Lucide icon="ArrowLeft" class="h-4 w-4" /> Ajustes
                    </Link>
                    <h1 class="mt-1 text-lg font-medium">Operación del día</h1>
                    <p class="text-sm text-slate-500">
                        Los relojes del plano: check-in a la hora de llegada,
                        cierre de día para reservadas vencidas y el flujo de
                        limpieza sucia, en limpieza y disponible.
                    </p>
                </div>
                <Button
                    variant="primary"
                    class="rounded-[0.5rem]"
                    :disabled="saving"
                    @click="submit"
                >
                    <Lucide
                        :icon="saving ? 'RefreshCw' : 'Save'"
                        class="mr-2 h-4 w-4"
                        :class="saving && 'animate-spin'"
                    />
                    Guardar cambios
                </Button>
            </div>

            <form class="mt-5 grid grid-cols-12 gap-6" @submit.prevent="submit">
                <!-- Check-in a la llegada: manual, por reloj o ambos -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked h-full p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="LogIn" class="h-3.5 w-3.5" />
                            Check-in a la llegada
                        </div>
                        <p class="text-xs text-slate-500">
                            Cuando llega la hora de una reserva confirmada,
                            ¿quién registra la llegada y ocupa la habitación?
                        </p>
                        <div class="mt-4 space-y-3">
                            <label
                                v-for="option in checkinOptions"
                                :key="option.value"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-dashed px-4 py-3 transition"
                                :class="
                                    form.checkin_mode === option.value
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-300/70 bg-slate-50 hover:border-slate-400/70 dark:border-darkmode-400 dark:bg-darkmode-700'
                                "
                            >
                                <FormCheck class="mt-0.5">
                                    <FormCheck.Input
                                        v-model="form.checkin_mode"
                                        type="radio"
                                        name="checkin_mode"
                                        :value="option.value"
                                    />
                                </FormCheck>
                                <span class="text-sm">
                                    <span class="font-medium">{{
                                        option.label
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >{{ option.help }}</span
                                    >
                                </span>
                            </label>
                        </div>
                        <FormHelp
                            v-if="errors.checkin_mode"
                            class="mt-2 text-danger"
                            >{{ errors.checkin_mode }}</FormHelp
                        >
                        <p
                            class="mt-4 flex items-start gap-1.5 text-xs text-slate-500"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            Solo aplica a reservas confirmadas con habitación
                            asignada. Si a la hora la habitación sigue sucia u
                            ocupada, el check-in espera y se registra en cuanto
                            se libere. La salida la cierra el check-out
                            automático de siempre.
                        </p>
                        <p
                            class="mt-2 text-xs"
                            :class="
                                arrivalsToday > 0
                                    ? 'text-success'
                                    : 'text-slate-400'
                            "
                        >
                            Hoy: {{ arrivalsToday }} llegada(s) confirmada(s).
                        </p>
                    </div>
                </div>

                <!-- Flujo de limpieza: manual, por reloj o ambos -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked h-full p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Brush" class="h-3.5 w-3.5" />
                            Flujo de limpieza
                        </div>
                        <p class="text-xs text-slate-500">
                            Cuando una habitación queda sucia (check-out o
                            cierre de día), ¿quién la avanza a en limpieza y
                            luego a disponible?
                        </p>
                        <div class="mt-4 space-y-3">
                            <label
                                v-for="option in modeOptions"
                                :key="option.value"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-dashed px-4 py-3 transition"
                                :class="
                                    form.hk_mode === option.value
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-300/70 bg-slate-50 hover:border-slate-400/70 dark:border-darkmode-400 dark:bg-darkmode-700'
                                "
                            >
                                <FormCheck class="mt-0.5">
                                    <FormCheck.Input
                                        v-model="form.hk_mode"
                                        type="radio"
                                        name="hk_mode"
                                        :value="option.value"
                                    />
                                </FormCheck>
                                <span class="text-sm">
                                    <span class="font-medium">{{
                                        option.label
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >{{ option.help }}</span
                                    >
                                </span>
                            </label>
                        </div>

                        <div
                            v-if="form.hk_mode !== 'manual'"
                            class="mt-4 space-y-3 border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-500"
                                    >De sucia a en limpieza tras</span
                                >
                                <FormInput
                                    v-model.number="form.hk_dirty_value"
                                    type="number"
                                    min="1"
                                    max="999"
                                    class="!w-24 text-center"
                                />
                                <FormSelect
                                    v-model="form.hk_dirty_unit"
                                    class="!w-36"
                                >
                                    <option value="minute">Minutos</option>
                                    <option value="hour">Horas</option>
                                </FormSelect>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-500"
                                    >De en limpieza a disponible tras</span
                                >
                                <FormInput
                                    v-model.number="form.hk_cleaning_value"
                                    type="number"
                                    min="1"
                                    max="999"
                                    class="!w-24 text-center"
                                />
                                <FormSelect
                                    v-model="form.hk_cleaning_unit"
                                    class="!w-36"
                                >
                                    <option value="minute">Minutos</option>
                                    <option value="hour">Horas</option>
                                </FormSelect>
                            </div>
                            <FormHelp
                                v-if="
                                    errors.hk_dirty_value ||
                                    errors.hk_dirty_unit ||
                                    errors.hk_cleaning_value ||
                                    errors.hk_cleaning_unit
                                "
                                class="text-danger"
                                >{{
                                    errors.hk_dirty_value ??
                                    errors.hk_dirty_unit ??
                                    errors.hk_cleaning_value ??
                                    errors.hk_cleaning_unit
                                }}</FormHelp
                            >
                            <p
                                class="flex items-start gap-1.5 text-xs text-slate-500"
                            >
                                <Lucide
                                    icon="Info"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0"
                                />
                                El tiempo corre desde el último cambio de
                                estado. Si limpieza libera antes a mano (modo
                                ambos), el reloj ya no interviene.
                            </p>
                        </div>

                        <p
                            class="mt-4 text-xs"
                            :class="
                                roomCounts.dirty + roomCounts.cleaning > 0
                                    ? 'text-pending'
                                    : 'text-slate-400'
                            "
                        >
                            Ahora mismo: {{ roomCounts.dirty }} habitación(es)
                            en sucia y {{ roomCounts.cleaning }} en limpieza.
                        </p>
                    </div>
                </div>

                <!-- Cierre de día: reservadas cuya salida venció sin check-in -->
                <div class="col-span-12 xl:col-span-6">
                    <div class="box box--stacked h-full p-5">
                        <div
                            class="mb-1 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CalendarClock" class="h-3.5 w-3.5" />
                            Cierre de día
                        </div>
                        <p class="text-xs text-slate-500">
                            Una reserva confirmada aparta su habitación en el
                            plano. Si la fecha de salida pasa y nadie registró
                            el check-in, ¿qué hacemos con esa habitación
                            reservada?
                        </p>
                        <div class="mt-4 space-y-3">
                            <label
                                v-for="option in dayCloseOptions"
                                :key="option.value"
                                class="flex cursor-pointer items-start gap-3 rounded-lg border border-dashed px-4 py-3 transition"
                                :class="
                                    form.day_close_no_checkin === option.value
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-slate-300/70 bg-slate-50 hover:border-slate-400/70 dark:border-darkmode-400 dark:bg-darkmode-700'
                                "
                            >
                                <FormCheck class="mt-0.5">
                                    <FormCheck.Input
                                        v-model="form.day_close_no_checkin"
                                        type="radio"
                                        name="day_close_no_checkin"
                                        :value="option.value"
                                    />
                                </FormCheck>
                                <span class="text-sm">
                                    <span class="font-medium">{{
                                        option.label
                                    }}</span>
                                    <span
                                        class="mt-0.5 block text-xs text-slate-500"
                                        >{{ option.help }}</span
                                    >
                                </span>
                            </label>
                        </div>
                        <FormHelp
                            v-if="errors.day_close_no_checkin"
                            class="mt-2 text-danger"
                            >{{ errors.day_close_no_checkin }}</FormHelp
                        >
                        <p
                            class="mt-4 flex items-start gap-1.5 text-xs text-slate-500"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            El cierre corre unos minutos después de la hora de
                            salida de la reserva. Las estancias con check-in
                            registrado no pasan por aquí: su check-out
                            automático ya manda la habitación a sucia.
                        </p>
                        <p
                            class="mt-2 text-xs"
                            :class="
                                roomCounts.reserved > 0
                                    ? 'text-info'
                                    : 'text-slate-400'
                            "
                        >
                            Ahora mismo: {{ roomCounts.reserved }}
                            habitación(es) en reservada.
                        </p>
                    </div>
                </div>
            </form>
        </div>
    </RazeLayout>
</template>
