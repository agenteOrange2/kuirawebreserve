<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        hold_value: number;
        hold_unit: string;
        transfer_valid_value: number;
        transfer_valid_unit: string;
        cash_deadline_value: number;
        cash_deadline_unit: string;
        balance_due_enabled: boolean;
        balance_due_value: number;
        balance_due_unit: string;
        balance_request_days: number;
        cancel_on_balance_overdue: boolean;
        auto_confirm_on_payment: boolean;
        cash_payment_enabled: boolean;
    };
    enabledMethods: Record<string, boolean>;
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

// El reloj del efectivo solo aplica si el método está disponible (gate de
// plataforma) Y el hotel lo tiene prendido — se administra desde el hub.
const showCashDeadline = computed(
    () =>
        props.enabledMethods.cash !== false &&
        props.settings.cash_payment_enabled,
);

// Resumen en palabras: son seis números que se combinan entre sí y nadie
// tiene por qué armar la película en su cabeza. Se lee arriba y cambia solo
// al mover cualquier control.
const unidad = (valor: number, unit: string) => {
    const nombres: Record<string, [string, string]> = {
        minute: ['minuto', 'minutos'],
        hour: ['hora', 'horas'],
        day: ['día', 'días'],
        week: ['semana', 'semanas'],
    };
    const [singular, plural] = nombres[unit] ?? [unit, unit];

    return `${valor} ${valor === 1 ? singular : plural}`;
};

const resumen = computed(() => {
    const lineas = [
        `Una reserva sin confirmar se libera sola tras ${unidad(form.hold_value, form.hold_unit)}.`,
        `Quien elija transferencia tiene ${unidad(form.transfer_valid_value, form.transfer_valid_unit)} para pagar y mandar su comprobante.`,
    ];

    if (showCashDeadline.value) {
        lineas.push(
            `Quien elija pagar en el hotel tiene ${unidad(form.cash_deadline_value, form.cash_deadline_unit)}, y nunca más allá de su hora de llegada.`,
        );
    }

    lineas.push(
        form.balance_due_enabled
            ? `El pago total se exige ${unidad(form.balance_due_value, form.balance_due_unit)} antes de llegar; el asistente lo pide ${unidad(form.balance_request_days, 'day')} antes de esa fecha` +
                  (form.cancel_on_balance_overdue
                      ? ', y la reserva se cancela sola si vence.'
                      : ', y si vence solo se avisa a tu equipo.')
            : 'No se exige el pago total antes de llegar: nadie tiene fecha límite.',
    );

    lineas.push(
        form.auto_confirm_on_payment
            ? 'Al recibir el anticipo, la reserva se confirma sola.'
            : 'Las reservas se confirman a mano, aunque el pago ya haya entrado.',
    );

    return lineas;
});

const form = reactive({
    hold_value: props.settings.hold_value,
    hold_unit: props.settings.hold_unit,
    transfer_valid_value: props.settings.transfer_valid_value,
    transfer_valid_unit: props.settings.transfer_valid_unit,
    cash_deadline_value: props.settings.cash_deadline_value,
    cash_deadline_unit: props.settings.cash_deadline_unit,
    balance_due_enabled: props.settings.balance_due_enabled,
    balance_due_value: props.settings.balance_due_value,
    balance_due_unit: props.settings.balance_due_unit,
    balance_request_days: props.settings.balance_request_days,
    cancel_on_balance_overdue: props.settings.cancel_on_balance_overdue,
    auto_confirm_on_payment: props.settings.auto_confirm_on_payment,
});

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        // El PATCH de settings hace merge en el backend: mandar solo este
        // subconjunto no pisa lo demás.
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                hold_value: form.hold_value,
                hold_unit: form.hold_unit,
                transfer_valid_value: form.transfer_valid_value,
                transfer_valid_unit: form.transfer_valid_unit,
                cash_deadline_value: form.cash_deadline_value,
                cash_deadline_unit: form.cash_deadline_unit,
                balance_due_enabled: form.balance_due_enabled,
                balance_due_value: form.balance_due_value,
                balance_due_unit: form.balance_due_unit,
                balance_request_days: form.balance_request_days,
                cancel_on_balance_overdue: form.cancel_on_balance_overdue,
                auto_confirm_on_payment: form.auto_confirm_on_payment,
            },
        });
        toast.success('Guardado', 'Los plazos y saldos se actualizaron.');
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
    <RazeLayout title="Plazos y saldo">
        <div class="mt-2">
            <!-- Header de tarjeta, mismo patrón que Usuarios: icono en
                 círculo + título + acción a la derecha -->
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
                        <h1 class="text-xl font-medium">Plazos y saldo</h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Cuánto vive un apartado, cuánto tiempo tiene el
                            huésped para pagar con cada método y qué pasa con el
                            saldo restante.
                        </p>
                    </div>
                </div>
                <Button
                    as="a"
                    :href="route('tenant.payment-methods')"
                    variant="outline-secondary"
                    class="rounded-[0.5rem] bg-white"
                >
                    <Lucide
                        icon="ArrowLeft"
                        class="mr-2 h-4 w-4 stroke-[1.3]"
                    />
                    Volver a Métodos de pago
                </Button>
            </div>

            <!-- Cómo queda la política, en palabras: seis números que se
                 combinan entre sí y que nadie debería tener que armar
                 mentalmente. -->
            <div class="box box--stacked mt-5 border-l-4 border-l-primary p-5">
                <div class="flex items-center gap-2 text-sm font-medium">
                    <Lucide icon="FileText" class="h-4 w-4 text-primary" />
                    Así queda tu política
                </div>
                <ul class="mt-2 space-y-1">
                    <li
                        v-for="linea in resumen"
                        :key="linea"
                        class="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300"
                    >
                        <span
                            class="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-primary"
                        />
                        {{ linea }}
                    </li>
                </ul>
            </div>

            <!-- Una tarjeta por tema, con sus ajustes como renglones dentro.
                 Antes cada ajuste era su propio recuadro punteado, así que
                 nada se veía agrupado: siete cajas hermanas flotando. -->
            <div class="box box--stacked mt-5">
                <div
                    class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2">
                        <Lucide
                            icon="Clock"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Plazos de cada método
                        </h2>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Cuánto tiempo se sostiene una reserva según cómo vaya a
                        pagar el huésped.
                    </p>
                </div>
                <div
                    class="divide-y divide-dashed divide-slate-200/80 px-5 dark:divide-darkmode-400"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Duración del apartado
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cuánto vive una reserva pendiente antes de
                                liberarse sola si nadie la confirma ni la paga.
                                Aplica al wizard, al asistente y al panel.
                            </p>
                            <FormHelp
                                v-if="errors.hold_value || errors.hold_unit"
                                class="text-danger"
                                >{{
                                    errors.hold_value ?? errors.hold_unit
                                }}</FormHelp
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.hold_value"
                                type="number"
                                min="1"
                                max="999"
                                class="!w-20 text-center"
                            />
                            <FormSelect v-model="form.hold_unit" class="!w-36">
                                <option value="minute">Minutos</option>
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                        </div>
                    </div>

                    <div
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Vigencia de un cobro por transferencia
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cuánto tiempo tiene el huésped para transferir y
                                mandar su comprobante. Mientras el cobro viva,
                                el apartado se extiende con él.
                            </p>
                            <FormHelp
                                v-if="
                                    errors.transfer_valid_value ||
                                    errors.transfer_valid_unit
                                "
                                class="text-danger"
                                >{{
                                    errors.transfer_valid_value ??
                                    errors.transfer_valid_unit
                                }}</FormHelp
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.transfer_valid_value"
                                type="number"
                                min="1"
                                max="999"
                                class="!w-20 text-center"
                            />
                            <FormSelect
                                v-model="form.transfer_valid_unit"
                                class="!w-36"
                            >
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                        </div>
                    </div>

                    <div
                        v-if="showCashDeadline"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Plazo para pagar en el hotel
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cuánto tiempo tiene el huésped para venir a
                                pagar cuando eligió "Pago en el hotel". Nunca
                                pasa de su hora de llegada.
                            </p>
                            <FormHelp
                                v-if="
                                    errors.cash_deadline_value ||
                                    errors.cash_deadline_unit
                                "
                                class="text-danger"
                                >{{
                                    errors.cash_deadline_value ??
                                    errors.cash_deadline_unit
                                }}</FormHelp
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.cash_deadline_value"
                                type="number"
                                min="1"
                                max="999"
                                class="!w-20 text-center"
                            />
                            <FormSelect
                                v-model="form.cash_deadline_unit"
                                class="!w-36"
                            >
                                <option value="minute">Minutos</option>
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                        </div>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <div
                    class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2">
                        <Lucide
                            icon="CalendarClock"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Saldo antes de la llegada
                        </h2>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Si exiges el total antes de que llegue el huésped, y qué
                        pasa si no lo cubre.
                    </p>
                </div>
                <div
                    class="divide-y divide-dashed divide-slate-200/80 px-5 dark:divide-darkmode-400"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Exigir el pago total antes de la llegada
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cada reserva nace con una fecha límite para
                                cubrir el total. Si la tarifa define su propia
                                anticipación, esa manda; si no, se usa el plazo
                                de aquí.
                            </p>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="form.balance_due_enabled"
                                type="checkbox"
                                @change="
                                    form.balance_due_enabled =
                                        !form.balance_due_enabled
                                "
                            />
                        </FormSwitch>
                    </div>

                    <div
                        v-if="form.balance_due_enabled"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Plazo por defecto
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se usa cuando la tarifa no define el suyo.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.balance_due_value"
                                type="number"
                                min="1"
                                max="365"
                                class="!w-20 text-center"
                            />
                            <FormSelect
                                v-model="form.balance_due_unit"
                                class="!w-36"
                            >
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                            <span class="text-xs text-slate-500"
                                >antes de llegar</span
                            >
                        </div>
                    </div>

                    <div
                        v-if="form.balance_due_enabled"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Cobro automático del saldo
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                El asistente pide el saldo por el chat con esta
                                anticipación a la fecha límite y recuerda 24
                                horas antes de que venza.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.balance_request_days"
                                type="number"
                                min="1"
                                max="30"
                                class="!w-20 text-center"
                            />
                            <span class="text-xs text-slate-500"
                                >días antes</span
                            >
                        </div>
                    </div>

                    <div
                        v-if="form.balance_due_enabled"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Cancelar reservas con saldo vencido
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Apagado (recomendado): el saldo vencido solo
                                genera una alerta en la bandeja y tu equipo
                                decide. Encendido: la reserva se cancela sola al
                                vencer y se avisa al huésped.
                            </p>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="form.cancel_on_balance_overdue"
                                type="checkbox"
                                @change="
                                    form.cancel_on_balance_overdue =
                                        !form.cancel_on_balance_overdue
                                "
                            />
                        </FormSwitch>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <div
                    class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2">
                        <Lucide
                            icon="Zap"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">Automatizaciones</h2>
                    </div>
                </div>
                <div class="px-5">
                    <div
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Confirmar reservas automáticamente al recibir el
                                pago
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cuando un pago verificado cubre el anticipo, la
                                reserva se confirma sola y se avisa al huésped.
                                Apágalo si prefieres confirmar cada reserva a
                                mano.
                            </p>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="form.auto_confirm_on_payment"
                                type="checkbox"
                                @change="
                                    form.auto_confirm_on_payment =
                                        !form.auto_confirm_on_payment
                                "
                            />
                        </FormSwitch>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex justify-end">
                <Button
                    type="button"
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
    </RazeLayout>
</template>
