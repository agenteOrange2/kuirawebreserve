<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ConversationRow {
    id: number;
    uuid: string;
    channel: string;
    channel_mode: string;
    name: string;
    guest_id: number | null;
    status: string;
    lead_status: string;
    summary: string | null;
    bot_enabled: boolean;
    assigned_to: number | null;
    assignee: string | null;
    unread: number;
    last_message_at: string | null;
    preview: string | null;
}
interface ThreadMessage { id: number; direction: string; sender_type: string; sender: string | null; body: string; at: string }
interface ChannelRow { id: number; type: string; name: string; mode: string }

const props = defineProps<{
    property: { id: number; name: string };
    conversations: ConversationRow[];
    channels: ChannelRow[];
    staff: { id: number; name: string }[];
    canManage: boolean;
    llmReady: boolean;
}>();

const toast = useToasts();
const initials = (name: string) =>
    name.trim().split(/\s+/).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('') || '?';

const channelMeta: Record<string, { label: string; icon: Icon; tone: string }> = {
    webchat: { label: 'Webchat', icon: 'Globe', tone: 'bg-info/10 text-info' },
    whatsapp: { label: 'WhatsApp', icon: 'MessageCircle', tone: 'bg-success/10 text-success' },
    messenger: { label: 'Messenger', icon: 'Facebook', tone: 'bg-primary/10 text-primary' },
    instagram: { label: 'Instagram', icon: 'Instagram', tone: 'bg-pending/10 text-pending' },
};
const statusMeta: Record<string, { label: string; tone: string }> = {
    open: { label: 'Abierta', tone: 'bg-success/10 text-success' },
    pending: { label: 'Espera humano', tone: 'bg-warning/10 text-warning' },
    resolved: { label: 'Resuelta', tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400' },
};

// Embudo de venta de la conversación (lead_status).
const leadMeta: Record<string, { label: string; tone: string }> = {
    quoting: { label: 'Cotizando', tone: 'bg-info/10 text-info' },
    hold: { label: 'Apartado', tone: 'bg-pending/10 text-pending' },
    won: { label: 'Ganado', tone: 'bg-success/10 text-success' },
    lost: { label: 'Perdido', tone: 'bg-danger/10 text-danger' },
};

const filter = ref<'all' | 'pending' | 'open' | 'resolved'>('all');
const leadFilter = ref('all');
const filtered = computed(() =>
    props.conversations
        .filter((c) => filter.value === 'all' || c.status === filter.value)
        .filter((c) => leadFilter.value === 'all' || c.lead_status === leadFilter.value),
);
const pendingCount = computed(() => props.conversations.filter((c) => c.status === 'pending').length);

// ── Hilo seleccionado ──
const selected = ref<ConversationRow | null>(null);
const thread = ref<ThreadMessage[]>([]);
const threadLoading = ref(false);
const reply = ref('');
const sending = ref(false);
const threadRef = ref<HTMLElement | null>(null);
let poller: ReturnType<typeof setInterval> | null = null;

async function scrollThread() {
    await nextTick();
    threadRef.value?.scrollTo({ top: threadRef.value.scrollHeight });
}

async function open(c: ConversationRow) {
    selected.value = c;
    showSummary.value = false;
    suggestion.value = null;
    usedCopilot.value = false;
    threadLoading.value = true;
    thread.value = [];
    await refreshThread();
    threadLoading.value = false;
    c.unread = 0;
    maybeAutoSuggest();
}

async function refreshThread() {
    if (!selected.value) return;
    try {
        const { data } = await axios.get(`/api/inbox/${selected.value.id}`);
        const grew = data.messages.length !== thread.value.length;
        thread.value = data.messages;
        Object.assign(selected.value, data.conversation);
        if (grew) scrollThread();
    } catch {
        /* la conversación pudo borrarse */
    }
}

async function sendReply() {
    const body = reply.value.trim();
    if (!body || !selected.value || sending.value) return;
    sending.value = true;
    try {
        await axios.post(`/api/inbox/${selected.value.id}/reply`, { body, copilot: usedCopilot.value });
        reply.value = '';
        usedCopilot.value = false;
        await refreshThread();
    } catch (e: any) {
        toast.error('No se pudo enviar', e.response?.data?.message ?? 'Ocurrió un error.');
    } finally {
        sending.value = false;
    }
}

async function patchConversation(payload: Record<string, unknown>, message: string) {
    if (!selected.value) return;
    try {
        await axios.patch(`/api/inbox/${selected.value.id}`, payload);
        toast.success(message);
        await refreshThread();
        router.reload({ only: ['conversations'] });
    } catch (e: any) {
        toast.error('No se pudo actualizar', e.response?.data?.message ?? 'Ocurrió un error.');
    }
}

async function setChannelMode(channel: ChannelRow, mode: string) {
    try {
        await axios.patch(`/api/channels/${channel.id}`, { mode });
        channel.mode = mode;
        toast.success('Modo actualizado', `${channel.name}: ${modeMeta[mode]?.label ?? mode}`);
    } catch (e: any) {
        toast.error('No se pudo cambiar', e.response?.data?.message ?? 'Ocurrió un error.');
    }
}

const modeMeta: Record<string, { label: string; icon: Icon }> = {
    auto: { label: 'Automático', icon: 'Zap' },
    copilot: { label: 'Copiloto', icon: 'UserCheck' },
    off: { label: 'Apagado', icon: 'PowerOff' },
};

// Resumen IA del hilo (memoria del bot), plegado por defecto.
const showSummary = ref(false);

// ── Copiloto: el bot redacta, el staff aprueba ──
const suggestion = ref<{ text: string; meta: { provider: string; model: string; ms: number } } | null>(null);
const suggestLoading = ref(false);
const usedCopilot = ref(false);

async function fetchSuggestion() {
    if (!selected.value || suggestLoading.value) return;
    suggestLoading.value = true;
    suggestion.value = null;
    try {
        const { data } = await axios.post(`/api/inbox/${selected.value.id}/suggest`);
        suggestion.value = data;
    } catch (e: any) {
        toast.error('Sin sugerencia', e.response?.data?.message ?? 'No se pudo generar el borrador.');
    } finally {
        suggestLoading.value = false;
    }
}

function useSuggestion() {
    if (!suggestion.value) return;
    reply.value = suggestion.value.text;
    usedCopilot.value = true;
    suggestion.value = null;
}

// En canales en modo copiloto, al abrir una conversación que espera
// respuesta (último mensaje del huésped) el borrador se pide solo.
function maybeAutoSuggest() {
    if (!props.canManage || !props.llmReady || !selected.value) return;
    if (selected.value.channel_mode !== 'copilot' || selected.value.status === 'resolved') return;
    const last = thread.value[thread.value.length - 1];
    if (last && last.direction === 'in') fetchSuggestion();
}

onMounted(() => {
    poller = setInterval(async () => {
        router.reload({ only: ['conversations'] });
        await refreshThread();
    }, 10000);
});
onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});
</script>

<template>
    <RazeLayout title="Bandeja">
        <div class="mt-2">
            <!-- Encabezado -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-lg font-medium">Bandeja de conversaciones</h1>
                        <span v-if="pendingCount" class="rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">{{ pendingCount }} esperan humano</span>
                    </div>
                    <p class="text-sm text-slate-500">{{ property.name }} · todos los canales en un solo lugar</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Modo por canal -->
                    <div v-for="ch in channels" :key="ch.id" class="flex items-center gap-2 rounded-[0.5rem] border border-slate-200/70 bg-white px-3 py-1.5 dark:border-darkmode-400 dark:bg-darkmode-600">
                        <div class="flex h-6 w-6 items-center justify-center rounded-full" :class="channelMeta[ch.type]?.tone">
                            <Lucide :icon="channelMeta[ch.type]?.icon ?? 'MessageCircle'" class="h-3.5 w-3.5" />
                        </div>
                        <span class="text-sm font-medium">{{ ch.name }}</span>
                        <FormSelect v-if="canManage" :model-value="ch.mode" class="!w-auto !py-1 text-xs" @update:model-value="(v: string) => setChannelMode(ch, v)">
                            <option value="auto">Automático</option>
                            <option value="copilot">Copiloto</option>
                            <option value="off">Apagado</option>
                        </FormSelect>
                    </div>
                    <Button as="a" :href="route('tenant.webchat')" target="_blank" variant="outline-secondary" class="rounded-[0.5rem] bg-white">
                        <Lucide icon="ExternalLink" class="mr-2 h-4 w-4 stroke-[1.3]" /> Ver webchat
                    </Button>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Lista de conversaciones -->
                <div class="col-span-12 xl:col-span-4">
                    <div class="box box--stacked flex h-[calc(100vh-16rem)] min-h-[480px] flex-col">
                        <!-- Filtros -->
                        <div class="border-b border-slate-200/60 p-3 dark:border-darkmode-400">
                            <div class="inline-flex w-full gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700">
                                <button
                                    v-for="f in [
                                        { key: 'all', label: 'Todas' },
                                        { key: 'pending', label: 'Esperan' },
                                        { key: 'open', label: 'Abiertas' },
                                        { key: 'resolved', label: 'Resueltas' },
                                    ]"
                                    :key="f.key"
                                    class="flex-1 rounded-[0.5rem] px-2 py-1.5 text-xs font-medium transition"
                                    :class="filter === f.key ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600' : 'text-slate-500 hover:text-slate-700'"
                                    @click="filter = f.key as typeof filter"
                                >
                                    {{ f.label }}
                                </button>
                            </div>
                            <FormSelect v-model="leadFilter" class="mt-2 !py-1.5 text-xs">
                                <option value="all">Embudo: todos</option>
                                <option value="quoting">Cotizando</option>
                                <option value="hold">Con apartado</option>
                                <option value="won">Ganados</option>
                                <option value="lost">Perdidos</option>
                            </FormSelect>
                        </div>
                        <!-- Conversaciones -->
                        <div class="flex-1 divide-y divide-slate-100 overflow-y-auto dark:divide-darkmode-400/60">
                            <button
                                v-for="c in filtered"
                                :key="c.id"
                                type="button"
                                class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-darkmode-400/40"
                                :class="{ 'bg-primary/5': selected?.id === c.id }"
                                @click="open(c)"
                            >
                                <div class="relative shrink-0">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white">{{ initials(c.name) }}</div>
                                    <div class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border-2 border-white dark:border-darkmode-600" :class="channelMeta[c.channel]?.tone ?? 'bg-slate-100'">
                                        <Lucide :icon="channelMeta[c.channel]?.icon ?? 'MessageCircle'" class="h-2.5 w-2.5" />
                                    </div>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate text-sm font-medium">{{ c.name }}</span>
                                        <span class="ml-auto shrink-0 text-[10px] text-slate-400">{{ c.last_message_at }}</span>
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-1.5">
                                        <span class="truncate text-xs text-slate-500">{{ c.preview ?? '—' }}</span>
                                        <span v-if="c.unread" class="ml-auto flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold text-white">{{ c.unread }}</span>
                                    </div>
                                    <div class="mt-1.5 flex items-center gap-1.5">
                                        <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium" :class="statusMeta[c.status]?.tone">{{ statusMeta[c.status]?.label }}</span>
                                        <span v-if="leadMeta[c.lead_status]" class="rounded-full px-1.5 py-0.5 text-[10px] font-medium" :class="leadMeta[c.lead_status].tone">{{ leadMeta[c.lead_status].label }}</span>
                                        <span v-if="!c.bot_enabled" class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-500 dark:bg-darkmode-400">{{ c.assignee ?? 'Humano' }}</span>
                                        <span v-else class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">Bot</span>
                                    </div>
                                </div>
                            </button>
                            <div v-if="!filtered.length" class="flex flex-col items-center gap-3 py-16 text-center">
                                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"><Lucide icon="MessagesSquare" class="h-6 w-6" /></div>
                                <p class="px-6 text-sm text-slate-500">Sin conversaciones{{ filter !== 'all' ? ' en este filtro' : ' todavía. Comparte el webchat de tu hotel para empezar' }}.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hilo -->
                <div class="col-span-12 xl:col-span-8">
                    <div class="box box--stacked flex h-[calc(100vh-16rem)] min-h-[480px] flex-col">
                        <template v-if="selected">
                            <!-- Header del hilo -->
                            <div class="flex flex-wrap items-center gap-3 border-b border-slate-200/60 px-5 py-3.5 dark:border-darkmode-400">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white">{{ initials(selected.name) }}</div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium">{{ selected.name }}</span>
                                        <Link v-if="selected.guest_id" :href="route('tenant.guests.show', selected.guest_id)" class="text-xs text-primary hover:underline">Ver perfil</Link>
                                    </div>
                                    <div class="flex items-center gap-1.5 text-xs text-slate-500">
                                        <Lucide :icon="channelMeta[selected.channel]?.icon ?? 'MessageCircle'" class="h-3 w-3" />
                                        {{ channelMeta[selected.channel]?.label ?? selected.channel }}
                                        <span class="rounded-full px-1.5 py-0.5 text-[10px] font-medium" :class="statusMeta[selected.status]?.tone">{{ statusMeta[selected.status]?.label }}</span>
                                        <span v-if="leadMeta[selected.lead_status]" class="rounded-full px-1.5 py-0.5 text-[10px] font-medium" :class="leadMeta[selected.lead_status].tone">{{ leadMeta[selected.lead_status].label }}</span>
                                    </div>
                                </div>
                                <div v-if="canManage" class="flex flex-wrap items-center gap-1.5">
                                    <Button
                                        v-if="selected.summary"
                                        variant="outline-secondary"
                                        size="sm"
                                        class="rounded-[0.5rem] bg-white"
                                        :class="{ '!border-primary/40 !text-primary': showSummary }"
                                        @click="showSummary = !showSummary"
                                    >
                                        <Lucide icon="Sparkles" class="mr-1.5 h-3.5 w-3.5" /> Resumen IA
                                    </Button>
                                    <Button
                                        v-if="!selected.bot_enabled"
                                        variant="outline-primary"
                                        size="sm"
                                        class="rounded-[0.5rem] bg-white"
                                        @click="patchConversation({ bot_enabled: true, status: 'open' }, 'El bot retomó la conversación.')"
                                    >
                                        <Lucide icon="Bot" class="mr-1.5 h-3.5 w-3.5" /> Devolver al bot
                                    </Button>
                                    <Button
                                        v-if="selected.status !== 'resolved'"
                                        variant="outline-secondary"
                                        size="sm"
                                        class="rounded-[0.5rem] bg-white"
                                        @click="patchConversation({ status: 'resolved' }, 'Conversación resuelta.')"
                                    >
                                        <Lucide icon="CircleCheck" class="mr-1.5 h-3.5 w-3.5" /> Resolver
                                    </Button>
                                    <FormSelect
                                        :model-value="selected.assigned_to ?? ''"
                                        class="!w-auto !py-1 text-xs"
                                        @update:model-value="(v: string) => patchConversation({ assigned_to: v || null }, 'Asignación actualizada.')"
                                    >
                                        <option value="">Sin asignar</option>
                                        <option v-for="s in staff" :key="s.id" :value="s.id">{{ s.name }}</option>
                                    </FormSelect>
                                </div>
                            </div>

                            <!-- Resumen IA (memoria del bot) -->
                            <div v-if="showSummary && selected.summary" class="border-b border-dashed border-slate-300/70 bg-primary/[0.03] px-5 py-3.5">
                                <div class="flex items-start gap-2.5">
                                    <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10">
                                        <Lucide icon="Sparkles" class="h-3.5 w-3.5 text-primary" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-xs font-medium uppercase tracking-wide text-slate-400">Resumen de la conversación</div>
                                        <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ selected.summary }}</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Mensajes -->
                            <div ref="threadRef" class="flex-1 space-y-3 overflow-y-auto bg-slate-50/60 px-5 py-4 dark:bg-darkmode-700/40">
                                <div v-if="threadLoading" class="flex items-center justify-center gap-2 py-10 text-sm text-slate-400">
                                    <Lucide icon="RefreshCw" class="h-4 w-4 animate-spin" /> Cargando conversación…
                                </div>
                                <template v-for="m in thread" :key="m.id">
                                    <div class="flex" :class="m.direction === 'in' ? 'justify-start' : 'justify-end'">
                                        <div class="max-w-[75%]">
                                            <div
                                                class="whitespace-pre-line rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed"
                                                :class="m.direction === 'in'
                                                    ? 'rounded-bl-md border border-slate-200 bg-white text-slate-700 dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-200'
                                                    : m.sender_type === 'bot'
                                                      ? 'rounded-br-md bg-primary/10 text-slate-700 dark:text-slate-200'
                                                      : m.sender_type === 'system'
                                                        ? 'rounded-br-md bg-warning/10 text-slate-600 dark:text-slate-300'
                                                        : 'rounded-br-md bg-linear-to-r from-theme-1 to-theme-2 text-white'"
                                            >
                                                {{ m.body }}
                                            </div>
                                            <div class="mt-1 flex items-center gap-1 text-[10px] text-slate-400" :class="m.direction === 'in' ? '' : 'justify-end'">
                                                <Lucide v-if="m.sender_type === 'bot'" icon="Bot" class="h-3 w-3" />
                                                <Lucide v-else-if="m.sender_type === 'staff'" icon="User" class="h-3 w-3" />
                                                {{ m.sender_type === 'bot' ? 'Asistente IA' : (m.sender ?? (m.sender_type === 'system' ? 'Sistema' : '')) }} · {{ m.at }}
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Composer -->
                            <div v-if="canManage" class="border-t border-slate-200/60 p-3 dark:border-darkmode-400">
                                <div v-if="selected.bot_enabled" class="mb-2 flex items-center gap-2 rounded-lg bg-primary/5 px-3 py-2 text-xs text-slate-500">
                                    <Lucide icon="Bot" class="h-3.5 w-3.5 text-primary" /> El bot atiende esta conversación. Si respondes tú, la tomas (el bot se pausa).
                                </div>

                                <!-- Copiloto: borrador con aprobación humana -->
                                <div v-if="suggestLoading" class="mb-2 flex items-center gap-2 rounded-lg border border-dashed border-primary/30 bg-primary/[0.03] px-3 py-2.5 text-xs text-slate-500">
                                    <Lucide icon="Sparkles" class="h-3.5 w-3.5 animate-pulse text-primary" /> El copiloto está redactando una sugerencia…
                                </div>
                                <div v-else-if="suggestion" class="mb-2 rounded-lg border border-primary/20 bg-primary/[0.04] p-3">
                                    <div class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">
                                        <Lucide icon="Sparkles" class="h-3.5 w-3.5 text-primary" /> Sugerencia del copiloto
                                        <span class="ml-auto rounded-full bg-primary/10 px-2 py-0.5 text-[10px] normal-case tracking-normal text-primary">{{ suggestion.meta.provider }} · {{ (suggestion.meta.ms / 1000).toFixed(1) }}s</span>
                                    </div>
                                    <p class="mt-1.5 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ suggestion.text }}</p>
                                    <div class="mt-2.5 flex items-center gap-2">
                                        <Button variant="primary" size="sm" class="rounded-[0.5rem]" @click="useSuggestion">
                                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" /> Usar y editar
                                        </Button>
                                        <Button variant="outline-secondary" size="sm" class="rounded-[0.5rem] bg-white" :disabled="suggestLoading" @click="fetchSuggestion">
                                            <Lucide icon="RefreshCw" class="mr-1.5 h-3.5 w-3.5" /> Otra
                                        </Button>
                                        <button type="button" class="ml-auto text-xs text-slate-400 hover:text-danger" @click="suggestion = null">Descartar</button>
                                    </div>
                                </div>

                                <div class="flex items-end gap-2">
                                    <Button
                                        v-if="llmReady && !suggestion"
                                        variant="outline-secondary"
                                        class="rounded-[0.5rem] bg-white"
                                        :disabled="suggestLoading"
                                        title="Pídele al copiloto un borrador de respuesta"
                                        @click="fetchSuggestion"
                                    >
                                        <Lucide icon="Sparkles" class="h-4 w-4 text-primary" />
                                    </Button>
                                    <textarea
                                        v-model="reply"
                                        rows="2"
                                        placeholder="Responder como staff…"
                                        class="flex-1 resize-none rounded-lg border border-slate-200 px-3.5 py-2.5 text-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-darkmode-400 dark:bg-darkmode-600"
                                        @keydown.enter.exact.prevent="sendReply"
                                    />
                                    <Button variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="sending || !reply.trim()" @click="sendReply">
                                        <Lucide icon="SendHorizontal" class="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </template>

                        <!-- Sin selección -->
                        <div v-else class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"><Lucide icon="MessagesSquare" class="h-7 w-7" /></div>
                            <p class="text-sm text-slate-500">Elige una conversación para ver el hilo.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
