<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import Table from '@/components/Base/Table';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface IntegrationRow {
    id: number;
    label: string;
    token_prefix: string;
    domains: string[];
    active: boolean;
    last_used_at: string | null;
    created_at: string | null;
}

interface SuggestionRow {
    id: number;
    source_url: string;
    action: 'update' | 'create';
    room_type: string | null;
    payload: {
        name: string;
        description: string | null;
        capacity: number | null;
        amenities: string[];
        price: number | null;
    };
    // Si el destino YA tiene tarifa activa, el precio sugerido no se ofrece
    // (nunca se toca un precio que el hotel ya está vendiendo).
    target_has_rate: boolean;
    created_at: string | null;
}

interface RateFormState {
    price: string;
    rate_type: 'night' | 'block';
    duration_value: number | string;
    duration_unit: string;
}

const durationUnits = [
    { value: 'minute', label: 'Minutos' },
    { value: 'hour', label: 'Horas' },
    { value: 'day', label: 'Días' },
    { value: 'week', label: 'Semanas' },
    { value: 'month', label: 'Meses' },
];

interface WidgetRow {
    key: string;
    label: string;
    description: string;
    url: string;
    module_enabled: boolean;
    enabled: boolean;
    shortcode: string;
    embed: string;
}

const props = defineProps<{
    propertyId: number;
    widgets: WidgetRow[];
    widgetScriptUrl: string;
    integrations: IntegrationRow[];
    suggestions: SuggestionRow[];
    catalogUrl: string;
    wizardUrl: string;
    aiConfigured: boolean;
    canManage: boolean;
}>();

// ── Widgets incrustables: toggle por wizard + snippets para pegar ──
const widgetState = reactive<Record<string, boolean>>(
    Object.fromEntries(props.widgets.map((w) => [w.key, w.enabled])),
);
const widgetBusy = ref<string | null>(null);
const expandedWidget = ref<string | null>(null);

async function toggleWidget(widget: WidgetRow) {
    widgetBusy.value = widget.key;
    const next = !widgetState[widget.key];
    try {
        await axios.patch(`/api/properties/${props.propertyId}`, {
            settings: { [`widget_${widget.key}_enabled`]: next },
        });
        widgetState[widget.key] = next;
        toast.success(
            next ? 'Widget habilitado' : 'Widget deshabilitado',
            next
                ? `${widget.label} ya se puede visitar e incrustar.`
                : `${widget.label} dejó de mostrarse (página pública incluida).`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo cambiar',
            e.response?.data?.message ?? 'Intenta de nuevo.',
        );
    } finally {
        widgetBusy.value = null;
    }
}

const toast = useToasts();
const saving = ref(false);
const generalError = ref<string | null>(null);
const errors = reactive<Record<string, string>>({});

function clearErrors() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    generalError.value = null;
}

function handleError(error: any) {
    clearErrors();
    const data = error.response?.data;
    if (data?.errors) {
        Object.entries(data.errors).forEach(
            ([key, messages]) => (errors[key] = (messages as string[])[0]),
        );
        toast.error('Revisa el formulario', Object.values(errors)[0]);
    } else {
        generalError.value = data?.message ?? 'Ocurrió un error inesperado.';
        toast.error('Error', generalError.value ?? undefined);
    }
}

async function copyText(text: string, message: string) {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(message);
    } catch {
        toast.error('No se pudo copiar', 'Copia el texto manualmente.');
    }
}

// ── Tokens de sitios conectados ──
const showTokenForm = ref(false);
const tokenForm = reactive({ label: '', domains: '' });
// El token en claro solo existe en esta respuesta: se muestra UNA vez.
const freshToken = ref<string | null>(null);

function openTokenForm() {
    tokenForm.label = '';
    tokenForm.domains = '';
    clearErrors();
    showTokenForm.value = true;
}

async function submitToken() {
    saving.value = true;
    clearErrors();
    try {
        const { data } = await axios.post<{ token: string }>(
            '/api/site-integrations',
            {
                label: tokenForm.label,
                domains: tokenForm.domains
                    .split(',')
                    .map((d) => d.trim())
                    .filter(Boolean),
            },
        );
        showTokenForm.value = false;
        freshToken.value = data.token;
        testResult.value = null;
        router.reload({ only: ['integrations'] });
    } catch (error) {
        handleError(error);
    } finally {
        saving.value = false;
    }
}

// ── Probar conexión: ejercita el MISMO endpoint que consultará el sitio
// (misma URL relativa, mismo origen del tenant — sin CORS) ──
const testingToken = ref(false);
const testResult = ref<{ ok: boolean; message: string } | null>(null);

async function testToken(token: string) {
    if (!token) return;
    testingToken.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.get('/api/site/catalog', {
            headers: { Authorization: `Bearer ${token}` },
        });
        const roomTypes: { reservable: boolean }[] = data.room_types ?? [];
        const reservable = roomTypes.filter((r) => r.reservable).length;
        testResult.value = {
            ok: true,
            message: `Conexión correcta — ${data.property?.name ?? 'tu hotel'}: ${reservable} de ${roomTypes.length} tipo(s) con tarifa activa (lo que verá tu sitio).`,
        };
    } catch (error: any) {
        testResult.value = {
            ok: false,
            message:
                error.response?.data?.message ??
                'No se pudo conectar. Revisa el token y que el módulo esté activo.',
        };
    } finally {
        testingToken.value = false;
    }
}

// Probar un token ya guardado: por seguridad no lo volvemos a mostrar, así
// que se pide pegarlo de nuevo (el mismo que se guardó en el sitio).
const testingRow = ref<IntegrationRow | null>(null);
const testTokenInput = ref('');

function openTestRow(row: IntegrationRow) {
    testingRow.value = row;
    testTokenInput.value = '';
    testResult.value = null;
}

async function toggleIntegration(row: IntegrationRow) {
    try {
        await axios.patch(`/api/site-integrations/${row.id}`, {
            active: !row.active,
        });
        toast.success(
            row.active ? 'Token desactivado' : 'Token reactivado',
            row.label,
        );
        router.reload({ only: ['integrations'] });
    } catch (error) {
        handleError(error);
    }
}

const deleting = ref<IntegrationRow | null>(null);

async function submitDelete() {
    if (!deleting.value) return;
    try {
        await axios.delete(`/api/site-integrations/${deleting.value.id}`);
        toast.success(
            'Token eliminado',
            `El sitio "${deleting.value.label}" ya no puede consultar el catálogo.`,
        );
        deleting.value = null;
        router.reload({ only: ['integrations'] });
    } catch (error) {
        deleting.value = null;
        handleError(error);
    }
}

// ── Agente importador con validación ──
const importUrl = ref('');
const importing = ref(false);
const localSuggestions = ref<SuggestionRow[] | null>(null);
const suggestionList = () => localSuggestions.value ?? props.suggestions;
const busySuggestion = ref<number | null>(null);

async function runImport() {
    importing.value = true;
    clearErrors();
    try {
        const { data } = await axios.post<{
            created: number;
            suggestions: SuggestionRow[];
        }>('/api/site-import', {
            url: importUrl.value,
        });
        localSuggestions.value = data.suggestions;
        toast.success(
            data.created
                ? `${data.created} sugerencia(s) encontrada(s)`
                : 'Sin habitaciones detectadas',
            data.created
                ? 'Revisa y aplica solo lo que sea correcto.'
                : 'La página no parece listar habitaciones.',
        );
    } catch (error) {
        handleError(error);
    } finally {
        importing.value = false;
    }
}

// Formulario de tarifa por sugerencia: solo se ofrece cuando el destino
// AÚN no vende con una tarifa activa (tipo nuevo, o existente sin precio).
// El valor del precio parte de lo que propuso el scrape, pero SIEMPRE
// editable — nunca se manda a ciegas.
const rateForms = reactive<Record<number, RateFormState>>({});

function showRateEditor(row: SuggestionRow): boolean {
    return row.action === 'create' || !row.target_has_rate;
}

function rateForm(row: SuggestionRow): RateFormState {
    if (!rateForms[row.id]) {
        rateForms[row.id] = {
            price: row.payload.price !== null ? String(row.payload.price) : '',
            rate_type: 'night',
            duration_value: 3,
            duration_unit: 'hour',
        };
    }
    return rateForms[row.id];
}

async function applySuggestion(row: SuggestionRow) {
    busySuggestion.value = row.id;
    try {
        const rf = showRateEditor(row) ? rateForms[row.id] : undefined;
        const payload: Record<string, unknown> = {};

        if (rf && rf.price) {
            payload.price = rf.price;
            payload.rate_type = rf.rate_type;
            if (rf.rate_type === 'block') {
                payload.duration_unit = rf.duration_unit;
                payload.duration_value = rf.duration_value;
            }
        }

        const { data } = await axios.post<{
            suggestions: SuggestionRow[];
            room_number: string | null;
            room_skipped_reason: string | null;
        }>(`/api/site-import/${row.id}/apply`, payload);
        localSuggestions.value = data.suggestions;
        delete rateForms[row.id];

        const gotRate = Boolean(payload.price);
        const roomLine = data.room_number
            ? ` Se creó la habitación ${data.room_number} en Habitaciones (renómbrala si quieres otro número).`
            : data.room_skipped_reason
              ? ` ${data.room_skipped_reason}`
              : ''; // ya tenía habitación: no se duplicó, solo se completó el nombre si faltaba.

        toast.success(
            row.action === 'create'
                ? 'Tipo y habitación creados'
                : 'Ficha actualizada en Zonas y tipos',
            (row.action === 'create'
                ? gotRate
                    ? `"${row.payload.name}" quedó activo con tarifa de $${payload.price}.`
                    : `"${row.payload.name}" quedó sin tarifa: ponle precio en Zonas y tipos antes de venderlo.`
                : gotRate
                  ? `Ficha y tarifa inicial de "${row.payload.name}" creadas — revísalas en Zonas y tipos.`
                  : `Revisa "${row.payload.name}" en Zonas y tipos.`) +
                roomLine,
        );
    } catch (error) {
        handleError(error);
    } finally {
        busySuggestion.value = null;
    }
}

async function discardSuggestion(row: SuggestionRow) {
    busySuggestion.value = row.id;
    try {
        const { data } = await axios.post<{ suggestions: SuggestionRow[] }>(
            `/api/site-import/${row.id}/discard`,
        );
        localSuggestions.value = data.suggestions;
        delete rateForms[row.id];
        toast.success('Sugerencia descartada', row.payload.name);
    } catch (error) {
        handleError(error);
    } finally {
        busySuggestion.value = null;
    }
}
</script>

<template>
    <RazeLayout title="Integración">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-medium">
                        Integración con tu sitio web
                    </h1>
                    <p class="text-sm text-slate-500">
                        Tu página (WordPress u otra) muestra los precios EN VIVO
                        de este sistema: los cambias aquí una vez y se
                        actualizan solos allá.
                    </p>
                </div>
                <Button
                    v-if="canManage"
                    variant="primary"
                    class="rounded-[0.5rem] shadow-md shadow-primary/20"
                    @click="openTokenForm"
                >
                    <Lucide icon="Plus" class="mr-2 h-4 w-4" /> Conectar sitio
                </Button>
            </div>

            <div
                v-if="generalError"
                class="mt-4 rounded-md bg-danger/10 px-4 py-3 text-sm text-danger"
            >
                {{ generalError }}
            </div>

            <!-- Tu página de reservas: la URL del wizard público -->
            <div
                class="box box--stacked mt-5 flex flex-wrap items-center gap-4 p-5"
            >
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                >
                    <Lucide icon="CalendarCheck2" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="font-medium">Tu página de reservas</div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Pégala en el botón "Reservar" de tu WordPress, en la bio
                        de Instagram o en WhatsApp — el huésped elige fechas, ve
                        el precio real y aparta en línea.
                    </p>
                    <code
                        class="mt-2 inline-block rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs break-all dark:bg-darkmode-400"
                        >{{ wizardUrl }}</code
                    >
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button
                        variant="outline-secondary"
                        size="sm"
                        @click="copyText(wizardUrl, 'Link copiado')"
                    >
                        <Lucide icon="Copy" class="mr-1.5 h-3.5 w-3.5" /> Copiar
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.wizard-settings')"
                        variant="outline-secondary"
                        size="sm"
                    >
                        <Lucide icon="Settings" class="mr-1.5 h-3.5 w-3.5" />
                        Configurar
                    </Button>
                    <Button
                        as="a"
                        :href="wizardUrl"
                        target="_blank"
                        variant="outline-primary"
                        size="sm"
                    >
                        <Lucide icon="ArrowRight" class="mr-1.5 h-3.5 w-3.5" />
                        Ver
                    </Button>
                </div>
            </div>

            <!-- Widgets incrustables: pega el wizard en tu WordPress o en cualquier sitio -->
            <div class="box box--stacked mt-5">
                <div
                    class="border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                >
                    <div class="flex items-center gap-2">
                        <Lucide
                            icon="Code"
                            class="h-4 w-4 stroke-[1.5] text-primary"
                        />
                        <h2 class="text-base font-medium">
                            Widgets para tu sitio
                        </h2>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        Pega el wizard en tu página con el shortcode (WordPress
                        con el plugin KuiraWebReserve Habitaciones, descargable
                        en "Sitios conectados") o con el script (cualquier
                        sitio). Los precios, cupos y fotos SIEMPRE están en
                        vivo: lo que cambies aquí se refleja al instante en tu
                        página, sin re-publicar nada.
                    </p>
                </div>
                <div
                    class="divide-y divide-dashed divide-slate-200/80 p-5 dark:divide-darkmode-400"
                >
                    <div
                        v-for="widget in widgets"
                        :key="widget.key"
                        class="py-4 first:pt-0 last:pb-0"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <div
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                            >
                                <Lucide
                                    :icon="
                                        widget.key === 'experiencias'
                                            ? 'Compass'
                                            : widget.key === 'grupos'
                                              ? 'UsersRound'
                                              : 'BedDouble'
                                    "
                                    class="h-4 w-4 text-primary"
                                />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium">{{
                                        widget.label
                                    }}</span>
                                    <span
                                        v-if="!widget.module_enabled"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400"
                                        >Módulo no incluido en tu plan</span
                                    >
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ widget.description }}
                                </p>
                            </div>
                            <Button
                                v-if="
                                    widget.module_enabled &&
                                    widgetState[widget.key]
                                "
                                variant="outline-secondary"
                                size="sm"
                                class="rounded-[0.5rem] bg-white"
                                @click="
                                    expandedWidget =
                                        expandedWidget === widget.key
                                            ? null
                                            : widget.key
                                "
                            >
                                <Lucide
                                    icon="Code"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Cómo incrustarlo
                            </Button>
                            <FormSwitch
                                v-if="widget.module_enabled && canManage"
                                title="Apagarlo oculta también la página pública"
                            >
                                <FormSwitch.Input
                                    :checked="widgetState[widget.key]"
                                    type="checkbox"
                                    :disabled="widgetBusy === widget.key"
                                    @change="toggleWidget(widget)"
                                />
                            </FormSwitch>
                        </div>

                        <div
                            v-if="
                                expandedWidget === widget.key &&
                                widget.module_enabled &&
                                widgetState[widget.key]
                            "
                            class="mt-4 space-y-3 rounded-lg border border-dashed border-slate-300/70 p-4 dark:border-darkmode-400"
                        >
                            <div>
                                <div
                                    class="mb-1 flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                        >Shortcode (WordPress con el plugin
                                        KuiraWebReserve Habitaciones)</span
                                    >
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="
                                            copyText(
                                                widget.shortcode,
                                                'Shortcode copiado',
                                            )
                                        "
                                    >
                                        Copiar
                                    </button>
                                </div>
                                <code
                                    class="block rounded bg-slate-100 px-2 py-1.5 font-mono text-xs break-all dark:bg-darkmode-400"
                                    >{{ widget.shortcode }}</code
                                >
                            </div>
                            <div>
                                <div
                                    class="mb-1 flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                        >Script (cualquier sitio: WP sin plugin,
                                        HTML, Wix...)</span
                                    >
                                    <button
                                        type="button"
                                        class="text-xs font-medium text-primary hover:underline"
                                        @click="
                                            copyText(
                                                widget.embed,
                                                'Script copiado',
                                            )
                                        "
                                    >
                                        Copiar
                                    </button>
                                </div>
                                <code
                                    class="block rounded bg-slate-100 px-2 py-1.5 font-mono text-xs break-all whitespace-pre-wrap dark:bg-darkmode-400"
                                    >{{ widget.embed }}</code
                                >
                            </div>
                            <div
                                class="flex items-center justify-between gap-2 text-xs text-slate-500"
                            >
                                <span>También funciona como link directo:</span>
                                <a
                                    :href="widget.url"
                                    target="_blank"
                                    class="font-medium text-primary hover:underline"
                                    >{{ widget.url }}</a
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Tokens -->
                <div class="col-span-12 xl:col-span-7">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Plug"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Sitios conectados
                            </h2>
                        </div>
                        <div class="flex-1 p-5">
                            <Table v-if="integrations.length">
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>Sitio</Table.Th>
                                        <Table.Th>Token</Table.Th>
                                        <Table.Th>Último uso</Table.Th>
                                        <Table.Th>Estado</Table.Th>
                                        <Table.Th
                                            v-if="canManage"
                                            class="text-right"
                                        />
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    <Table.Tr
                                        v-for="row in integrations"
                                        :key="row.id"
                                    >
                                        <Table.Td class="font-medium">
                                            {{ row.label }}
                                            <div
                                                v-if="row.domains.length"
                                                class="text-xs font-normal text-slate-500"
                                            >
                                                {{ row.domains.join(', ') }}
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <span
                                                class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-500 dark:bg-darkmode-400"
                                            >
                                                {{ row.token_prefix }}…
                                            </span>
                                        </Table.Td>
                                        <Table.Td class="text-slate-500">{{
                                            row.last_used_at ?? 'Sin uso aún'
                                        }}</Table.Td>
                                        <Table.Td>
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs"
                                                :class="
                                                    row.active
                                                        ? 'bg-success/10 text-success'
                                                        : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                                                "
                                            >
                                                {{
                                                    row.active
                                                        ? 'Activo'
                                                        : 'Desactivado'
                                                }}
                                            </span>
                                        </Table.Td>
                                        <Table.Td v-if="canManage">
                                            <div class="flex justify-end gap-3">
                                                <a
                                                    href="#"
                                                    class="text-slate-500"
                                                    title="Probar conexión"
                                                    @click.prevent="
                                                        openTestRow(row)
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Wifi"
                                                        class="h-4 w-4"
                                                    />
                                                </a>
                                                <a
                                                    href="#"
                                                    class="text-slate-500"
                                                    :title="
                                                        row.active
                                                            ? 'Desactivar sin borrar'
                                                            : 'Reactivar'
                                                    "
                                                    @click.prevent="
                                                        toggleIntegration(row)
                                                    "
                                                >
                                                    <Lucide
                                                        :icon="
                                                            row.active
                                                                ? 'Pause'
                                                                : 'Play'
                                                        "
                                                        class="h-4 w-4"
                                                    />
                                                </a>
                                                <a
                                                    href="#"
                                                    class="text-danger"
                                                    title="Eliminar token"
                                                    @click.prevent="
                                                        deleting = row
                                                    "
                                                >
                                                    <Lucide
                                                        icon="Trash2"
                                                        class="h-4 w-4"
                                                    />
                                                </a>
                                            </div>
                                        </Table.Td>
                                    </Table.Tr>
                                </Table.Tbody>
                            </Table>
                            <div
                                v-else
                                class="flex flex-col items-center gap-3 py-8 text-center"
                            >
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="Plug" class="h-6 w-6" />
                                </div>
                                <div>
                                    <p class="text-sm font-medium">
                                        Aún no hay sitios conectados
                                    </p>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Genera un token por sitio (tu WordPress,
                                        tu página a medida) para que lea el
                                        catálogo en vivo.
                                    </p>
                                </div>
                                <Button
                                    v-if="canManage"
                                    variant="outline-primary"
                                    size="sm"
                                    class="rounded-[0.5rem]"
                                    @click="openTokenForm"
                                >
                                    <Lucide icon="Plus" class="mr-1 h-4 w-4" />
                                    Conectar el primero
                                </Button>
                            </div>
                        </div>
                        <div
                            class="border-t border-dashed border-slate-300/70 px-5 py-3.5 text-xs text-slate-500"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="font-medium text-slate-600 dark:text-slate-300"
                                    >Endpoint del catálogo:</span
                                >
                                <code
                                    class="rounded bg-slate-100 px-1.5 py-0.5 font-mono dark:bg-darkmode-400"
                                    >{{ catalogUrl }}</code
                                >
                                <button
                                    type="button"
                                    class="text-primary hover:underline"
                                    @click="
                                        copyText(catalogUrl, 'Endpoint copiado')
                                    "
                                >
                                    Copiar
                                </button>
                            </div>
                            <p class="mt-1.5">
                                El sitio manda el token en el header
                                Authorization (Bearer) DESDE SU SERVIDOR y
                                cachea unos minutos. El token nunca va en el
                                navegador del visitante. Devuelve tipos con
                                fotos, amenidades y precio "desde" en vivo; no
                                crea reservas ni expone huéspedes.
                            </p>
                            <div
                                class="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-slate-300/70 pt-3"
                            >
                                <span
                                    class="font-medium text-slate-600 dark:text-slate-300"
                                    >Plugin para WordPress:</span
                                >
                                <a
                                    href="/downloads/kuirawebreserve-rooms.zip"
                                    download
                                    class="flex items-center gap-1 font-medium text-primary hover:underline"
                                >
                                    <Lucide
                                        icon="Download"
                                        class="h-3.5 w-3.5"
                                    />
                                    Descargar kuirawebreserve-rooms.zip
                                </a>
                            </div>
                            <p class="mt-1.5">
                                Instálalo en Plugins → Añadir nuevo → Subir,
                                pega tu dominio y el token, y usa
                                <code
                                    class="rounded bg-slate-100 px-1.5 py-0.5 font-mono dark:bg-darkmode-400"
                                    >[kuirawebreserve_rooms]</code
                                >
                                para las tarjetas de habitaciones con foto y
                                precio vivo. Trae botón "Probar conexión" para
                                corroborar que todo quedó.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Agente importador -->
                <div class="col-span-12 xl:col-span-5">
                    <div class="box box--stacked flex h-full flex-col">
                        <div
                            class="flex items-center gap-2 border-b border-slate-200/60 px-5 py-4 dark:border-darkmode-400"
                        >
                            <Lucide
                                icon="Bot"
                                class="h-4 w-4 stroke-[1.5] text-primary"
                            />
                            <h2 class="text-base font-medium">
                                Importar desde tu sitio
                            </h2>
                        </div>
                        <div class="flex-1 space-y-4 p-5">
                            <p class="text-xs text-slate-500">
                                Pega la página donde muestras tus habitaciones:
                                el asistente la lee y propone nombres,
                                descripciones, capacidades, amenidades y, si lo
                                detecta, un precio. Nada se aplica sin tu
                                aprobación — el precio siempre lo confirmas o
                                corriges tú, y solo se usa si el tipo aún no
                                tiene una tarifa activa: un precio que ya vendes
                                jamás se toca.
                            </p>
                            <div
                                class="flex items-start gap-2 rounded-md bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700"
                            >
                                <Lucide
                                    icon="Info"
                                    class="mt-0.5 h-3.5 w-3.5 shrink-0 text-slate-400"
                                />
                                <span>
                                    Al aplicar, esto edita el
                                    <span
                                        class="font-medium text-slate-600 dark:text-slate-300"
                                        >tipo</span
                                    >
                                    en
                                    <Link
                                        :href="route('tenant.catalog')"
                                        class="font-medium text-primary hover:underline"
                                        >Zonas y tipos</Link
                                    >
                                    (nombre, descripción, amenidades) y, si el
                                    tipo aún no tiene ninguna, crea también su
                                    <span
                                        class="font-medium text-slate-600 dark:text-slate-300"
                                        >habitación</span
                                    >
                                    en
                                    <Link
                                        :href="route('tenant.rooms')"
                                        class="font-medium text-primary hover:underline"
                                        >Habitaciones</Link
                                    >
                                    con un número automático (lo cambias cuando
                                    quieras). Si el tipo ya tiene habitación,
                                    nunca se duplica.
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <FormInput
                                    v-model="importUrl"
                                    type="url"
                                    class="flex-1"
                                    placeholder="https://tusitio.com/habitaciones"
                                    :disabled="!aiConfigured || importing"
                                />
                                <Button
                                    variant="primary"
                                    :disabled="
                                        !aiConfigured || importing || !importUrl
                                    "
                                    @click="runImport"
                                >
                                    <Lucide
                                        :icon="
                                            importing ? 'RefreshCw' : 'Sparkles'
                                        "
                                        class="mr-2 h-4 w-4"
                                        :class="importing && 'animate-spin'"
                                    />
                                    {{ importing ? 'Analizando…' : 'Analizar' }}
                                </Button>
                            </div>
                            <FormHelp v-if="errors.url" class="text-danger">{{
                                errors.url
                            }}</FormHelp>
                            <div
                                v-if="!aiConfigured"
                                class="flex items-center gap-2 rounded-md border border-warning/30 bg-warning/5 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="h-4 w-4 shrink-0 text-warning"
                                />
                                El importador usa el Asistente IA y tu hotel no
                                tiene IA disponible (plan o configuración).
                            </div>

                            <!-- Cola de validación -->
                            <div
                                v-if="suggestionList().length"
                                class="space-y-3"
                            >
                                <div
                                    class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                >
                                    Sugerencias pendientes ({{
                                        suggestionList().length
                                    }})
                                </div>
                                <div
                                    v-for="s in suggestionList()"
                                    :key="s.id"
                                    class="rounded-lg border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span class="text-sm font-medium">{{
                                            s.payload.name
                                        }}</span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            :class="
                                                s.action === 'create'
                                                    ? 'bg-info/10 text-info'
                                                    : 'bg-pending/10 text-pending'
                                            "
                                        >
                                            {{
                                                s.action === 'create'
                                                    ? 'Nuevo tipo'
                                                    : `Actualiza: ${s.room_type}`
                                            }}
                                        </span>
                                    </div>
                                    <p
                                        v-if="s.payload.description"
                                        class="mt-1.5 text-xs text-slate-500"
                                    >
                                        {{ s.payload.description }}
                                    </p>
                                    <div
                                        class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500"
                                    >
                                        <span
                                            v-if="s.payload.capacity"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-darkmode-400"
                                        >
                                            {{ s.payload.capacity }} pers
                                        </span>
                                        <span
                                            v-for="amenity in s.payload
                                                .amenities"
                                            :key="amenity"
                                            class="rounded-full bg-slate-100 px-2 py-0.5 dark:bg-darkmode-400"
                                        >
                                            {{ amenity }}
                                        </span>
                                    </div>

                                    <!-- Precio/tarifa: opcional, editable, y solo si el destino no vende ya -->
                                    <div
                                        v-if="canManage && showRateEditor(s)"
                                        class="mt-3 rounded-md border border-dashed border-slate-300/70 p-2.5 dark:border-darkmode-400"
                                    >
                                        <div
                                            class="mb-2 flex items-center gap-1.5 text-xs font-medium text-slate-600 dark:text-slate-300"
                                        >
                                            <Lucide
                                                icon="Tag"
                                                class="h-3.5 w-3.5 text-success"
                                            />
                                            Tarifa inicial (opcional)
                                        </div>
                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <FormInput
                                                v-model="rateForm(s).price"
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                class="!w-28 !py-1.5 text-sm"
                                                :placeholder="
                                                    s.payload.price !== null
                                                        ? String(
                                                              s.payload.price,
                                                          )
                                                        : 'Sin precio'
                                                "
                                            />
                                            <FormSelect
                                                v-model="rateForm(s).rate_type"
                                                class="!w-auto !py-1.5 text-sm"
                                            >
                                                <option value="night">
                                                    Por noche
                                                </option>
                                                <option value="block">
                                                    Por periodo
                                                </option>
                                            </FormSelect>
                                            <template
                                                v-if="
                                                    rateForm(s).rate_type ===
                                                    'block'
                                                "
                                            >
                                                <FormInput
                                                    v-model.number="
                                                        rateForm(s)
                                                            .duration_value
                                                    "
                                                    type="number"
                                                    min="1"
                                                    class="!w-16 !py-1.5 text-sm"
                                                />
                                                <FormSelect
                                                    v-model="
                                                        rateForm(s)
                                                            .duration_unit
                                                    "
                                                    class="!w-auto !py-1.5 text-sm"
                                                >
                                                    <option
                                                        v-for="unit in durationUnits"
                                                        :key="unit.value"
                                                        :value="unit.value"
                                                    >
                                                        {{ unit.label }}
                                                    </option>
                                                </FormSelect>
                                            </template>
                                        </div>
                                        <p
                                            class="mt-1.5 text-[11px] text-slate-400"
                                        >
                                            {{
                                                s.payload.price !== null
                                                    ? 'Precio detectado en tu sitio — revísalo antes de confirmar.'
                                                    : 'Tu sitio no mostraba un precio claro; captúralo aquí o déjalo vacío y ponlo después en Zonas y tipos.'
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="canManage"
                                        class="mt-3 flex gap-2"
                                    >
                                        <Button
                                            variant="primary"
                                            size="sm"
                                            :disabled="busySuggestion === s.id"
                                            @click="applySuggestion(s)"
                                        >
                                            <Lucide
                                                icon="Check"
                                                class="mr-1 h-3.5 w-3.5"
                                            />
                                            Aplicar
                                        </Button>
                                        <Button
                                            variant="outline-secondary"
                                            size="sm"
                                            :disabled="busySuggestion === s.id"
                                            @click="discardSuggestion(s)"
                                        >
                                            Descartar
                                        </Button>
                                    </div>
                                </div>
                            </div>
                            <div
                                v-else
                                class="flex items-center gap-2 rounded-md bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-darkmode-700"
                            >
                                <Lucide
                                    icon="Info"
                                    class="h-4 w-4 shrink-0 text-slate-400"
                                />
                                Sin sugerencias pendientes. Analiza tu página de
                                habitaciones para llenar o refrescar tu
                                catálogo.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: nuevo token -->
        <Dialog :open="showTokenForm" @close="showTokenForm = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="submitToken">
                    <h2 class="mb-4 text-base font-medium">
                        Conectar un sitio
                    </h2>
                    <div class="space-y-4">
                        <div>
                            <FormLabel htmlFor="int-label"
                                >Nombre del sitio</FormLabel
                            >
                            <FormInput
                                id="int-label"
                                v-model="tokenForm.label"
                                type="text"
                                placeholder="WordPress del motel"
                            />
                            <FormHelp v-if="errors.label" class="text-danger">{{
                                errors.label
                            }}</FormHelp>
                        </div>
                        <div>
                            <FormLabel htmlFor="int-domains"
                                >Dominios (opcional, separados por
                                coma)</FormLabel
                            >
                            <FormInput
                                id="int-domains"
                                v-model="tokenForm.domains"
                                type="text"
                                placeholder="motellacupula.com, www.motellacupula.com"
                            />
                            <FormHelp
                                >Referencia de a quién pertenece el token; la
                                restricción estricta llega con la Booking
                                API.</FormHelp
                            >
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="showTokenForm = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="saving"
                            >Generar token</Button
                        >
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: token recién generado (única vez visible) -->
        <Dialog :open="freshToken !== null" @close="freshToken = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <div
                        class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="KeyRound" class="h-6 w-6" />
                    </div>
                    <h2 class="text-base font-medium">Token generado</h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Cópialo AHORA y guárdalo en el sitio (wp-config o
                        ajustes del plugin). Por seguridad no se puede volver a
                        ver: si se pierde, genera uno nuevo.
                    </p>
                    <div
                        class="mt-4 rounded-lg bg-slate-100 px-4 py-3 font-mono text-sm break-all dark:bg-darkmode-400"
                    >
                        {{ freshToken }}
                    </div>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <Button
                            variant="primary"
                            @click="copyText(freshToken ?? '', 'Token copiado')"
                        >
                            <Lucide icon="Copy" class="mr-2 h-4 w-4" /> Copiar
                            token
                        </Button>
                        <Button
                            variant="outline-secondary"
                            :disabled="testingToken"
                            @click="testToken(freshToken ?? '')"
                        >
                            <Lucide icon="Wifi" class="mr-2 h-4 w-4" />
                            {{ testingToken ? 'Probando…' : 'Probar conexión' }}
                        </Button>
                    </div>
                    <div
                        v-if="testResult"
                        class="mt-3 rounded-md px-3 py-2.5 text-left text-xs"
                        :class="
                            testResult.ok
                                ? 'bg-success/10 text-success'
                                : 'bg-danger/10 text-danger'
                        "
                    >
                        {{ testResult.message }}
                    </div>
                    <div class="mt-5">
                        <Button
                            variant="outline-secondary"
                            @click="
                                freshToken = null;
                                testResult = null;
                            "
                            >Listo, lo guardé</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: probar un token ya guardado (se pide pegarlo: no lo mostramos otra vez) -->
        <Dialog
            :open="testingRow !== null"
            @close="
                testingRow = null;
                testResult = null;
            "
        >
            <Dialog.Panel>
                <div class="p-5">
                    <h2 class="mb-1 text-base font-medium">
                        Probar "{{ testingRow?.label }}"
                    </h2>
                    <p class="mb-4 text-xs text-slate-500">
                        Pega el mismo token que guardaste en el sitio para
                        verificar que la conexión funciona.
                    </p>
                    <FormLabel htmlFor="test-token">Token</FormLabel>
                    <FormInput
                        id="test-token"
                        v-model="testTokenInput"
                        type="text"
                        placeholder="ksk_…"
                        class="font-mono"
                    />
                    <div
                        v-if="testResult"
                        class="mt-3 rounded-md px-3 py-2.5 text-xs"
                        :class="
                            testResult.ok
                                ? 'bg-success/10 text-success'
                                : 'bg-danger/10 text-danger'
                        "
                    >
                        {{ testResult.message }}
                    </div>
                    <div class="mt-5 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="
                                testingRow = null;
                                testResult = null;
                            "
                            >Cerrar</Button
                        >
                        <Button
                            variant="primary"
                            :disabled="testingToken || !testTokenInput"
                            @click="testToken(testTokenInput)"
                        >
                            <Lucide icon="Wifi" class="mr-2 h-4 w-4" />
                            {{ testingToken ? 'Probando…' : 'Probar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal: eliminar token -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div class="p-5 text-center">
                    <Lucide
                        icon="TriangleAlert"
                        class="mx-auto mb-3 h-12 w-12 text-danger"
                    />
                    <h2 class="text-base font-medium">
                        ¿Eliminar el token de "{{ deleting?.label }}"?
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Ese sitio dejará de leer el catálogo de inmediato. Si es
                        temporal, mejor desactívalo.
                    </p>
                    <div class="mt-5 flex justify-center gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button variant="danger" @click="submitDelete"
                            >Sí, eliminar</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
