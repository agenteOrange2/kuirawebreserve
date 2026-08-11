<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSelect, FormSwitch } from '@/components/Base/Form';
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
                    <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" />
                    Volver a Métodos de pago
                </Button>
            </div>

            <form
                class="box box--stacked mt-5 flex flex-col p-5"
                @submit.prevent="submit"
            >
                <!-- Plazos: apartado, transferencia y pago en el hotel — cada método su reloj -->
                <div class="grid grid-cols-12 gap-3">
                    <div
                        class="col-span-12 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 xl:col-span-6 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <div class="text-sm font-medium">
                            Duración del apartado
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuánto vive una reserva pendiente antes de liberarse
                            sola si nadie la confirma ni la paga. Aplica al
                            wizard, al asistente y al panel.
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <FormInput
                                v-model.number="form.hold_value"
                                type="number"
                                min="1"
                                max="999"
                                class="!w-24 text-center"
                            />
                            <FormSelect v-model="form.hold_unit" class="!w-40">
                                <option value="minute">Minutos</option>
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                        </div>
                        <FormHelp
                            v-if="errors.hold_value || errors.hold_unit"
                            class="text-danger"
                            >{{
                                errors.hold_value ?? errors.hold_unit
                            }}</FormHelp
                        >
                    </div>
                    <div
                        class="col-span-12 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 xl:col-span-6 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <div class="text-sm font-medium">
                            Vigencia de un cobro por transferencia
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuánto tiempo tiene el huésped para transferir y
                            mandar su comprobante. Mientras el cobro viva, el
                            apartado se extiende con él.
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <FormInput
                                v-model.number="form.transfer_valid_value"
                                type="number"
                                min="1"
                                max="999"
                                class="!w-24 text-center"
                            />
                            <FormSelect
                                v-model="form.transfer_valid_unit"
                                class="!w-40"
                            >
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                        </div>
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
                    <div
                        v-if="showCashDeadline"
                        class="col-span-12 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 xl:col-span-6 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <div class="text-sm font-medium">
                            Plazo para pagar en el hotel
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuánto tiempo tiene el huésped para venir a pagar
                            cuando eligió "Pago en el hotel". El apartado se
                            extiende hasta ese plazo y se libera solo si nadie
                            paga ni confirma. Nunca pasa de la hora de llegada.
                        </p>
                        <div class="mt-2 flex items-center gap-2">
                            <FormInput
                                v-model.number="form.cash_deadline_value"
                                type="number"
                                min="1"
                                max="999"
                                class="!w-24 text-center"
                            />
                            <FormSelect
                                v-model="form.cash_deadline_unit"
                                class="!w-40"
                            >
                                <option value="minute">Minutos</option>
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                        </div>
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
                </div>

                <!-- Fecha límite de pago total: interruptor del módulo + default -->
                <div
                    class="mt-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="text-sm">
                            <div class="font-medium">
                                Exigir el pago total antes de la llegada
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Cada reserva nace con una fecha límite para
                                cubrir el total. Si la tarifa define su propia
                                anticipación, esa manda; si no, se usa el plazo
                                de aquí. Apagado: nadie tiene fecha límite y no
                                se piden saldos automáticos.
                            </p>
                        </div>
                        <FormSwitch class="mt-1">
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
                        class="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400"
                    >
                        <span class="text-xs text-slate-500"
                            >Plazo default (cuando la tarifa no define el
                            suyo):</span
                        >
                        <FormInput
                            v-model.number="form.balance_due_value"
                            type="number"
                            min="1"
                            max="365"
                            class="!w-24 text-center"
                        />
                        <FormSelect v-model="form.balance_due_unit" class="!w-40">
                            <option value="day">Días</option>
                            <option value="week">Semanas</option>
                        </FormSelect>
                        <span class="text-xs text-slate-500"
                            >antes de la llegada</span
                        >
                    </div>
                </div>

                <div
                    v-if="form.balance_due_enabled"
                    class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <div class="text-sm">
                        <div class="font-medium">
                            Cobro automático del saldo
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            El asistente pide el saldo por el chat con esta
                            anticipación a la fecha límite y recuerda 24 horas
                            antes de que venza.
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
                        <span class="text-xs text-slate-500">días antes</span>
                    </div>
                </div>

                <div
                    v-if="form.balance_due_enabled"
                    class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <div class="text-sm">
                        <div class="font-medium">
                            Cancelar reservas con saldo vencido
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Apagado (recomendado): el saldo vencido solo genera
                            una alerta en la bandeja y tu equipo decide.
                            Encendido: la reserva se cancela sola al vencer y se
                            avisa al huésped.
                        </p>
                    </div>
                    <FormSwitch class="mt-1">
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

                <div
                    class="mt-3 flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <div class="text-sm">
                        <div class="font-medium">
                            Confirmar reservas automáticamente al recibir el
                            pago
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Cuando un pago verificado cubre el anticipo, la
                            reserva se confirma sola y se avisa al huésped.
                            Apágalo si prefieres confirmar cada reserva a mano.
                        </p>
                    </div>
                    <FormSwitch class="mt-1">
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

                <div class="mt-5 flex justify-end">
                    <Button
                        type="submit"
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="saving"
                    >
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </form>
        </div>
    </RazeLayout>
</template>
