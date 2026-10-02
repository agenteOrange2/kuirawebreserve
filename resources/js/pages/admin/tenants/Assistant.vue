<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
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

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';

// Ajustes del bot: se guardan al vuelo. El interruptor solo cambia cuando
// el servidor lo acepta (click.prevent), así un rechazo no lo deja mintiendo.
const settings = reactive({
    enabled: props.ai.enabled,
    byok_allowed: props.ai.byok_allowed,
    api_allowed: props.ai.api_allowed,
    context_editable: props.contextEditable,
    guidelines_editable: props.guidelinesEditable,
});
const savingKey = ref<string | null>(null);

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
        const errors = e.response?.data?.errors;
        toast.error(
            'No se pudo guardar',
            (errors && (Object.values(errors)[0] as string[])?.[0]) ??
                e.response?.data?.message ??
                'Ocurrió un error.',
        );
        return false;
    }
}

async function toggle(key: keyof typeof settings, label: string) {
    if (savingKey.value) return;
    savingKey.value = key;
    const value = !settings[key];
    if (await patch({ [key]: value }, label)) settings[key] = value;
    savingKey.value = null;
}

// Cuota: vacío = la que traiga el plan; un número la fija para este hotel.
const limitInput = ref(
    props.ai.monthly_reply_limit === null
        ? ''
        : String(props.ai.monthly_reply_limit),
);
const providerInput = ref<string | number>(props.ai.provider_id ?? '');
const limitDirty = computed(
    () =>
        limitInput.value.trim() !==
        (props.ai.monthly_reply_limit === null
            ? ''
            : String(props.ai.monthly_reply_limit)),
);
const limitInvalid = computed(() => {
    const raw = limitInput.value.trim();
    return raw !== '' && !(Number.isInteger(Number(raw)) && Number(raw) >= 0);
});

async function saveLimit() {
    if (limitInvalid.value || savingKey.value) return;
    savingKey.value = 'limit';
    const ok = await patch(
        {
            monthly_reply_limit:
                limitInput.value.trim() === ''
                    ? null
                    : Number(limitInput.value),
        },
        'Cuota actualizada',
    );
    savingKey.value = null;
    // La barra de uso se recalcula con la cuota nueva.
    if (ok) router.reload({ only: ['ai'] });
}

async function saveProvider() {
    savingKey.value = 'provider';
    const ok = await patch(
        {
            platform_ai_provider_id:
                providerInput.value === '' ? null : Number(providerInput.value),
        },
        'Proveedor actualizado',
    );
    if (!ok) providerInput.value = props.ai.provider_id ?? '';
    savingKey.value = null;
}

// limit 0 es "sin respuestas", no "sin límite": solo null es ilimitado.
const quotaPercent = computed(() => {
    if (props.ai.limit === null) return null;
    if (props.ai.limit === 0) return 100;
    return Math.min(100, Math.round((props.ai.used / props.ai.limit) * 100));
});
const quotaTone = computed(() =>
    (quotaPercent.value ?? 0) >= 90
        ? 'bg-danger'
        : (quotaPercent.value ?? 0) >= 70
          ? 'bg-warning'
          : 'bg-success',
);

// Permisos en dos grupos: lo que el hotel VE en su panel y lo que puede
// USAR por su cuenta.
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
                help: 'Abre Asistente, Contexto en su panel; apagado, el contexto lo lleva solo la plataforma.',
                toast: 'Visibilidad actualizada',
            },
            {
                key: 'guidelines_editable',
                label: 'Capturar aprendizajes',
                help: 'Abre Asistente, Aprendizajes y el botón "Enseñar al asistente" en su Bandeja.',
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

// ── Instrucciones de plataforma y prompt efectivo ──
const savedInstructions = ref(props.platformInstructions ?? '');
const instructions = ref(savedInstructions.value);
const instructionsDirty = computed(
    () => instructions.value.trim() !== savedInstructions.value.trim(),
);
const promptText = ref(props.prompt);
const savingInstructions = ref(false);
const refreshing = ref(false);
const confirmTemplate = ref(false);
const copied = ref(false);

// Estimación gruesa (~4 caracteres por token): para darse idea de cuánto
// pesa el prompt en cada respuesta.
const promptTokens = computed(() => Math.round(promptText.value.length / 4));

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

async function copyPrompt() {
    try {
        await navigator.clipboard.writeText(promptText.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        toast.error(
            'No se pudo copiar',
            'El navegador bloqueó el portapapeles.',
        );
    }
}

async function saveInstructions() {
    savingInstructions.value = true;
    const value = instructions.value.trim();
    const ok = await patch(
        { platform_instructions: value || null },
        'Instrucciones guardadas',
    );
    if (ok) {
        savedInstructions.value = value;
        await refreshPrompt();
    }
    savingInstructions.value = false;
}
</script>

<template>
    <RazeLayout :title="`${tenant.name} · Asistente`">
        <TenantHeader :tenant="tenant" :plans="plans" active="assistant" />

        <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
            <!-- Estado y cuota -->
            <div class="col-span-12 flex flex-col xl:col-span-5">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                settings.enabled
                                    ? 'border-success/10 bg-success/10 text-success'
                                    : 'border-slate-200 bg-slate-100 text-slate-400 dark:border-darkmode-400 dark:bg-darkmode-400',
                            ]"
                        >
                            <Lucide icon="Bot" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">Estado del bot</h2>
                            <p class="text-xs text-slate-500">
                                {{
                                    settings.enabled
                                        ? 'Contesta solo en sus canales.'
                                        : 'Apagado: nadie contesta solo.'
                                }}
                            </p>
                        </div>
                        <FormSwitch
                            class="shrink-0"
                            :title="
                                settings.enabled
                                    ? 'Apagar el bot'
                                    : 'Encender el bot'
                            "
                        >
                            <FormSwitch.Input
                                :checked="settings.enabled"
                                type="checkbox"
                                :disabled="savingKey === 'enabled'"
                                @click.prevent="
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
                    <div class="flex flex-1 flex-col gap-4 px-4 py-3 text-xs">
                        <div
                            v-if="!ai.ai_in_plan"
                            class="flex items-start gap-2 rounded-lg bg-pending/10 px-3 py-2 text-pending"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-px h-3.5 w-3.5 shrink-0"
                            />
                            Su plan no incluye IA. Se puede encender igual
                            (cortesía o prueba), pero es la palanca natural de
                            venta.
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <span class="text-slate-500"
                                    >Respuestas del mes</span
                                >
                                <span class="font-medium tabular-nums"
                                    >{{ ai.used.toLocaleString('es-MX')
                                    }}<span
                                        class="font-normal text-slate-400"
                                        >{{
                                            ai.limit !== null
                                                ? ` de ${ai.limit.toLocaleString('es-MX')}`
                                                : ' · sin límite'
                                        }}</span
                                    ></span
                                >
                            </div>
                            <div
                                v-if="ai.limit !== null"
                                class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-darkmode-400"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="quotaTone"
                                    :style="{ width: `${quotaPercent}%` }"
                                />
                            </div>
                            <div class="mt-1 text-[11px] text-slate-400">
                                {{ ai.tokens.toLocaleString('es-MX') }} tokens
                                consumidos este mes
                                <template v-if="ai.limit === 0">
                                    · con cuota 0 el bot no contesta</template
                                >
                            </div>
                        </div>

                        <div
                            class="border-t border-dashed border-slate-200/70 pt-3 dark:border-darkmode-400"
                        >
                            <label
                                for="ai-limit"
                                class="mb-1.5 block font-medium"
                                >Cuota mensual de respuestas</label
                            >
                            <div class="flex gap-2">
                                <FormInput
                                    id="ai-limit"
                                    v-model="limitInput"
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="h-9 text-xs"
                                    :placeholder="
                                        ai.plan_replies === null
                                            ? 'Sin límite'
                                            : `${ai.plan_replies} (del plan)`
                                    "
                                    @keydown.enter.prevent="saveLimit"
                                />
                                <Button
                                    variant="outline-primary"
                                    class="h-9 shrink-0 rounded-[0.5rem] px-4 text-xs"
                                    :disabled="
                                        !limitDirty ||
                                        limitInvalid ||
                                        savingKey === 'limit'
                                    "
                                    @click="saveLimit"
                                    >Guardar</Button
                                >
                            </div>
                            <FormHelp v-if="limitInvalid" class="text-danger"
                                >Debe ser un número entero, 0 o más.</FormHelp
                            >
                            <FormHelp v-else
                                >Vacío = la de su plan más lo que sumen sus
                                servicios adicionales.</FormHelp
                            >
                        </div>

                        <div>
                            <label
                                for="ai-provider"
                                class="mb-1.5 block font-medium"
                                >Proveedor forzado</label
                            >
                            <FormSelect
                                id="ai-provider"
                                v-model="providerInput"
                                class="h-9 text-xs"
                                :disabled="savingKey === 'provider'"
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
                                Sin forzar, usa los proveedores de la plataforma
                                en orden.
                                <Link
                                    :href="route('admin.ai')"
                                    class="text-primary"
                                    >Ver llaves</Link
                                >
                            </FormHelp>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permisos -->
            <div class="col-span-12 flex flex-col xl:col-span-7">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-primary/10 bg-primary/10 text-primary',
                            ]"
                        >
                            <Lucide icon="KeyRound" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-medium">
                                Qué puede tocar el hotel
                            </h2>
                            <p class="text-xs text-slate-500">
                                Se aplica al instante en su panel.
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col">
                        <template v-for="grupo in permisos" :key="grupo.title">
                            <div
                                class="flex items-center gap-1.5 border-b border-slate-200/60 bg-slate-50/70 px-4 py-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase dark:border-darkmode-400 dark:bg-darkmode-600/40"
                            >
                                <Lucide
                                    :icon="grupo.icon"
                                    class="h-3.5 w-3.5"
                                />
                                {{ grupo.title }}
                            </div>
                            <div
                                class="flex flex-1 flex-col divide-y divide-slate-200/60 border-b border-slate-200/60 last:border-b-0 dark:divide-darkmode-400 dark:border-darkmode-400"
                            >
                                <div
                                    v-for="row in grupo.rows"
                                    :key="row.key"
                                    class="flex flex-1 items-center gap-4 px-4 py-3"
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
                                            :disabled="savingKey === row.key"
                                            @click.prevent="
                                                toggle(row.key, row.toast)
                                            "
                                        />
                                    </FormSwitch>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Instrucciones de plataforma -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-info/10 bg-info/10 text-info',
                            ]"
                        >
                            <Lucide icon="ScrollText" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">
                                Instrucciones de plataforma
                            </h2>
                            <p class="text-xs text-slate-500">
                                Por encima de las del hotel y debajo de las
                                reglas de seguridad.
                            </p>
                        </div>
                        <span
                            v-if="instructionsDirty"
                            class="shrink-0 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                            >Sin guardar</span
                        >
                    </div>
                    <div class="flex min-h-0 flex-1 flex-col px-4 py-3">
                        <FormTextarea
                            v-model="instructions"
                            rows="12"
                            class="h-[24rem] resize-none font-mono text-xs"
                            placeholder="Ej. Cotiza siempre primero la opción más económica. El pago se registra en recepción."
                        />
                        <div
                            class="mt-1.5 flex items-center justify-between text-[11px] text-slate-400"
                        >
                            <span>Vacío = sin instrucciones extra.</span>
                            <span>{{ instructions.length }} caracteres</span>
                        </div>
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                    >
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] text-xs"
                            :title="'Cubre los errores más comunes: mezclar tarifas de otro tipo, confundir precio por unidad con el total y apartar sin confirmar el monto'"
                            @click="useTemplate"
                        >
                            <Lucide
                                icon="FileText"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Usar plantilla base
                        </Button>
                        <Button
                            variant="primary"
                            class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                            :disabled="savingInstructions || !instructionsDirty"
                            @click="saveInstructions"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                savingInstructions ? 'Guardando...' : 'Guardar'
                            }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Prompt efectivo -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="box box--stacked flex flex-1 flex-col">
                    <div :class="cardHeader">
                        <div
                            :class="[
                                sectionIcon,
                                'border-dark/10 bg-dark/10 text-dark dark:text-slate-300',
                            ]"
                        >
                            <Lucide icon="Eye" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-medium">Prompt efectivo</h2>
                            <p class="truncate text-xs text-slate-500">
                                Lo que recibe el modelo, armado en vivo · ~{{
                                    promptTokens.toLocaleString('es-MX')
                                }}
                                tokens
                            </p>
                        </div>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                            :title="copied ? 'Copiado' : 'Copiar'"
                            @click="copyPrompt"
                        >
                            <Lucide
                                :icon="copied ? 'Check' : 'Copy'"
                                class="h-4 w-4"
                                :class="{ 'text-success': copied }"
                            />
                        </button>
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                            title="Volver a armarlo"
                            :disabled="refreshing"
                            @click="refreshPrompt"
                        >
                            <Lucide
                                icon="RefreshCw"
                                class="h-4 w-4"
                                :class="{ 'animate-spin': refreshing }"
                            />
                        </button>
                    </div>
                    <pre
                        class="m-4 h-[28rem] overflow-auto rounded-lg bg-slate-50 p-3 font-mono text-[11px] leading-relaxed break-words whitespace-pre-wrap text-slate-600 dark:bg-darkmode-800 dark:text-slate-300"
                        >{{ promptText }}</pre
                    >
                </div>
            </div>
        </div>

        <!-- Confirmación: reemplazar con la plantilla base -->
        <Dialog :open="confirmTemplate" @close="confirmTemplate = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="flex items-start gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                        >
                            <Lucide icon="FileText" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-base font-medium">
                                Reemplazar con la plantilla base
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">
                                El texto actual del cuadro se pierde. No se
                                guarda nada hasta que presiones Guardar.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            @click="confirmTemplate = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="primary"
                            class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                            @click="applyTemplate"
                        >
                            <Lucide
                                icon="FileText"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Sí, reemplazar
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
