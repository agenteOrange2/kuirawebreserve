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
    FormTextarea,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';
import TenantHeader from './TenantHeader.vue';
import type { PlanOption, TenantShell } from './types';

const props = defineProps<{
    tenant: TenantShell;
    plans: PlanOption[];
    ai: {
        enabled: boolean;
        limit: number | null;
        used: number;
        tokens: number;
        byok_allowed: boolean;
        api_allowed: boolean;
        monthly_reply_limit: number | null;
        provider_id: number | null;
        ai_in_plan: boolean;
        plan_replies: number | null;
    };
    providers: Array<{
        id: number;
        label: string;
        model: string;
        active: boolean;
    }>;
    platformInstructions: string | null;
    template: string;
    prompt: string;
    contextEditable: boolean;
    guidelinesEditable: boolean;
}>();

const toast = useToasts();

// Ajustes del bot: se guardan al vuelo, con reversión si el servidor
// rechaza (mismo patrón que la vista vieja de contexto).
const settings = reactive({
    enabled: props.ai.enabled,
    byok_allowed: props.ai.byok_allowed,
    api_allowed: props.ai.api_allowed,
    context_editable: props.contextEditable,
    guidelines_editable: props.guidelinesEditable,
});

async function patch(
    payload: Record<string, unknown>,
    success = 'Ajuste guardado',
): Promise<boolean> {
    try {
        await axios.patch(
            route('admin.ai.tenants.update', props.tenant.id),
            payload,
        );
        toast.success(success);

        return true;
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );

        return false;
    }
}

async function toggle(key: keyof typeof settings, label: string) {
    const value = !settings[key];
    settings[key] = value;
    const ok = await patch({ [key]: value }, label);
    if (!ok) settings[key] = !value;
}

// Cuota: vacío = la que traiga el plan; un número la fija para este hotel.
const limitInput = ref(
    props.ai.monthly_reply_limit === null
        ? ''
        : String(props.ai.monthly_reply_limit),
);
const providerInput = ref<string | number>(props.ai.provider_id ?? '');

function saveLimit() {
    patch(
        {
            monthly_reply_limit:
                limitInput.value.trim() === ''
                    ? null
                    : Number(limitInput.value),
        },
        'Cuota actualizada',
    );
}

function saveProvider() {
    patch(
        {
            platform_ai_provider_id:
                providerInput.value === '' ? null : Number(providerInput.value),
        },
        'Proveedor actualizado',
    );
}

// Permisos en dos grupos: lo que el hotel VE en su panel y lo que puede
// USAR por su cuenta. Son cuatro interruptores; en cuatro tarjetas se
// veían como cuatro secciones distintas y dejaban huecos.
const permisos: Array<{
    title: string;
    icon: Icon;
    rows: Array<{
        key:
            | 'context_editable'
            | 'guidelines_editable'
            | 'byok_allowed'
            | 'api_allowed';
        label: string;
        help: string;
        toast: string;
    }>;
}> = [
    {
        title: 'Páginas en su panel',
        icon: 'PanelsTopLeft',
        rows: [
            {
                key: 'context_editable',
                label: 'Ver y editar su contexto',
                help: 'Habilita /asistente/contexto; apagado, el contexto lo gestiona solo la plataforma.',
                toast: 'Visibilidad actualizada',
            },
            {
                key: 'guidelines_editable',
                label: 'Capturar aprendizajes',
                help: 'Habilita /asistente/aprendizajes y el botón "Enseñar al asistente" en su Bandeja.',
                toast: 'Visibilidad actualizada',
            },
        ],
    },
    {
        title: 'Capacidades técnicas',
        icon: 'Plug',
        rows: [
            {
                key: 'byok_allowed',
                label: 'Usar sus propias llaves (BYOK)',
                help: 'Paga su consumo directo al proveedor y deja de gastar cuota nuestra.',
                toast: 'Permiso actualizado',
            },
            {
                key: 'api_allowed',
                label: 'Acceso a la API del agente',
                help: 'Permite que sistemas del hotel consulten y aparten por la Agent API.',
                toast: 'Permiso actualizado',
            },
        ],
    },
];

const quotaPercent = computed(() => {
    if (!props.ai.limit) return null;

    return Math.min(100, Math.round((props.ai.used / props.ai.limit) * 100));
});

// ── Instrucciones de plataforma y prompt efectivo ──
const instructions = ref(props.platformInstructions ?? '');
const promptText = ref(props.prompt);
const savingInstructions = ref(false);
const refreshing = ref(false);
const confirmTemplate = ref(false);

function useTemplate() {
    // Con texto capturado se pide confirmación antes de reemplazarlo.
    if (instructions.value.trim()) {
        confirmTemplate.value = true;

        return;
    }
    applyTemplate();
}

function applyTemplate() {
    instructions.value = props.template;
    confirmTemplate.value = false;
}

async function refreshPrompt() {
    refreshing.value = true;
    try {
        const { data } = await axios.get<{ prompt: string }>(
            route('admin.ai.tenants.prompt', props.tenant.id),
        );
        promptText.value = data.prompt;
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ??
                'Ocurrió un error al cargar el prompt.',
        );
    } finally {
        refreshing.value = false;
    }
}

async function saveInstructions() {
    savingInstructions.value = true;
    const ok = await patch(
        { platform_instructions: instructions.value.trim() || null },
        'Instrucciones guardadas',
    );
    if (ok) await refreshPrompt();
    savingInstructions.value = false;
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Asistente`">
        <TenantHeader :tenant="tenant" :plans="plans" active="assistant" />

        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Estado y cuota -->
            <div class="col-span-12 xl:col-span-4">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="Bot"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">Estado del bot</h2>
                        <Link
                            :href="route('admin.ai')"
                            class="ml-auto flex items-center text-xs text-primary"
                        >
                            Llaves de plataforma
                            <Lucide icon="ArrowRight" class="ml-1 h-3 w-3" />
                        </Link>
                    </div>
                    <div class="flex flex-1 flex-col gap-4 p-5 text-sm">
                        <div
                            v-if="!ai.ai_in_plan"
                            class="flex items-start gap-2 rounded-lg bg-pending/10 px-3 py-2.5 text-xs text-pending"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-4 w-4 shrink-0"
                            />
                            <span>
                                Su plan no incluye IA. Se puede encender igual
                                (cortesía o prueba), pero es la palanca natural
                                de upsell.
                            </span>
                        </div>

                        <div
                            class="flex items-center justify-between rounded-lg border border-dashed border-slate-300/70 px-3 py-2.5 dark:border-darkmode-400"
                        >
                            <div class="pr-3">
                                <span class="text-sm">Bot encendido</span>
                                <FormHelp class="mt-0">
                                    Apagado, sus canales siguen recibiendo pero
                                    nadie contesta solo.
                                </FormHelp>
                            </div>
                            <FormSwitch class="shrink-0">
                                <FormSwitch.Input
                                    :checked="settings.enabled"
                                    type="checkbox"
                                    @change="
                                        toggle(
                                            'enabled',
                                            settings.enabled
                                                ? 'Bot apagado'
                                                : 'Bot encendido',
                                        )
                                    "
                                />
                            </FormSwitch>
                        </div>

                        <div
                            class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-1.5 flex items-center justify-between"
                            >
                                <span class="text-slate-500"
                                    >Respuestas del mes</span
                                >
                                <span class="text-xs text-slate-500"
                                    >{{ ai.used
                                    }}{{
                                        ai.limit
                                            ? ` / ${ai.limit}`
                                            : ' · sin límite'
                                    }}</span
                                >
                            </div>
                            <div
                                v-if="ai.limit"
                                class="h-2 rounded-full bg-slate-200/70 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-2 rounded-full"
                                    :class="
                                        (quotaPercent ?? 0) >= 90
                                            ? 'bg-danger'
                                            : (quotaPercent ?? 0) >= 70
                                              ? 'bg-warning'
                                              : 'bg-success'
                                    "
                                    :style="{ width: `${quotaPercent}%` }"
                                />
                            </div>
                            <div class="mt-1 text-xs text-slate-400">
                                {{ ai.tokens.toLocaleString('es-MX') }} tokens
                                consumidos este mes
                            </div>
                        </div>

                        <div
                            class="mt-auto border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400"
                        >
                            <div
                                class="mb-2 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    icon="SlidersHorizontal"
                                    class="h-3.5 w-3.5"
                                />
                                Ajustes de este hotel
                            </div>
                            <label class="mb-1 block text-sm"
                                >Cuota mensual de respuestas</label
                            >
                            <div class="flex gap-2">
                                <FormInput
                                    v-model="limitInput"
                                    type="number"
                                    min="0"
                                    :placeholder="
                                        ai.plan_replies === null
                                            ? 'Sin límite'
                                            : `${ai.plan_replies} (del plan)`
                                    "
                                />
                                <Button
                                    variant="outline-primary"
                                    class="shrink-0 bg-white"
                                    @click="saveLimit"
                                    >Guardar</Button
                                >
                            </div>
                            <FormHelp>
                                Vacío = la que traiga su plan más lo que sumen
                                sus servicios adicionales.
                            </FormHelp>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm"
                                >Proveedor forzado</label
                            >
                            <FormSelect
                                v-model="providerInput"
                                @change="saveProvider"
                            >
                                <option value="">
                                    Cadena de la plataforma
                                </option>
                                <option
                                    v-for="p in providers"
                                    :key="p.id"
                                    :value="p.id"
                                >
                                    {{ p.label }} · {{ p.model
                                    }}{{ p.active ? '' : ' (inactivo)' }}
                                </option>
                            </FormSelect>
                            <FormHelp>
                                Sin forzar, el bot usa la cadena de proveedores
                                de la plataforma en orden.
                            </FormHelp>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permisos: renglones agrupados por naturaleza. En rejilla
                 de tarjetas quedaban cuatro cajas con un hueco enorme
                 debajo de cada texto corto -->
            <div class="col-span-12 xl:col-span-8">
                <div class="box box--stacked flex h-full flex-col">
                    <div
                        class="flex items-center gap-2 border-b border-dashed border-slate-300/70 px-5 py-4"
                    >
                        <Lucide
                            icon="KeyRound"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Qué puede tocar el hotel
                        </h2>
                    </div>
                    <div class="flex flex-1 flex-col gap-5 p-5">
                        <div
                            v-for="grupo in permisos"
                            :key="grupo.title"
                            class="flex flex-1 flex-col"
                        >
                            <div
                                class="flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                            >
                                <Lucide
                                    :icon="grupo.icon"
                                    class="h-3.5 w-3.5"
                                />
                                {{ grupo.title }}
                            </div>
                            <div
                                class="mt-2 flex flex-1 flex-col divide-y divide-dashed divide-slate-200/70 rounded-lg border border-slate-200/70 dark:divide-darkmode-400 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="row in grupo.rows"
                                    :key="row.key"
                                    class="flex flex-1 items-center gap-4 px-3.5 py-3"
                                >
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm font-medium">
                                            {{ row.label }}
                                        </div>
                                        <p
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            {{ row.help }}
                                        </p>
                                    </div>
                                    <FormSwitch class="shrink-0">
                                        <FormSwitch.Input
                                            :checked="settings[row.key]"
                                            type="checkbox"
                                            @change="toggle(row.key, row.toast)"
                                        />
                                    </FormSwitch>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-5 grid grid-cols-12 gap-5">
            <!-- Instrucciones de plataforma -->
            <div class="col-span-12 xl:col-span-6">
                <div
                    class="box box--stacked flex h-full flex-col p-5 xl:h-[38rem]"
                >
                    <h2 class="text-base font-medium">
                        Instrucciones de plataforma
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Definen cómo cotiza, cómo aparta y qué dice de pagos
                        este bot; van por encima de las instrucciones del hotel
                        y debajo de las reglas de seguridad — nunca cobra ni
                        confirma reservas.
                    </p>
                    <FormTextarea
                        v-model="instructions"
                        rows="10"
                        class="mt-4 min-h-0 flex-1 resize-none font-mono text-xs"
                        placeholder="Ej. — Cotiza siempre primero la opción más económica. — El pago se registra en recepción: nunca pidas datos de tarjeta. — Para reservar exige nombre completo y teléfono."
                    />
                    <FormHelp
                        >Vacío = sin instrucciones extra de
                        plataforma.</FormHelp
                    >
                    <div
                        class="mt-4 flex flex-wrap items-center justify-end gap-2"
                    >
                        <Button
                            variant="outline-primary"
                            class="rounded-[0.5rem] bg-white"
                            @click="useTemplate"
                        >
                            <Lucide
                                icon="FileText"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            Usar plantilla base
                        </Button>
                        <Button
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="savingInstructions"
                            @click="saveInstructions"
                        >
                            <Lucide
                                icon="Check"
                                class="mr-2 h-4 w-4 stroke-[1.3]"
                            />
                            {{ savingInstructions ? 'Guardando…' : 'Guardar' }}
                        </Button>
                    </div>
                    <p
                        class="mt-4 flex items-start gap-2 border-t border-dashed border-slate-300/70 pt-4 text-xs text-slate-500 dark:border-darkmode-400"
                    >
                        <Lucide
                            icon="Info"
                            class="mt-0.5 h-4 w-4 shrink-0 text-slate-500"
                        />
                        <span>
                            La plantilla cubre los errores más comunes del bot:
                            mezclar tarifas de otro tipo de habitación,
                            confundir el precio por unidad con el total, y
                            apartar sin confirmar el monto.
                        </span>
                    </p>
                </div>
            </div>

            <!-- Prompt efectivo -->
            <div class="col-span-12 xl:col-span-6">
                <div
                    class="box box--stacked flex h-full flex-col p-5 xl:h-[38rem]"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-2"
                    >
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Prompt efectivo
                            </h2>
                            <p class="mt-1 text-sm text-slate-500">
                                Así ve el mundo este bot: identidad, datos del
                                hotel, tarifas, FAQs, tus instrucciones y las
                                reglas — armado en vivo.
                            </p>
                        </div>
                        <Button
                            variant="outline-secondary"
                            size="sm"
                            class="shrink-0 rounded-[0.5rem] bg-white"
                            :disabled="refreshing"
                            @click="refreshPrompt"
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="mr-1.5 h-3.5 w-3.5"
                                :class="{ 'animate-spin': refreshing }"
                            />
                            Actualizar
                        </Button>
                    </div>
                    <pre
                        class="mt-4 max-h-96 min-h-0 flex-1 overflow-auto rounded bg-slate-50 p-4 font-mono text-xs break-words whitespace-pre-wrap text-slate-600 xl:max-h-none dark:bg-darkmode-700 dark:text-slate-300"
                        >{{ promptText }}</pre
                    >
                </div>
            </div>
        </div>

        <!-- Confirmación para reemplazar con la plantilla base -->
        <Dialog :open="confirmTemplate" @close="confirmTemplate = false">
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-warning/10 text-warning"
                        >
                            <Lucide icon="FileText" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Reemplazar con la plantilla base?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                El texto actual del cuadro se perderá. No se
                                guarda nada hasta que presiones Guardar.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="confirmTemplate = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="shadow-md shadow-primary/20"
                            @click="applyTemplate"
                        >
                            <Lucide icon="FileText" class="mr-2 h-4 w-4" /> Sí,
                            reemplazar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
