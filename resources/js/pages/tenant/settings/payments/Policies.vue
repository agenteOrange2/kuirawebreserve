<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
    FormTextarea,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        cancel_policy_enabled: boolean;
        cancel_free_value: number;
        cancel_free_unit: string;
        cancel_penalty_percent: number;
        cancel_policy_text: string;
        walkin_charge: 'checkout' | 'checkin';
        guarantee_enabled: boolean;
        guarantee_amount: number;
    };
    ratePlansWithCancelPolicy: number;
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    cancel_policy_enabled: props.settings.cancel_policy_enabled,
    cancel_free_value: props.settings.cancel_free_value,
    cancel_free_unit: props.settings.cancel_free_unit,
    cancel_penalty_percent: props.settings.cancel_penalty_percent,
    cancel_policy_text: props.settings.cancel_policy_text,
    walkin_charge: props.settings.walkin_charge,
    guarantee_enabled: props.settings.guarantee_enabled,
    guarantee_amount: props.settings.guarantee_amount as string | number,
});

// La política como la leerá el huésped (mismo formato que el backend).
const cancelPolicyPreview = computed(() => {
    const value = form.cancel_free_value || 0;
    const plural = value !== 1;
    const unitWord =
        form.cancel_free_unit === 'hour'
            ? plural
                ? 'horas'
                : 'hora'
            : form.cancel_free_unit === 'week'
              ? plural
                  ? 'semanas'
                  : 'semana'
              : plural
                ? 'días'
                : 'día';
    const penalty = form.cancel_penalty_percent;
    const after =
        penalty >= 100
            ? 'después no hay reembolso'
            : `después se retiene el ${penalty}% de lo pagado`;

    return `Cancelación sin costo hasta ${value} ${unitWord} antes de la llegada; ${after}.`;
});

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        // El PATCH de settings hace merge en el backend: mandar solo este
        // subconjunto no pisa lo demás.
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                cancel_policy_enabled: form.cancel_policy_enabled,
                cancel_free_value: form.cancel_free_value,
                cancel_free_unit: form.cancel_free_unit,
                cancel_penalty_percent: form.cancel_penalty_percent,
                cancel_policy_text: form.cancel_policy_text,
                walkin_charge: form.walkin_charge,
                guarantee_enabled: form.guarantee_enabled,
                guarantee_amount:
                    form.guarantee_amount === '' ? 0 : form.guarantee_amount,
            },
        });
        toast.success(
            'Guardado',
            'Las políticas y cargos en recepción se actualizaron.',
        );
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
    <RazeLayout title="Políticas y cobros en recepción">
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
                        <Lucide icon="Scale" class="h-7 w-7" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-xl font-medium">
                            Políticas y cobros en recepción
                        </h1>
                        <p class="mt-1 text-sm text-slate-500">
                            Reglas que no dependen del método de pago: qué pasa
                            al cancelar, cuándo se cobra un walk-in y si se pide
                            fianza al llegar.
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

            <!-- Una tarjeta por tema, con los ajustes como renglones dentro:
                 label y explicación a la izquierda, control siempre pegado al
                 borde derecho para que queden alineados entre sí. -->
            <div class="box box--stacked mt-5">
                <div
                    class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2">
                        <Lucide
                            icon="CalendarX2"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Política de cancelación
                        </h2>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Hasta cuándo puede cancelar el huésped con reembolso y
                        qué se retiene después.
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
                                Aplicar una política de cancelación
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se muestra al reservar, gobierna el botón de
                                cancelar de la consulta pública y calcula el
                                reembolso sugerido en el panel. Si una tarifa
                                define la suya, esa manda. Apagada: toda
                                cancelación con dinero pagado se revisa a mano.
                            </p>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="form.cancel_policy_enabled"
                                type="checkbox"
                                @change="
                                    form.cancel_policy_enabled =
                                        !form.cancel_policy_enabled
                                "
                            />
                        </FormSwitch>
                    </div>

                    <div
                        v-if="form.cancel_policy_enabled"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Reembolso completo hasta
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Antes de este plazo, cancelar no cuesta nada.
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.cancel_free_value"
                                type="number"
                                min="1"
                                max="365"
                                class="!w-20 text-center"
                            />
                            <FormSelect
                                v-model="form.cancel_free_unit"
                                class="!w-36"
                            >
                                <option value="hour">Horas</option>
                                <option value="day">Días</option>
                                <option value="week">Semanas</option>
                            </FormSelect>
                            <span class="text-xs text-slate-500"
                                >antes de llegar</span
                            >
                        </div>
                    </div>

                    <div
                        v-if="form.cancel_policy_enabled"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Retención después de ese plazo
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Porcentaje de lo pagado que se queda el hotel.
                                Con 100 no hay reembolso.
                            </p>
                            <FormHelp
                                v-if="
                                    errors.cancel_free_value ||
                                    errors.cancel_free_unit ||
                                    errors.cancel_penalty_percent
                                "
                                class="text-danger"
                                >{{
                                    errors.cancel_free_value ??
                                    errors.cancel_free_unit ??
                                    errors.cancel_penalty_percent
                                }}</FormHelp
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <FormInput
                                v-model.number="form.cancel_penalty_percent"
                                type="number"
                                min="0"
                                max="100"
                                class="!w-20 text-center"
                            />
                            <span class="text-xs text-slate-500"
                                >% de lo pagado</span
                            >
                        </div>
                    </div>

                    <div v-if="form.cancel_policy_enabled" class="py-4">
                        <div
                            class="flex items-start gap-1.5 rounded-md border border-info/20 bg-info/5 px-3 py-2 text-xs text-slate-600 dark:text-slate-300"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0 text-info"
                            />
                            <span
                                >Así la verá el huésped: "{{
                                    cancelPolicyPreview
                                }}"</span
                            >
                        </div>
                        <div class="mt-3">
                            <div class="text-sm font-medium">
                                Condiciones adicionales (opcional)
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Se muestran junto a la política — por ejemplo
                                cómo y en cuántos días se devuelve el dinero.
                            </p>
                            <FormTextarea
                                v-model="form.cancel_policy_text"
                                class="mt-2"
                                rows="2"
                                maxlength="2000"
                                placeholder="Ej. Los reembolsos se procesan por el mismo medio de pago en un plazo de 7 días hábiles."
                            />
                            <FormHelp
                                v-if="errors.cancel_policy_text"
                                class="text-danger"
                                >{{ errors.cancel_policy_text }}</FormHelp
                            >
                        </div>
                        <p
                            v-if="ratePlansWithCancelPolicy > 0"
                            class="mt-3 flex items-start gap-1.5 text-xs text-warning"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            {{ ratePlansWithCancelPolicy }}
                            tarifa(s) activa(s) definen su propia política de
                            cancelación y mandan sobre esta para sus reservas.
                        </p>
                    </div>
                </div>
            </div>

            <div class="box box--stacked mt-5">
                <div
                    class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2">
                        <Lucide
                            icon="Building2"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Cobros en el mostrador
                        </h2>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Qué se cobra cuando el huésped llega, con o sin reserva.
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
                                Cobro de huéspedes sin reserva (walk-in)
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Al registrar la llegada: se pide el método de
                                pago y el hospedaje entra al corte desde el
                                inicio (al salir solo consumos). Al registrar la
                                salida: cuenta final con hospedaje y consumos.
                            </p>
                        </div>
                        <FormSelect
                            v-model="form.walkin_charge"
                            class="!w-60 shrink-0"
                        >
                            <option value="checkout">
                                Al registrar la salida
                            </option>
                            <option value="checkin">
                                Al registrar la llegada
                            </option>
                        </FormSelect>
                    </div>

                    <div
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Fianza (depósito en garantía)
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Monto fijo por estancia que se cobra al llegar y
                                se devuelve al salir. No cuenta como venta: el
                                corte la muestra aparte y solo ajusta el
                                efectivo esperado del arqueo.
                            </p>
                        </div>
                        <FormSwitch class="mt-1 shrink-0">
                            <FormSwitch.Input
                                :checked="form.guarantee_enabled"
                                type="checkbox"
                                @change="
                                    form.guarantee_enabled =
                                        !form.guarantee_enabled
                                "
                            />
                        </FormSwitch>
                    </div>

                    <div
                        v-if="form.guarantee_enabled"
                        class="flex flex-wrap items-start justify-between gap-4 py-4"
                    >
                        <div class="max-w-2xl min-w-0">
                            <div class="text-sm font-medium">
                                Monto de la fianza
                            </div>
                            <p class="mt-0.5 text-xs text-slate-500">
                                Con monto en 0 la fianza queda apagada aunque el
                                interruptor esté prendido. Si el personal la
                                retiene al registrar la salida (daños,
                                faltantes), se le pide el motivo y queda en el
                                registro del pago.
                            </p>
                            <FormHelp
                                v-if="errors.guarantee_amount"
                                class="text-danger"
                                >{{ errors.guarantee_amount }}</FormHelp
                            >
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <span class="text-sm text-slate-500">$</span>
                            <FormInput
                                v-model="form.guarantee_amount"
                                type="number"
                                step="0.01"
                                min="0"
                                placeholder="500.00"
                                class="!w-32 text-center"
                            />
                        </div>
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
