<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect, FormTextarea } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide/Lucide.vue';
import { useInboxAlerts } from '@/composables/useInboxAlerts';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface ConversationRow {
    id: number;
    uuid: string;
    channel: string;
    channel_mode: string;
    name: string;
    /** Número presentable del contacto (null si el canal no lo da). */
    phone: { label: string; digits: string } | null;
    guest_id: number | null;
    status: string;
    archived: boolean;
    // Minutos esperando a una persona del hotel (solo las pendientes).
    waiting_minutes?: number | null;
    waiting_label?: string | null;
    lead_status: string;
    summary: string | null;
    bot_enabled: boolean;
    assigned_to: number | null;
    assignee: string | null;
    unread: number;
    from_social: boolean;
    last_message_at: string | null;
    preview: string | null;
    reservation_code: string | null;
    payment_status: string | null;
    payment_status_label: string | null;
    payment_pending_verification: boolean;
}
interface ThreadMessage {
    id: number;
    direction: string;
    sender_type: string;
    sender: string | null;
    body: string;
    voice_note: boolean;
    /** El canal rechazó el mensaje: el huésped NO lo recibió. */
    undelivered: boolean;
    attachments: {
        id: number;
        url: string;
        name: string;
        is_image: boolean;
        /** Lo que el sistema leyó en la imagen (comprobante o no). */
        reading: AttachmentReading | null;
    }[];
    at: string;
}
interface AttachmentReading {
    verdict: string | null;
    summary: string | null;
    warnings: string[];
    description: string | null;
}
/** La reserva de la conversación abierta, con su dinero y su comprobante. */
interface ReservationCard {
    code: string;
    is_group: boolean;
    url: string;
    status: string;
    status_label: string;
    rooms_count: number;
    rooms_label: string;
    guests: number;
    starts_label: string;
    ends_label: string;
    total_label: string;
    paid_label: string;
    pending_label: string;
    payment_status: string | null;
    payment_status_label: string | null;
    hold_expires_label: string | null;
    request: {
        id: number;
        concept: string;
        amount_label: string;
        has_receipt: boolean;
        verdict: string | null;
        summary: string | null;
        warnings: string[];
    } | null;
}
interface ChannelRow {
    id: number;
    type: string;
    name: string;
    mode: string;
}
const props = defineProps<{
    tenantId: string;
    property: { id: number; name: string };
    conversations: ConversationRow[];
    filters: {
        archived: boolean;
        esperando: boolean;
        resueltas: boolean;
        q: string;
        ver: number;
    };
    counts: {
        active: number;
        resolved: number;
        archived: number;
        waiting: number;
    };
    /** Cuántas se ven de cuántas hay, y si falta por cargar. */
    pagination: {
        loaded: number;
        total: number;
        has_more: boolean;
        step: number;
    };
    channels: ChannelRow[];
    staff: { id: number; name: string }[];
    canManage: boolean;
    canTeach: boolean;
    llmReady: boolean;
}>();

const toast = useToasts();
// Aviso de mensaje nuevo: tono, notificación del navegador y contador en
// el título de la pestaña.
const alerts = useInboxAlerts();
const initials = (name: string) =>
    name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('') || '?';

const channelMeta: Record<string, { label: string; icon: Icon; tone: string }> =
    {
        webchat: {
            label: 'Webchat',
            icon: 'Globe',
            tone: 'border-info/10 bg-info/10 text-info',
        },
        whatsapp: {
            label: 'WhatsApp',
            icon: 'MessageCircle',
            tone: 'border-success/10 bg-success/10 text-success',
        },
        whatsapp_evo: {
            label: 'WhatsApp (Evolution)',
            icon: 'MessageCircle',
            tone: 'border-success/10 bg-success/10 text-success',
        },
        messenger: {
            label: 'Messenger',
            icon: 'Facebook',
            tone: 'border-primary/10 bg-primary/10 text-primary',
        },
        instagram: {
            label: 'Instagram',
            icon: 'Instagram',
            tone: 'border-pending/10 bg-pending/10 text-pending',
        },
        telegram: {
            label: 'Telegram',
            icon: 'Send',
            tone: 'border-info/10 bg-info/10 text-info',
        },
        tiktok: {
            label: 'TikTok',
            icon: 'Music2',
            tone: 'border-dark/10 bg-dark/10 text-dark dark:text-slate-300',
        },
    };
const statusMeta: Record<string, { label: string; tone: string }> = {
    open: { label: 'Abierta', tone: 'bg-success/10 text-success' },
    pending: { label: 'Espera humano', tone: 'bg-warning/10 text-warning' },
    resolved: {
        label: 'Resuelta',
        tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
    },
};

// Embudo de venta de la conversación (lead_status).
const leadMeta: Record<string, { label: string; tone: string }> = {
    quoting: { label: 'Cotizando', tone: 'bg-info/10 text-info' },
    hold: { label: 'Apartado', tone: 'bg-pending/10 text-pending' },
    won: { label: 'Ganado', tone: 'bg-success/10 text-success' },
    lost: { label: 'Perdido', tone: 'bg-danger/10 text-danger' },
};

// Chip de pago de la reserva ligada (spec-pagos §9.3).
const paymentMeta: Record<string, { tone: string }> = {
    unpaid: { tone: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400' },
    partial: { tone: 'bg-pending/10 text-pending' },
    deposit_paid: { tone: 'bg-info/10 text-info' },
    paid: { tone: 'bg-success/10 text-success' },
};

// ── Pestañas de la lista: activas (abiertas + esperan), resueltas y
// archivo. El archivo se carga del servidor con ?archived=1. ──
type InboxTab = 'active' | 'resolved' | 'archived';
const tab = ref<InboxTab>(
    props.filters.archived
        ? 'archived'
        : props.filters.resueltas
          ? 'resolved'
          : 'active',
);
const leadFilter = ref('all');
const search = ref(props.filters.q ?? '');

/** Hay búsqueda vigente (la que ya aplicó el servidor). */
const searching = computed(() => (props.filters.q ?? '') !== '');

const loadingMore = ref(false);

/**
 * Todo lo que el servidor decide (pestaña, esperando, búsqueda y tamaño de
 * la ventana) viaja junto en la URL: así el repaso de cada minuto y los
 * avisos de Reverb no tiran los filtros ni encogen lo ya cargado.
 */
type ListParam = string | number | undefined;

function goList(
    overrides: Record<string, ListParam> = {},
    onFinish?: () => void,
): void {
    const params: Record<string, ListParam> = {
        ...(tab.value === 'archived' ? { archived: 1 } : {}),
        ...(tab.value === 'resolved' ? { estado: 'resolved' } : {}),
        ...(props.filters.esperando ? { esperando: 1 } : {}),
        ...(search.value.trim().length >= 2 ? { q: search.value.trim() } : {}),
        ...(props.filters.ver > 100 ? { ver: props.filters.ver } : {}),
        ...overrides,
    };
    for (const key of Object.keys(params)) {
        if (params[key] === undefined) delete params[key];
    }

    router.get(
        route('tenant.inbox'),
        params as Record<string, string | number>,
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['conversations', 'filters', 'counts', 'pagination'],
            onFinish,
        },
    );
}

function setTab(t: InboxTab): void {
    if (tab.value === t) return;
    tab.value = t;
    // Cambiar de pestaña empieza de nuevo: la ventana vuelve a 100.
    goList({ ver: undefined });
}

// Traspasos sin dueño: el bot pasa el hilo a una persona y se apaga. Este
// filtro va al servidor (?esperando=1) porque el que lleva 22 horas
// esperando ya no cabe en las 100 conversaciones más recientes.
function toggleEsperando(): void {
    goList({
        esperando: props.filters.esperando ? undefined : 1,
        ver: undefined,
    });
}

// La búsqueda se manda al servidor (con respiro entre teclas) y mira toda
// la bandeja, no solo lo que está en pantalla.
let searchDebounce: ReturnType<typeof setTimeout> | null = null;

function onSearchInput(): void {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        const term = search.value.trim();
        // Menos de dos letras no es una búsqueda; el servidor la ignora.
        const wanted = term.length >= 2 ? term : '';
        if (wanted === (props.filters.q ?? '')) return;
        goList({ q: wanted || undefined, ver: undefined });
    }, 350);
}

function loadMore(): void {
    if (loadingMore.value) return;
    loadingMore.value = true;
    goList({ ver: props.filters.ver + props.pagination.step }, () => {
        loadingMore.value = false;
    });
}

function esperaTono(minutes?: number | null): string {
    if (!minutes) return 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';
    if (minutes >= 60) return 'bg-danger/10 text-danger';
    if (minutes >= 15) return 'bg-warning/10 text-warning';
    return 'bg-slate-100 text-slate-500 dark:bg-darkmode-400';
}

// La pestaña ya la resolvió el servidor (activas, resueltas o archivo, y
// buscando se enseña todo). Aquí solo queda el embudo.
const filtered = computed(() =>
    props.conversations.filter(
        (c) => leadFilter.value === 'all' || c.lead_status === leadFilter.value,
    ),
);

// En Activas la lista se agrupa: primero lo que espera a un humano.
const groups = computed(() => {
    if (tab.value !== 'active' || searching.value) {
        return [
            {
                key: tab.value,
                label: null as string | null,
                items: filtered.value,
            },
        ];
    }
    return [
        {
            key: 'pending',
            label: 'Esperan humano',
            items: filtered.value.filter((c) => c.status === 'pending'),
        },
        {
            key: 'open',
            label: 'En curso',
            items: filtered.value.filter((c) => c.status === 'open'),
        },
    ].filter((g) => g.items.length);
});

const pendingCount = computed(
    () => props.conversations.filter((c) => c.status === 'pending').length,
);

// ── Hilo seleccionado ──
const selected = ref<ConversationRow | null>(null);

// ── Enseñar al asistente: captura una lección desde ESTA conversación ──
const teaching = ref(false);
const teachInput = ref('');
const teachSaving = ref(false);

async function teachAssistant() {
    if (!selected.value || teachInput.value.trim().length < 10) return;
    teachSaving.value = true;
    try {
        await axios.post('/api/agent-guidelines', {
            instruction: teachInput.value.trim(),
            source_conversation_id: selected.value.id,
        });
        teaching.value = false;
        teachInput.value = '';
        toast.success(
            'Aprendizaje guardado',
            'El bot lo aplica desde su siguiente respuesta; administra las lecciones en Asistente IA.',
        );
    } catch (e: any) {
        toast.error(
            'No se pudo guardar',
            e.response?.data?.message ??
                'Escribe la lección con al menos 10 caracteres.',
        );
    } finally {
        teachSaving.value = false;
    }
}
const thread = ref<ThreadMessage[]>([]);
const threadLoading = ref(false);
// La reserva de la conversación abierta: folio, fechas y dinero. Antes había
// que salir a /reservas o /pagos para saber si el huésped ya había pagado.
const reservationCard = ref<ReservationCard | null>(null);

const receiptTone: Record<string, string> = {
    match: 'bg-success/10 text-success',
    review: 'bg-pending/10 text-pending',
    duplicate: 'bg-danger/10 text-danger',
    not_receipt: 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
};

function readingLabel(reading: AttachmentReading): string {
    if (reading.verdict === 'not_receipt') {
        return reading.description
            ? `No es comprobante: ${reading.description}`
            : 'No es un comprobante de pago';
    }
    if (reading.verdict === 'duplicate') {
        return 'Comprobante repetido: lo revisa el personal';
    }
    return reading.summary ?? 'Comprobante por verificar';
}
const reply = ref('');
const sending = ref(false);
const threadRef = ref<HTMLElement | null>(null);
const replyRef = ref<HTMLTextAreaElement | null>(null);
let poller: ReturnType<typeof setInterval> | null = null;

// El textarea crece con el contenido (tope 10rem, luego scroll).
async function autosizeReply() {
    await nextTick();
    const el = replyRef.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

async function scrollThread() {
    await nextTick();
    threadRef.value?.scrollTo({ top: threadRef.value.scrollHeight });
}

// En móvil el hilo reemplaza a la lista; este botón regresa a ella.
function closeThread(): void {
    selected.value = null;
    thread.value = [];
    suggestion.value = null;
}

async function open(c: ConversationRow) {
    alerts.clearBadge();
    selected.value = c;
    showSummary.value = false;
    suggestion.value = null;
    usedCopilot.value = false;
    threadLoading.value = true;
    thread.value = [];
    reservationCard.value = null;
    await refreshThread();
    threadLoading.value = false;
    c.unread = 0;
    maybeAutoSuggest();
}

/**
 * Abre un hilo por id aunque no esté en la lista cargada. La lista es una
 * ventana de las más recientes: un enlace a una conversación de hace unos
 * días caía fuera y la pantalla decía que no existía.
 */
async function openById(id: number) {
    const found = props.conversations.find((c) => c.id === id);
    if (found) {
        await open(found);
        return;
    }

    try {
        const { data } = await axios.get(`/api/inbox/${id}`);
        await open(data.conversation as ConversationRow);
    } catch {
        toast.error(
            'No encontramos esa conversación',
            'Puede haberse eliminado de la bandeja.',
        );
    }
}

async function refreshThread() {
    if (!selected.value) return;
    try {
        const { data } = await axios.get(`/api/inbox/${selected.value.id}`);
        const grew = data.messages.length !== thread.value.length;
        thread.value = data.messages;
        reservationCard.value = data.reservation ?? null;
        Object.assign(selected.value, data.conversation);
        if (grew) scrollThread();
    } catch {
        /* la conversación pudo borrarse */
    }
}

// Adjunto saliente: una foto o un PDF por mensaje.
const attachmentInput = ref<HTMLInputElement | null>(null);
const attachment = ref<File | null>(null);

function pickAttachment(event: Event) {
    attachment.value = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function clearAttachment() {
    attachment.value = null;
    if (attachmentInput.value) attachmentInput.value.value = '';
}

async function sendReply() {
    const body = reply.value.trim();
    // Con adjunto el texto sobra: mandar solo la foto es legítimo.
    if ((!body && !attachment.value) || !selected.value || sending.value)
        return;

    sending.value = true;
    try {
        const form = new FormData();
        form.append('body', body);
        form.append('copilot', usedCopilot.value ? '1' : '0');
        if (attachment.value) form.append('attachment', attachment.value);

        const { data } = await axios.post(
            `/api/inbox/${selected.value.id}/reply`,
            form,
        );

        // Queda en el hilo aunque el canal lo rechace; se dice de frente en
        // vez de dejar creer que el huésped ya lo tiene.
        if (data?.delivered === false) {
            toast.error(
                attachment.value
                    ? 'El archivo no salió'
                    : 'El mensaje no salió',
                attachment.value
                    ? 'Quedó en el hilo, pero este canal todavía no manda adjuntos. Por ahora solo WhatsApp.'
                    : 'El canal lo rechazó (puede ser la ventana de 24 horas o el número). Quedó en el hilo marcado como no entregado: búscalo por otra vía.',
            );
        }

        reply.value = '';
        usedCopilot.value = false;
        clearAttachment();
        autosizeReply();
        await refreshThread();
    } catch (e: any) {
        toast.error(
            'No se pudo enviar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        sending.value = false;
    }
}

async function patchConversation(
    payload: Record<string, unknown>,
    message: string,
) {
    if (!selected.value) return;
    try {
        await axios.patch(`/api/inbox/${selected.value.id}`, payload);
        toast.success(message);
        await refreshThread();
        router.reload({ only: ['conversations', 'counts', 'pagination'] });
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

async function setChannelMode(channel: ChannelRow, mode: string) {
    try {
        await axios.patch(`/api/channels/${channel.id}`, { mode });
        channel.mode = mode;
        toast.success(
            'Modo actualizado',
            `${channel.name}: ${modeMeta[mode]?.label ?? mode}`,
        );
    } catch (e: any) {
        toast.error(
            'No se pudo cambiar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    }
}

const modeMeta: Record<string, { label: string; icon: Icon }> = {
    auto: { label: 'Automático', icon: 'Zap' },
    copilot: { label: 'Copiloto', icon: 'UserCheck' },
    off: { label: 'Apagado', icon: 'PowerOff' },
};

// Modal de canales: el modo de atención se asigna ahí, no inline en el
// encabezado (isolated-settings: la config va en su propia superficie).
const channelsOpen = ref(false);

// Resumen IA del hilo (memoria del bot), plegado por defecto.
const showSummary = ref(false);

// ── Copiloto: el bot redacta, el staff aprueba ──
const suggestion = ref<{
    text: string;
    meta: { provider: string; model: string; ms: number };
} | null>(null);
const suggestLoading = ref(false);
const usedCopilot = ref(false);

async function fetchSuggestion() {
    if (!selected.value || suggestLoading.value) return;
    suggestLoading.value = true;
    suggestion.value = null;
    try {
        const { data } = await axios.post(
            `/api/inbox/${selected.value.id}/suggest`,
        );
        suggestion.value = data;
    } catch (e: any) {
        toast.error(
            'Sin sugerencia',
            e.response?.data?.message ?? 'No se pudo generar el borrador.',
        );
    } finally {
        suggestLoading.value = false;
    }
}

function useSuggestion() {
    if (!suggestion.value) return;
    reply.value = suggestion.value.text;
    usedCopilot.value = true;
    suggestion.value = null;
    autosizeReply();
}

// ── Eliminar conversación (borra el hilo completo de la DB) ──
const deleting = ref<ConversationRow | null>(null);
const deleteBusy = ref(false);

async function submitDelete() {
    if (!deleting.value || deleteBusy.value) return;
    deleteBusy.value = true;
    try {
        await axios.delete(`/api/inbox/${deleting.value.id}`);
        if (selected.value?.id === deleting.value.id) {
            selected.value = null;
            thread.value = [];
            suggestion.value = null;
        }
        deleting.value = null;
        toast.success(
            'Conversación eliminada',
            'El hilo y sus mensajes se borraron definitivamente.',
        );
        router.reload({ only: ['conversations', 'counts', 'pagination'] });
    } catch (e: any) {
        toast.error(
            'No se pudo eliminar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        deleteBusy.value = false;
    }
}

// ── Archivar: sala de espera antes del borrado definitivo. Lo archivado
// se elimina solo a los 30 días (conversations:prune-archived) o al
// vaciar el archivo a mano; vuelve a la bandeja si el huésped escribe. ──
const archivingId = ref<number | null>(null);
const archivingAll = ref(false);

async function setArchived(c: ConversationRow, value: boolean) {
    archivingId.value = c.id;
    try {
        await axios.patch(`/api/inbox/${c.id}`, { archived: value });
        if (selected.value?.id === c.id) {
            selected.value.archived = value;
            if (value) selected.value.status = 'resolved';
        }
        toast.success(
            value ? 'Conversación archivada' : 'Conversación restaurada',
            value
                ? 'Se elimina sola en 30 días; vuelve a la bandeja si el huésped escribe.'
                : `${c.name} regresó a la bandeja.`,
        );
        router.reload({ only: ['conversations', 'counts', 'pagination'] });
    } catch (e: any) {
        toast.error(
            'No se pudo actualizar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        archivingId.value = null;
    }
}

async function archiveAllResolved() {
    if (archivingAll.value) return;
    archivingAll.value = true;
    try {
        const { data } = await axios.post('/api/inbox/archive-resolved');
        toast.success(
            'Resueltas archivadas',
            `${data.archived} conversación(es) pasaron al archivo.`,
        );
        if (selected.value?.status === 'resolved')
            selected.value.archived = true;
        router.reload({ only: ['conversations', 'counts', 'pagination'] });
    } catch (e: any) {
        toast.error(
            'No se pudo archivar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        archivingAll.value = false;
    }
}

// ── Vaciar el archivo: borrado definitivo de todo lo archivado ──
const emptyArchiveOpen = ref(false);
const emptyingArchive = ref(false);

async function emptyArchive() {
    if (emptyingArchive.value) return;
    emptyingArchive.value = true;
    try {
        const { data } = await axios.delete('/api/inbox/archived');
        emptyArchiveOpen.value = false;
        if (selected.value?.archived) {
            selected.value = null;
            thread.value = [];
            suggestion.value = null;
        }
        toast.success(
            'Archivo vaciado',
            `${data.deleted} conversación(es) se eliminaron definitivamente.`,
        );
        router.reload({ only: ['conversations', 'counts', 'pagination'] });
    } catch (e: any) {
        toast.error(
            'No se pudo vaciar',
            e.response?.data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        emptyingArchive.value = false;
    }
}

// En canales en modo copiloto, al abrir una conversación que espera
// respuesta (último mensaje del huésped) el borrador se pide solo.
function maybeAutoSuggest() {
    if (!props.canManage || !props.llmReady || !selected.value) return;
    if (
        selected.value.channel_mode !== 'copilot' ||
        selected.value.status === 'resolved'
    )
        return;
    const last = thread.value[thread.value.length - 1];
    if (last && last.direction === 'in') fetchSuggestion();
}

// Reverb avisa de cada mensaje nuevo (ConversationActivity). Como pueden
// llegar varios de golpe, la recarga se agrupa: una sola por ráfaga.
let refreshDebounce: ReturnType<typeof setTimeout> | null = null;

function refreshInbox(conversationId?: number) {
    if (refreshDebounce) clearTimeout(refreshDebounce);

    refreshDebounce = setTimeout(async () => {
        router.reload({ only: ['conversations', 'counts', 'pagination'] });

        // El hilo abierto solo se recarga si el mensaje es suyo (o si no
        // sabemos de cuál venía, como en el refresco de respaldo).
        if (
            selected.value &&
            (conversationId === undefined ||
                conversationId === selected.value.id)
        ) {
            await refreshThread();
        }
    }, 400);
}

useEcho<{ conversation_id: number; direction: string; at: string }>(
    `tenant.${props.tenantId}.inbox`,
    '.conversation.activity',
    (payload) => {
        refreshInbox(payload.conversation_id);

        // Solo avisa de lo que ENTRA: las respuestas del propio staff (o del
        // bot) no son algo a lo que haya que voltear.
        if (payload.direction !== 'in') return;

        // Si el hilo de esa conversación ya está abierto a la vista, el
        // mensaje se está leyendo: sonar sería ruido.
        if (
            selected.value?.id === payload.conversation_id &&
            !document.hidden
        ) {
            return;
        }

        const from = props.conversations.find(
            (c) => c.id === payload.conversation_id,
        );

        alerts.announce(
            'Mensaje nuevo',
            from
                ? `${from.name} escribió al hotel`
                : 'Alguien escribió al hotel',
        );
    },
);

// Red de seguridad por si el websocket se cae: un repaso cada minuto y al
// volver el foco a la pestaña — mismo patrón que el plano. Nunca cada pocos
// segundos: eso era lo que hacía la bandeja antes de Reverb.
function refreshIfVisible() {
    if (document.hidden) return;
    refreshInbox();
}

function onVisibilityChange() {
    refreshIfVisible();
}

onMounted(() => {
    poller = setInterval(refreshIfVisible, 60000);
    document.addEventListener('visibilitychange', onVisibilityChange);

    // Enlace directo a un hilo (?conversation=ID): lo usa la lista de
    // espera para enseñar el aviso que mandó el asistente. Si no está en la
    // lista cargada se pide al servidor: el enlace siempre abre el hilo.
    const wanted = Number(
        new URLSearchParams(window.location.search).get('conversation'),
    );

    if (wanted) openById(wanted);
});
onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
    if (refreshDebounce) clearTimeout(refreshDebounce);
    if (searchDebounce) clearTimeout(searchDebounce);
    document.removeEventListener('visibilitychange', onVisibilityChange);
});
</script>

<template>
    <RazeLayout title="Bandeja">
        <div class="mt-2">
            <!-- Encabezado. En el celular se quita mientras se lee un hilo:
                 ahí el nombre y el volver ya viven en la cabecera del chat, y
                 ese espacio le hacía falta a los mensajes. -->
            <div
                class="box box--stacked flex-wrap items-center justify-between gap-3 p-4 sm:p-5"
                :class="selected ? 'hidden xl:flex' : 'flex'"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Inbox" class="h-4 w-4" />
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-base font-medium">
                                Bandeja de conversaciones
                            </h1>
                            <span
                                v-if="pendingCount"
                                class="rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning"
                                >{{ pendingCount }} esperan humano</span
                            >
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">
                            {{ property.name }} · todos los canales en un solo
                            lugar
                        </p>
                    </div>
                </div>
                <!-- Móvil: cuadrícula de 2 para que los botones no se
                     amontonen; escritorio: fila de siempre. -->
                <div
                    class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center"
                >
                    <!-- Permiso de notificaciones: solo se pide con un clic,
                         que es lo que exigen los navegadores. -->
                    <Button
                        v-if="
                            alerts.canNotify.value &&
                            alerts.permission.value === 'default'
                        "
                        variant="outline-primary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                        title="Avisar aunque la pestaña esté en segundo plano"
                        @click="alerts.requestPermission"
                    >
                        <Lucide
                            icon="BellRing"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Activar avisos
                    </Button>
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                        :title="
                            alerts.muted.value
                                ? 'Los mensajes nuevos no suenan'
                                : 'Suena al llegar un mensaje nuevo'
                        "
                        @click="alerts.toggleMute"
                    >
                        <Lucide
                            :icon="alerts.muted.value ? 'BellOff' : 'Bell'"
                            class="h-3.5 w-3.5 stroke-[1.5]"
                            :class="alerts.muted.value ? 'text-slate-400' : ''"
                        />
                    </Button>
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                        @click="channelsOpen = true"
                    >
                        <Lucide
                            icon="Radio"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Canales
                        <span
                            class="ml-2 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"
                            >{{ channels.length }}</span
                        >
                    </Button>
                    <Button
                        as="a"
                        :href="route('tenant.webchat')"
                        target="_blank"
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] bg-white text-xs"
                    >
                        <Lucide
                            icon="ExternalLink"
                            class="mr-1.5 h-3.5 w-3.5 stroke-[1.5]"
                        />
                        Ver webchat
                    </Button>
                </div>
            </div>

            <!-- Mensajero: lista e hilo en un mismo panel -->
            <div class="box box--stacked mt-5 grid grid-cols-12">
                <!-- Lista de conversaciones (en móvil se muestra lista O hilo) -->
                <div
                    class="col-span-12 h-[calc(100dvh-11.5rem)] min-h-[480px] flex-col border-slate-200/60 xl:col-span-4 xl:flex xl:h-[calc(100vh-15rem)] xl:min-h-[560px] xl:border-r dark:border-darkmode-400"
                    :class="selected ? 'hidden' : 'flex'"
                >
                    <!-- Búsqueda y pestañas -->
                    <div
                        class="space-y-3 border-b border-slate-200/60 p-4 dark:border-darkmode-400"
                    >
                        <div class="flex flex-col gap-2.5 sm:flex-row">
                            <div class="relative min-w-0 flex-1">
                                <Lucide
                                    icon="Search"
                                    class="absolute inset-y-0 left-0 z-10 my-auto ml-3 h-4 w-4 stroke-[1.5] text-slate-400"
                                />
                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Buscar nombre, teléfono, folio o mensaje"
                                    class="h-9 w-full rounded-lg border border-slate-200 pr-3 pl-9 text-xs transition outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 dark:border-darkmode-400 dark:bg-darkmode-600"
                                    @input="onSearchInput"
                                    @search="onSearchInput"
                                />
                            </div>
                            <FormSelect
                                v-model="leadFilter"
                                class="shrink-0 text-xs sm:!w-auto"
                            >
                                <option value="all">Embudo: todos</option>
                                <option value="quoting">Cotizando</option>
                                <option value="hold">Con apartado</option>
                                <option value="won">Ganados</option>
                                <option value="lost">Perdidos</option>
                            </FormSelect>
                        </div>
                        <p
                            v-if="searching"
                            class="flex items-center gap-1.5 text-[11px] text-slate-500"
                        >
                            <Lucide
                                icon="Info"
                                class="h-3.5 w-3.5 shrink-0 text-slate-400"
                            />
                            Se busca en toda la bandeja, incluidas resueltas y
                            archivadas.
                        </p>
                        <button
                            v-if="counts.waiting > 0 || filters.esperando"
                            type="button"
                            class="mb-2 flex h-8 w-full items-center justify-center gap-1.5 rounded-[0.5rem] border px-2 text-xs font-medium transition"
                            :class="
                                filters.esperando
                                    ? 'border-warning bg-warning/10 text-warning'
                                    : 'border-slate-200/70 text-slate-500 hover:border-warning/60 hover:text-warning dark:border-darkmode-400'
                            "
                            title="Conversaciones que el asistente pasó a una persona y siguen sin respuesta"
                            @click="toggleEsperando"
                        >
                            <Lucide icon="Hourglass" class="h-3.5 w-3.5" />
                            Esperando al personal
                            <span class="font-semibold">{{
                                counts.waiting
                            }}</span>
                        </button>
                        <div
                            class="inline-flex w-full gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                        >
                            <button
                                v-for="f in [
                                    {
                                        key: 'active',
                                        label: 'Activas',
                                        count: counts.active,
                                    },
                                    {
                                        key: 'resolved',
                                        label: 'Resueltas',
                                        count: counts.resolved,
                                    },
                                    {
                                        key: 'archived',
                                        label: 'Archivadas',
                                        count: counts.archived,
                                    },
                                ]"
                                :key="f.key"
                                class="flex h-8 flex-1 items-center justify-center gap-1.5 rounded-[0.5rem] px-2 text-xs font-medium transition"
                                :class="
                                    tab === f.key
                                        ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                        : 'text-slate-500 hover:text-slate-700'
                                "
                                @click="setTab(f.key as InboxTab)"
                            >
                                {{ f.label }}
                                <span
                                    v-if="f.count"
                                    class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold"
                                    :class="
                                        tab === f.key
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-slate-200/80 text-slate-500 dark:bg-darkmode-400'
                                    "
                                    >{{ f.count }}</span
                                >
                            </button>
                        </div>
                        <div
                            v-if="
                                tab === 'resolved' &&
                                canManage &&
                                counts.resolved > 0
                            "
                            class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3.5 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <span class="text-xs leading-snug text-slate-500">
                                Archívalas para despejar la bandeja; el archivo
                                se borra solo a los 30 días.
                            </span>
                            <Button
                                variant="outline-secondary"
                                class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                :disabled="archivingAll"
                                @click="archiveAllResolved"
                            >
                                <Lucide
                                    icon="Archive"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{
                                    archivingAll
                                        ? 'Archivando…'
                                        : 'Archivar todas'
                                }}
                            </Button>
                        </div>
                        <div
                            v-if="tab === 'archived'"
                            class="flex items-center justify-between gap-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3.5 py-2.5 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <span class="text-xs leading-snug text-slate-500">
                                Se eliminan solas a los 30 días de archivadas;
                                vuelven a la bandeja si el huésped escribe.
                            </span>
                            <Button
                                v-if="canManage && counts.archived > 0"
                                variant="outline-danger"
                                class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                                @click="emptyArchiveOpen = true"
                            >
                                <Lucide
                                    icon="Trash2"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                Vaciar archivo
                            </Button>
                        </div>
                    </div>

                    <!-- Conversaciones (agrupadas por estado en Activas) -->
                    <div class="flex-1 overflow-y-auto">
                        <template v-for="g in groups" :key="g.key">
                            <div
                                v-if="g.label"
                                class="sticky top-0 z-10 flex items-center gap-2 border-y border-slate-200/60 bg-slate-50/95 px-3.5 py-1.5 backdrop-blur-sm first:border-t-0 dark:border-darkmode-400 dark:bg-darkmode-700/95"
                            >
                                <Lucide
                                    :icon="
                                        g.key === 'pending'
                                            ? 'UserCheck'
                                            : 'MessageCircle'
                                    "
                                    class="h-4 w-4"
                                    :class="
                                        g.key === 'pending'
                                            ? 'text-warning'
                                            : 'text-slate-400'
                                    "
                                />
                                <span
                                    class="text-xs font-medium tracking-wide uppercase"
                                    :class="
                                        g.key === 'pending'
                                            ? 'text-warning'
                                            : 'text-slate-400'
                                    "
                                    >{{ g.label }}</span
                                >
                                <span class="text-xs text-slate-400"
                                    >({{ g.items.length }})</span
                                >
                            </div>
                            <div
                                class="divide-y divide-slate-100 dark:divide-darkmode-400/60"
                            >
                                <div
                                    v-for="c in g.items"
                                    :key="c.id"
                                    role="button"
                                    tabindex="0"
                                    class="group flex w-full cursor-pointer items-start gap-3 px-3.5 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-darkmode-400/40"
                                    :class="{
                                        'bg-primary/5': selected?.id === c.id,
                                        'bg-primary/[0.03]':
                                            selected?.id !== c.id &&
                                            c.unread > 0,
                                    }"
                                    @click="open(c)"
                                    @keydown.enter.self.prevent="open(c)"
                                >
                                    <div class="relative shrink-0">
                                        <div
                                            class="flex h-10 w-10 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                                        >
                                            {{ initials(c.name) }}
                                        </div>
                                        <div
                                            class="absolute -right-1 -bottom-1 flex h-5 w-5 items-center justify-center rounded-full border-2 border-white dark:border-darkmode-600"
                                            :class="
                                                channelMeta[c.channel]?.tone ??
                                                'bg-slate-100'
                                            "
                                        >
                                            <Lucide
                                                :icon="
                                                    channelMeta[c.channel]
                                                        ?.icon ??
                                                    'MessageCircle'
                                                "
                                                class="h-2.5 w-2.5"
                                            />
                                        </div>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span
                                                class="truncate text-sm"
                                                :class="
                                                    c.unread
                                                        ? 'font-semibold'
                                                        : 'font-medium'
                                                "
                                                >{{ c.name }}</span
                                            >
                                            <span
                                                class="ml-auto shrink-0 text-xs text-slate-400"
                                                >{{ c.last_message_at }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-1 flex items-center gap-2"
                                        >
                                            <span
                                                class="truncate text-xs text-slate-500"
                                                >{{ c.preview ?? '—' }}</span
                                            >
                                            <span
                                                v-if="c.unread"
                                                class="ml-auto flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-semibold text-white"
                                                >{{ c.unread }}</span
                                            >
                                        </div>
                                        <div
                                            class="mt-1.5 flex flex-wrap items-center gap-1.5"
                                        >
                                            <span
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    statusMeta[c.status]?.tone
                                                "
                                                >{{
                                                    statusMeta[c.status]?.label
                                                }}</span
                                            >
                                            <span
                                                v-if="leadMeta[c.lead_status]"
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    leadMeta[c.lead_status].tone
                                                "
                                                >{{
                                                    leadMeta[c.lead_status]
                                                        .label
                                                }}</span
                                            >
                                            <span
                                                v-if="
                                                    c.waiting_label &&
                                                    c.status === 'pending'
                                                "
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    esperaTono(
                                                        c.waiting_minutes,
                                                    )
                                                "
                                                title="Lleva este tiempo esperando a una persona del hotel"
                                                >Espera
                                                {{ c.waiting_label }}</span
                                            >
                                            <span
                                                v-if="c.from_social"
                                                class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                                title="Nació de un comentario en redes sociales"
                                                >Comentario</span
                                            >
                                            <span
                                                v-if="
                                                    c.payment_pending_verification
                                                "
                                                class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                                >Verificar pago</span
                                            >
                                            <span
                                                v-else-if="
                                                    c.payment_status &&
                                                    c.payment_status_label
                                                "
                                                class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                :class="
                                                    paymentMeta[
                                                        c.payment_status
                                                    ]?.tone
                                                "
                                                >{{
                                                    c.payment_status_label
                                                }}</span
                                            >
                                            <span
                                                v-if="!c.bot_enabled"
                                                class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                                >{{
                                                    c.assignee ?? 'Humano'
                                                }}</span
                                            >
                                            <span
                                                v-else
                                                class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                                                >Bot</span
                                            >
                                            <button
                                                v-if="
                                                    canManage &&
                                                    !c.archived &&
                                                    c.status === 'resolved'
                                                "
                                                type="button"
                                                class="ml-auto rounded-md p-1.5 text-slate-400 opacity-0 transition group-hover:opacity-100 hover:bg-primary/10 hover:text-primary focus:opacity-100"
                                                title="Archivar conversación"
                                                :disabled="archivingId === c.id"
                                                @click.stop="
                                                    setArchived(c, true)
                                                "
                                            >
                                                <Lucide
                                                    icon="Archive"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                            <button
                                                v-if="canManage && c.archived"
                                                type="button"
                                                class="ml-auto rounded-md p-1.5 text-slate-400 opacity-0 transition group-hover:opacity-100 hover:bg-primary/10 hover:text-primary focus:opacity-100"
                                                title="Restaurar a la bandeja"
                                                :disabled="archivingId === c.id"
                                                @click.stop="
                                                    setArchived(c, false)
                                                "
                                            >
                                                <Lucide
                                                    icon="ArchiveRestore"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                            <button
                                                v-if="canManage"
                                                type="button"
                                                class="rounded-md p-1.5 text-slate-400 opacity-0 transition group-hover:opacity-100 hover:bg-danger/10 hover:text-danger focus:opacity-100"
                                                :class="
                                                    !c.archived &&
                                                    c.status !== 'resolved'
                                                        ? 'ml-auto'
                                                        : ''
                                                "
                                                title="Eliminar conversación"
                                                @click.stop="deleting = c"
                                            >
                                                <Lucide
                                                    icon="Trash2"
                                                    class="h-4 w-4"
                                                />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                        <div
                            v-if="!filtered.length"
                            class="flex flex-col items-center gap-3 py-12 text-center"
                        >
                            <div
                                class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Lucide icon="MessagesSquare" class="h-5 w-5" />
                            </div>
                            <p class="px-6 text-sm text-slate-500">
                                {{
                                    searching
                                        ? 'Ninguna conversación coincide con esa búsqueda, en toda la bandeja.'
                                        : leadFilter !== 'all'
                                          ? 'Sin resultados con estos filtros.'
                                          : tab === 'archived'
                                            ? 'El archivo está vacío; archiva las resueltas para despejar la bandeja.'
                                            : tab === 'resolved'
                                              ? 'No hay conversaciones resueltas pendientes de archivar.'
                                              : 'Sin conversaciones activas todavía. Comparte el webchat de tu hotel para empezar.'
                                }}
                            </p>
                        </div>
                    </div>

                    <!-- Cuántas se ven de cuántas hay: la lista es una
                         ventana de las más recientes, no la bandeja entera. -->
                    <div
                        v-if="pagination.total > pagination.loaded"
                        class="flex items-center justify-between gap-3 border-t border-slate-200/60 px-4 py-2.5 dark:border-darkmode-400"
                    >
                        <span class="text-xs text-slate-500">
                            Mostrando {{ pagination.loaded }} de
                            {{ pagination.total }}
                        </span>
                        <Button
                            v-if="pagination.has_more"
                            variant="outline-secondary"
                            class="h-8 shrink-0 rounded-[0.5rem] bg-white text-xs"
                            :disabled="loadingMore"
                            @click="loadMore"
                        >
                            <Lucide
                                icon="ChevronDown"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            {{ loadingMore ? 'Cargando…' : 'Cargar más' }}
                        </Button>
                    </div>
                </div>

                <!-- Hilo -->
                <div
                    class="col-span-12 min-h-[360px] flex-col xl:col-span-8 xl:flex xl:h-[calc(100vh-15rem)] xl:min-h-[560px]"
                    :class="
                        selected
                            ? 'flex h-[calc(100dvh-7.5rem)] xl:h-[calc(100vh-15rem)]'
                            : 'hidden'
                    "
                >
                    <template v-if="selected">
                        <!-- Header del hilo -->
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-2.5 border-b border-slate-200/60 px-4 py-3 sm:gap-x-3.5 sm:px-5 dark:border-darkmode-400"
                        >
                            <button
                                type="button"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 xl:hidden dark:hover:bg-darkmode-400"
                                title="Volver a la lista"
                                @click="closeThread"
                            >
                                <Lucide icon="ChevronLeft" class="h-5 w-5" />
                            </button>
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-linear-to-br from-theme-1 to-theme-2 text-xs font-semibold text-white"
                            >
                                {{ initials(selected.name) }}
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2.5">
                                    <span
                                        class="truncate text-sm font-medium"
                                        >{{ selected.name }}</span
                                    >
                                    <Link
                                        v-if="selected.guest_id"
                                        :href="
                                            route(
                                                'tenant.guests.show',
                                                selected.guest_id,
                                            )
                                        "
                                        class="shrink-0 text-xs text-primary hover:underline"
                                        >Ver perfil</Link
                                    >
                                </div>
                                <!-- En celular las etiquetas van en UNA línea
                                     que se desliza: envueltas ocupaban hasta
                                     cuatro renglones y se comían el chat. -->
                                <div
                                    class="mt-0.5 flex flex-nowrap items-center gap-1.5 overflow-x-auto text-xs whitespace-nowrap text-slate-500 sm:flex-wrap sm:overflow-visible"
                                >
                                    <Lucide
                                        :icon="
                                            channelMeta[selected.channel]
                                                ?.icon ?? 'MessageCircle'
                                        "
                                        class="h-3.5 w-3.5 shrink-0"
                                    />
                                    <span class="mr-1">{{
                                        channelMeta[selected.channel]?.label ??
                                        selected.channel
                                    }}</span>
                                    <!-- El número a la vista: para llamarle o
                                         pasarlo al personal sin abrir la ficha. -->
                                    <a
                                        v-if="selected.phone"
                                        :href="`tel:+${selected.phone.digits}`"
                                        class="mr-1 inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 font-medium text-slate-600 transition hover:text-primary dark:bg-darkmode-400 dark:text-slate-300"
                                        title="Llamar"
                                    >
                                        <Lucide icon="Phone" class="h-3 w-3" />
                                        {{ selected.phone.label }}
                                    </a>
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            statusMeta[selected.status]?.tone
                                        "
                                        >{{
                                            statusMeta[selected.status]?.label
                                        }}</span
                                    >
                                    <span
                                        v-if="selected.archived"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >Archivada</span
                                    >
                                    <span
                                        v-if="leadMeta[selected.lead_status]"
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            leadMeta[selected.lead_status].tone
                                        "
                                        >{{
                                            leadMeta[selected.lead_status].label
                                        }}</span
                                    >
                                    <span
                                        v-if="selected.reservation_code"
                                        class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 dark:bg-darkmode-400"
                                        >{{ selected.reservation_code }}</span
                                    >
                                    <span
                                        v-if="
                                            selected.payment_pending_verification
                                        "
                                        class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                        >Verificar pago</span
                                    >
                                    <span
                                        v-else-if="
                                            selected.payment_status &&
                                            selected.payment_status_label
                                        "
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            paymentMeta[selected.payment_status]
                                                ?.tone
                                        "
                                        >{{
                                            selected.payment_status_label
                                        }}</span
                                    >
                                </div>
                            </div>
                            <!-- En angosto las acciones bajan a su propio
                                 renglón completo: si comparten fila, aplastan
                                 el nombre y el canal hasta volverlos "V…". -->
                            <div
                                v-if="canManage"
                                class="flex w-full flex-wrap items-center gap-2 md:w-auto"
                            >
                                <Button
                                    v-if="selected.summary"
                                    variant="outline-secondary"
                                    class="h-9 rounded-[0.5rem] bg-white text-xs"
                                    :class="{
                                        '!border-primary/40 !text-primary':
                                            showSummary,
                                    }"
                                    @click="showSummary = !showSummary"
                                >
                                    <Lucide
                                        icon="Sparkles"
                                        class="h-3.5 w-3.5 sm:mr-1.5"
                                    />
                                    <span class="hidden sm:inline"
                                        >Resumen IA</span
                                    >
                                </Button>
                                <Button
                                    v-if="selected.status !== 'resolved'"
                                    variant="outline-secondary"
                                    class="hidden h-9 rounded-[0.5rem] bg-white text-xs md:inline-flex"
                                    @click="
                                        patchConversation(
                                            { status: 'resolved' },
                                            'Conversación resuelta.',
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="CircleCheck"
                                        class="h-3.5 w-3.5 sm:mr-1.5"
                                    />
                                    <span class="hidden sm:inline"
                                        >Resolver</span
                                    >
                                </Button>
                                <Button
                                    v-if="
                                        selected.status === 'resolved' &&
                                        !selected.archived
                                    "
                                    variant="outline-secondary"
                                    class="hidden h-9 rounded-[0.5rem] bg-white text-xs md:inline-flex"
                                    @click="
                                        patchConversation(
                                            { status: 'open' },
                                            'Conversación reabierta.',
                                        )
                                    "
                                >
                                    <Lucide
                                        icon="RotateCcw"
                                        class="h-3.5 w-3.5 sm:mr-1.5"
                                    />
                                    <span class="hidden sm:inline"
                                        >Reabrir</span
                                    >
                                </Button>
                                <Button
                                    v-if="selected.archived"
                                    variant="outline-secondary"
                                    class="hidden h-9 rounded-[0.5rem] bg-white text-xs md:inline-flex"
                                    :disabled="archivingId === selected.id"
                                    @click="setArchived(selected, false)"
                                >
                                    <Lucide
                                        icon="ArchiveRestore"
                                        class="h-3.5 w-3.5 sm:mr-1.5"
                                    />
                                    <span class="hidden sm:inline"
                                        >Restaurar</span
                                    >
                                </Button>
                                <FormSelect
                                    :model-value="selected.assigned_to ?? ''"
                                    class="h-9 min-w-0 flex-1 text-xs md:!w-auto md:max-w-[12rem] md:flex-none"
                                    @update:model-value="
                                        (v: string) =>
                                            patchConversation(
                                                { assigned_to: v || null },
                                                'Asignación actualizada.',
                                            )
                                    "
                                >
                                    <option value="">Sin asignar</option>
                                    <option
                                        v-for="s in staff"
                                        :key="s.id"
                                        :value="s.id"
                                    >
                                        {{ s.name }}
                                    </option>
                                </FormSelect>
                                <Menu>
                                    <Menu.Button
                                        class="flex h-9 w-9 items-center justify-center rounded-[0.5rem] border border-slate-200 bg-white text-slate-500 transition hover:bg-slate-100 dark:border-darkmode-400 dark:bg-darkmode-600 dark:hover:bg-darkmode-400"
                                        title="Más acciones"
                                    >
                                        <Lucide
                                            icon="MoreVertical"
                                            class="h-4 w-4"
                                        />
                                    </Menu.Button>
                                    <Menu.Items
                                        class="w-60"
                                        placement="bottom-end"
                                    >
                                        <!-- En móvil, Resolver/Reabrir/Restaurar
                                             viven aquí: los botones de la barra
                                             se ocultan para no desperdiciar
                                             espacio del encabezado. -->
                                        <Menu.Item
                                            v-if="
                                                selected.status !== 'resolved'
                                            "
                                            as="button"
                                            type="button"
                                            class="md:hidden"
                                            @click="
                                                patchConversation(
                                                    { status: 'resolved' },
                                                    'Conversación resuelta.',
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="CircleCheck"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Resolver conversación
                                        </Menu.Item>
                                        <Menu.Item
                                            v-if="
                                                selected.status ===
                                                    'resolved' &&
                                                !selected.archived
                                            "
                                            as="button"
                                            type="button"
                                            class="md:hidden"
                                            @click="
                                                patchConversation(
                                                    { status: 'open' },
                                                    'Conversación reabierta.',
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="RotateCcw"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Reabrir conversación
                                        </Menu.Item>
                                        <Menu.Item
                                            v-if="selected.archived"
                                            as="button"
                                            type="button"
                                            class="md:hidden"
                                            :disabled="
                                                archivingId === selected.id
                                            "
                                            @click="
                                                setArchived(selected, false)
                                            "
                                        >
                                            <Lucide
                                                icon="ArchiveRestore"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Restaurar conversación
                                        </Menu.Item>
                                        <Menu.Divider class="md:hidden" />
                                        <Menu.Item
                                            v-if="canTeach"
                                            as="button"
                                            type="button"
                                            @click="teaching = true"
                                        >
                                            <Lucide
                                                icon="GraduationCap"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Enseñar al asistente
                                        </Menu.Item>
                                        <Menu.Item
                                            v-if="!selected.bot_enabled"
                                            as="button"
                                            type="button"
                                            @click="
                                                patchConversation(
                                                    {
                                                        bot_enabled: true,
                                                        status: 'open',
                                                    },
                                                    'El bot retomó la conversación.',
                                                )
                                            "
                                        >
                                            <Lucide
                                                icon="Bot"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Devolver al bot
                                        </Menu.Item>
                                        <Menu.Item
                                            v-if="
                                                selected.status ===
                                                    'resolved' &&
                                                !selected.archived
                                            "
                                            as="button"
                                            type="button"
                                            :disabled="
                                                archivingId === selected.id
                                            "
                                            @click="setArchived(selected, true)"
                                        >
                                            <Lucide
                                                icon="Archive"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Archivar conversación
                                        </Menu.Item>
                                        <Menu.Divider />
                                        <Menu.Item
                                            as="button"
                                            type="button"
                                            class="text-danger"
                                            @click="deleting = selected"
                                        >
                                            <Lucide
                                                icon="Trash2"
                                                class="mr-2 h-4 w-4"
                                            />
                                            Eliminar conversación
                                        </Menu.Item>
                                    </Menu.Items>
                                </Menu>
                            </div>
                        </div>

                        <!-- Resumen IA (memoria del bot) -->
                        <div
                            v-if="showSummary && selected.summary"
                            class="border-b border-dashed border-slate-300/70 bg-primary/[0.03] px-4 py-3"
                        >
                            <div class="flex items-start gap-3">
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                                >
                                    <Lucide
                                        icon="Sparkles"
                                        class="h-4 w-4 text-primary"
                                    />
                                </div>
                                <div class="min-w-0">
                                    <div
                                        class="text-xs font-medium tracking-wide text-slate-400 uppercase"
                                    >
                                        Resumen de la conversación
                                    </div>
                                    <p
                                        class="mt-1 text-sm whitespace-pre-line text-slate-600 dark:text-slate-300"
                                    >
                                        {{ selected.summary }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- La reserva de esta conversación y su dinero -->
                        <div
                            v-if="reservationCard"
                            class="border-b border-slate-200/60 bg-slate-50/70 px-4 py-3 text-xs dark:border-darkmode-400 dark:bg-darkmode-700/40"
                        >
                            <div
                                class="flex flex-wrap items-center gap-x-3 gap-y-1.5"
                            >
                                <a
                                    :href="reservationCard.url"
                                    class="inline-flex h-7 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 text-[11px] font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                                >
                                    <Lucide
                                        :icon="
                                            reservationCard.is_group
                                                ? 'Layers'
                                                : 'CalendarCheck'
                                        "
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ reservationCard.code }}
                                </a>
                                <span
                                    class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500 dark:bg-darkmode-400"
                                    >{{ reservationCard.status_label }}</span
                                >
                                <span
                                    class="inline-flex items-center gap-1.5 text-slate-500"
                                >
                                    <Lucide
                                        icon="BedDouble"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ reservationCard.rooms_count }}
                                    {{
                                        reservationCard.rooms_count === 1
                                            ? 'habitación'
                                            : 'habitaciones'
                                    }}
                                    · {{ reservationCard.guests }}
                                    {{
                                        reservationCard.guests === 1
                                            ? 'persona'
                                            : 'personas'
                                    }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 text-slate-500"
                                >
                                    <Lucide icon="Clock" class="h-3.5 w-3.5" />
                                    {{ reservationCard.starts_label }} →
                                    {{ reservationCard.ends_label }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 text-slate-500"
                                >
                                    <Lucide
                                        icon="Banknote"
                                        class="h-3.5 w-3.5"
                                    />
                                    {{ reservationCard.total_label }} · pagado
                                    {{ reservationCard.paid_label }} · falta
                                    <span class="font-medium">{{
                                        reservationCard.pending_label
                                    }}</span>
                                </span>
                                <span
                                    v-if="reservationCard.hold_expires_label"
                                    class="rounded-full bg-pending/10 px-2 py-0.5 text-[11px] font-medium text-pending"
                                    >Se sostiene hasta
                                    {{
                                        reservationCard.hold_expires_label
                                    }}</span
                                >
                            </div>

                            <!-- El cobro que espera comprobante, con lo que el
                                 sistema leyó en la foto -->
                            <div
                                v-if="reservationCard.request"
                                class="mt-2 flex flex-wrap items-center gap-2 rounded-lg border border-slate-200/70 bg-white px-2.5 py-1.5 dark:border-darkmode-400 dark:bg-darkmode-600"
                            >
                                <Lucide
                                    icon="ReceiptText"
                                    class="h-3.5 w-3.5 text-slate-400"
                                />
                                <span class="font-medium"
                                    >{{ reservationCard.request.concept }}
                                    {{
                                        reservationCard.request.amount_label
                                    }}</span
                                >
                                <span
                                    v-if="reservationCard.request.has_receipt"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="
                                        receiptTone[
                                            reservationCard.request.verdict ??
                                                'review'
                                        ] ?? 'bg-pending/10 text-pending'
                                    "
                                    >{{
                                        reservationCard.request.summary ??
                                        'Comprobante por verificar'
                                    }}</span
                                >
                                <span v-else class="text-slate-500"
                                    >Sin comprobante todavía</span
                                >
                                <span
                                    v-for="(aviso, i) in reservationCard.request
                                        .warnings"
                                    :key="i"
                                    class="inline-flex items-center gap-1 rounded-full bg-warning/10 px-2 py-0.5 text-[11px] font-medium text-warning"
                                >
                                    <Lucide
                                        icon="TriangleAlert"
                                        class="h-3 w-3"
                                    />
                                    {{ aviso }}
                                </span>
                                <a
                                    href="/pagos"
                                    class="ml-auto inline-flex h-7 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 text-[11px] font-medium text-slate-600 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-300"
                                >
                                    Verificar en Pagos
                                </a>
                            </div>
                        </div>

                        <!-- Mensajes -->
                        <div
                            ref="threadRef"
                            class="flex-1 space-y-3 overflow-y-auto bg-slate-50/60 px-4 py-4 dark:bg-darkmode-700/40"
                        >
                            <div
                                v-if="threadLoading"
                                class="flex items-center justify-center gap-2 py-10 text-sm text-slate-400"
                            >
                                <Lucide
                                    icon="RefreshCw"
                                    class="h-4 w-4 animate-spin"
                                />
                                Cargando conversación…
                            </div>
                            <template v-for="m in thread" :key="m.id">
                                <div
                                    class="flex"
                                    :class="
                                        m.direction === 'in'
                                            ? 'justify-start'
                                            : 'justify-end'
                                    "
                                >
                                    <div class="max-w-[85%] sm:max-w-[72%]">
                                        <div
                                            class="rounded-2xl px-3.5 py-2 text-sm leading-relaxed whitespace-pre-line"
                                            :class="
                                                m.direction === 'in'
                                                    ? 'rounded-bl-md border border-slate-200 bg-white text-slate-700 dark:border-darkmode-400 dark:bg-darkmode-600 dark:text-slate-200'
                                                    : m.sender_type === 'bot'
                                                      ? 'rounded-br-md bg-primary/10 text-slate-700 dark:text-slate-200'
                                                      : m.sender_type ===
                                                          'system'
                                                        ? 'rounded-br-md bg-warning/10 text-slate-600 dark:text-slate-300'
                                                        : 'rounded-br-md bg-linear-to-r from-theme-1 to-theme-2 text-white'
                                            "
                                        >
                                            <div
                                                v-if="m.voice_note"
                                                class="mb-1 flex items-center gap-1 text-[11px] text-slate-400"
                                            >
                                                <Lucide
                                                    icon="Mic"
                                                    class="h-3 w-3"
                                                />
                                                Nota de voz transcrita
                                            </div>
                                            {{ m.body }}
                                            <div
                                                v-if="m.undelivered"
                                                class="mt-1.5 inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-medium text-danger"
                                                title="El canal lo rechazó: el huésped no lo recibió"
                                            >
                                                <Lucide
                                                    icon="TriangleAlert"
                                                    class="h-3 w-3"
                                                />
                                                No se entregó
                                            </div>
                                            <div
                                                v-if="m.attachments?.length"
                                                class="mt-2 space-y-2"
                                            >
                                                <div
                                                    v-for="a in m.attachments"
                                                    :key="a.id"
                                                >
                                                    <a
                                                        :href="a.url"
                                                        target="_blank"
                                                        class="block"
                                                    >
                                                        <img
                                                            v-if="a.is_image"
                                                            :src="a.url"
                                                            :alt="a.name"
                                                            class="max-h-48 max-w-full rounded-lg border border-slate-200/70 object-contain dark:border-darkmode-400"
                                                        />
                                                        <span
                                                            v-else
                                                            class="inline-flex items-center gap-1.5 text-sm underline"
                                                        >
                                                            <Lucide
                                                                icon="FileText"
                                                                class="h-4 w-4"
                                                            />
                                                            {{ a.name }}
                                                        </span>
                                                    </a>
                                                    <!-- Qué se leyó en la
                                                         imagen: comprobante
                                                         con sus datos, o no -->
                                                    <span
                                                        v-if="a.reading"
                                                        class="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                                        :class="
                                                            receiptTone[
                                                                a.reading
                                                                    .verdict ??
                                                                    'review'
                                                            ] ??
                                                            'bg-pending/10 text-pending'
                                                        "
                                                    >
                                                        <Lucide
                                                            icon="ReceiptText"
                                                            class="h-3 w-3"
                                                        />
                                                        {{
                                                            readingLabel(
                                                                a.reading,
                                                            )
                                                        }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                        <div
                                            class="mt-1 flex items-center gap-1.5 text-xs text-slate-400"
                                            :class="
                                                m.direction === 'in'
                                                    ? ''
                                                    : 'justify-end'
                                            "
                                        >
                                            <Lucide
                                                v-if="m.sender_type === 'bot'"
                                                icon="Bot"
                                                class="h-3.5 w-3.5"
                                            />
                                            <Lucide
                                                v-else-if="
                                                    m.sender_type === 'staff'
                                                "
                                                icon="User"
                                                class="h-3.5 w-3.5"
                                            />
                                            {{
                                                m.sender_type === 'bot'
                                                    ? 'Asistente IA'
                                                    : (m.sender ??
                                                      (m.sender_type ===
                                                      'system'
                                                          ? 'Sistema'
                                                          : ''))
                                            }}
                                            · {{ m.at }}
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Compositor -->
                        <div
                            v-if="canManage"
                            class="border-t border-slate-200/60 p-3 sm:p-4 dark:border-darkmode-400"
                        >
                            <div
                                v-if="selected.bot_enabled"
                                class="mb-2.5 flex items-start gap-2 rounded-lg bg-primary/5 px-3 py-2 text-[11px] leading-snug text-slate-500"
                            >
                                <Lucide
                                    icon="Bot"
                                    class="mt-px h-3.5 w-3.5 shrink-0 text-primary"
                                />
                                <span
                                    >El bot atiende esta conversación. Si
                                    respondes tú, la tomas (el bot se
                                    pausa).</span
                                >
                            </div>

                            <!-- Copiloto: borrador con aprobación humana -->
                            <div
                                v-if="suggestLoading"
                                class="mb-2.5 flex items-center gap-2 rounded-lg border border-dashed border-primary/30 bg-primary/[0.03] px-3 py-2 text-[11px] text-slate-500"
                            >
                                <Lucide
                                    icon="Sparkles"
                                    class="h-3.5 w-3.5 shrink-0 animate-pulse text-primary"
                                />
                                El copiloto está redactando una sugerencia…
                            </div>
                            <div
                                v-else-if="suggestion"
                                class="mb-2.5 rounded-xl border border-primary/20 bg-primary/[0.04] p-3"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1"
                                >
                                    <Lucide
                                        icon="Sparkles"
                                        class="h-3.5 w-3.5 shrink-0 text-primary"
                                    />
                                    <span
                                        class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                                        >Sugerencia del copiloto</span
                                    >
                                    <span
                                        class="rounded-full bg-primary/10 px-2 py-0.5 text-[11px] text-primary sm:ml-auto"
                                        >{{ suggestion.meta.provider }} ·
                                        {{
                                            (suggestion.meta.ms / 1000).toFixed(
                                                1,
                                            )
                                        }}s</span
                                    >
                                </div>
                                <p
                                    class="mt-2 text-xs leading-relaxed whitespace-pre-line text-slate-600 dark:text-slate-300"
                                >
                                    {{ suggestion.text }}
                                </p>
                                <div
                                    class="mt-2.5 flex flex-wrap items-center gap-2"
                                >
                                    <Button
                                        variant="primary"
                                        class="h-8 rounded-[0.5rem] text-xs"
                                        @click="useSuggestion"
                                    >
                                        <Lucide
                                            icon="Check"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Usar y editar
                                    </Button>
                                    <Button
                                        variant="outline-secondary"
                                        class="h-8 rounded-[0.5rem] bg-white text-xs"
                                        :disabled="suggestLoading"
                                        @click="fetchSuggestion"
                                    >
                                        <Lucide
                                            icon="RefreshCw"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Otra
                                    </Button>
                                    <button
                                        type="button"
                                        class="ml-auto text-xs text-slate-400 transition hover:text-danger"
                                        @click="suggestion = null"
                                    >
                                        Descartar
                                    </button>
                                </div>
                            </div>

                            <!-- Una sola caja: el texto arriba y las
                                 herramientas abajo. Antes eran botones y
                                 textarea en la misma fila, y el ancho mínimo
                                 del textarea los empujaba fuera del panel. -->
                            <div
                                class="rounded-xl border border-slate-200 bg-white transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/15 dark:border-darkmode-400 dark:bg-darkmode-600"
                            >
                                <textarea
                                    ref="replyRef"
                                    v-model="reply"
                                    rows="2"
                                    placeholder="Responder como staff…"
                                    class="block max-h-40 min-h-[52px] w-full resize-none overflow-y-auto border-0 bg-transparent px-3.5 pt-2.5 pb-1 text-sm leading-relaxed text-slate-700 outline-none placeholder:text-slate-400 dark:text-slate-200"
                                    @input="autosizeReply"
                                    @keydown.enter.exact.prevent="sendReply"
                                />

                                <!-- Archivo elegido, antes de mandarlo -->
                                <div
                                    v-if="attachment"
                                    class="mx-2.5 mb-1.5 flex items-center gap-2 rounded-lg bg-slate-100 px-2.5 py-1.5 text-[11px] dark:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="Paperclip"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-500"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{
                                        attachment.name
                                    }}</span>
                                    <button
                                        type="button"
                                        title="Quitar el archivo"
                                        class="shrink-0 text-slate-400 transition hover:text-danger"
                                        @click="clearAttachment"
                                    >
                                        <Lucide icon="X" class="h-3.5 w-3.5" />
                                    </button>
                                </div>

                                <div
                                    class="flex items-center gap-1 border-t border-slate-100 px-2 py-1.5 dark:border-darkmode-400/70"
                                >
                                    <button
                                        v-if="llmReady && !suggestion"
                                        type="button"
                                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-2 text-xs font-medium text-slate-500 transition hover:bg-primary/10 hover:text-primary disabled:opacity-50 sm:px-2.5"
                                        :disabled="suggestLoading"
                                        title="Pídele al copiloto un borrador de respuesta"
                                        @click="fetchSuggestion"
                                    >
                                        <Lucide
                                            icon="Sparkles"
                                            class="h-4 w-4 text-primary"
                                        />
                                        <span class="hidden sm:inline"
                                            >Copiloto</span
                                        >
                                    </button>
                                    <input
                                        ref="attachmentInput"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp,application/pdf"
                                        class="hidden"
                                        @change="pickAttachment"
                                    />
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary disabled:opacity-50 dark:hover:bg-darkmode-400"
                                        title="Adjuntar foto o PDF"
                                        :disabled="sending"
                                        @click="attachmentInput?.click()"
                                    >
                                        <Lucide
                                            icon="Paperclip"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                    <span
                                        class="ml-auto hidden truncate pr-1 text-[11px] text-slate-400 lg:inline"
                                        >Enter envía · Shift+Enter salta de
                                        línea</span
                                    >
                                    <button
                                        type="button"
                                        class="ml-auto inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full bg-primary px-3 text-xs font-medium text-white shadow-sm shadow-primary/20 transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-40 sm:px-3.5 lg:ml-2"
                                        title="Enviar (Enter)"
                                        :disabled="
                                            sending ||
                                            (!reply.trim() && !attachment)
                                        "
                                        @click="sendReply"
                                    >
                                        <Lucide
                                            :icon="
                                                sending
                                                    ? 'RefreshCw'
                                                    : 'SendHorizontal'
                                            "
                                            class="h-3.5 w-3.5"
                                            :class="
                                                sending ? 'animate-spin' : ''
                                            "
                                        />
                                        <span class="hidden sm:inline">{{
                                            sending ? 'Enviando…' : 'Enviar'
                                        }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Sin selección -->
                    <div
                        v-else
                        class="flex flex-1 flex-col items-center justify-center gap-4 text-center"
                    >
                        <div
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-primary"
                        >
                            <Lucide icon="MessagesSquare" class="h-5 w-5" />
                        </div>
                        <div>
                            <p class="text-sm font-medium">
                                Elige una conversación
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                El hilo completo se muestra aquí, con el
                                copiloto listo para ayudarte.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: enseñar al asistente desde esta conversación -->
        <Dialog :open="teaching" @close="teaching = false">
            <Dialog.Panel>
                <form class="p-5" @submit.prevent="teachAssistant">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10"
                        >
                            <Lucide
                                icon="GraduationCap"
                                class="h-5 w-5 text-primary"
                            />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Enseñar al asistente
                            </h2>
                            <p class="text-xs text-slate-500">
                                Describe qué debió hacer distinto: se vuelve una
                                regla que cumple en todas las conversaciones.
                            </p>
                        </div>
                    </div>
                    <FormTextarea
                        v-model="teachInput"
                        rows="3"
                        maxlength="500"
                        placeholder="Ej. Cuando el huésped pida varias cabañas, aparta cada una por separado y reporta el resultado exacto de cada apartado; nunca digas que falló sin citar el error de la herramienta."
                    />
                    <p class="mt-2 text-xs text-slate-400">
                        Quedará ligada a esta conversación y podrás pausarla o
                        borrarla en Asistente IA → Aprendizajes.
                    </p>
                    <div class="mt-4 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline-secondary"
                            @click="teaching = false"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            variant="primary"
                            :disabled="
                                teachSaving || teachInput.trim().length < 10
                            "
                        >
                            {{ teachSaving ? 'Guardando…' : 'Guardar lección' }}
                        </Button>
                    </div>
                </form>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal eliminar conversación -->
        <Dialog :open="deleting !== null" @close="deleting = null">
            <Dialog.Panel>
                <div v-if="deleting" class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Eliminar la conversación de
                                {{ deleting.name }}?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Se borrará el hilo completo con todos sus
                                mensajes. Esta acción no se puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="deleting = null"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="deleteBusy"
                            @click="submitDelete"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{ deleteBusy ? 'Eliminando…' : 'Sí, eliminar' }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal vaciar archivo -->
        <Dialog :open="emptyArchiveOpen" @close="emptyArchiveOpen = false">
            <Dialog.Panel>
                <div class="p-6">
                    <div class="flex items-start gap-3.5">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-danger/10 text-danger"
                        >
                            <Lucide icon="Trash2" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                ¿Vaciar el archivo?
                            </h2>
                            <p class="mt-0.5 text-sm text-slate-500">
                                Se eliminan definitivamente las
                                {{ counts.archived }} conversación(es)
                                archivadas con sus mensajes. Esta acción no se
                                puede deshacer.
                            </p>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button
                            variant="outline-secondary"
                            @click="emptyArchiveOpen = false"
                            >Cancelar</Button
                        >
                        <Button
                            variant="danger"
                            :disabled="emptyingArchive"
                            @click="emptyArchive"
                        >
                            <Lucide icon="Trash2" class="mr-2 h-4 w-4" />
                            {{
                                emptyingArchive
                                    ? 'Vaciando…'
                                    : 'Sí, vaciar archivo'
                            }}
                        </Button>
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>

        <!-- Modal canales: modo de atención por canal -->
        <Dialog size="lg" :open="channelsOpen" @close="channelsOpen = false">
            <Dialog.Panel>
                <div class="p-5">
                    <div class="mb-4 flex items-center gap-3">
                        <div
                            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                        >
                            <Lucide icon="Radio" class="h-5 w-5" />
                        </div>
                        <div>
                            <h2 class="text-base font-medium">
                                Canales conectados
                            </h2>
                            <p class="text-xs text-slate-500">
                                Elige cómo atiende el asistente cada canal; el
                                cambio aplica al momento.
                            </p>
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div
                            v-for="ch in channels"
                            :key="ch.id"
                            class="rounded-xl border border-slate-200/70 p-3.5 dark:border-darkmode-400"
                        >
                            <div class="flex flex-wrap items-center gap-3">
                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                                    :class="channelMeta[ch.type]?.tone"
                                >
                                    <Lucide
                                        :icon="
                                            channelMeta[ch.type]?.icon ??
                                            'MessageCircle'
                                        "
                                        class="h-4 w-4"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium">
                                        {{ ch.name }}
                                    </div>
                                    <div
                                        class="truncate text-xs text-slate-500"
                                    >
                                        {{
                                            channelMeta[ch.type]?.label ??
                                            ch.type
                                        }}
                                    </div>
                                </div>
                                <div
                                    v-if="canManage"
                                    class="inline-flex gap-1 rounded-[0.6rem] bg-slate-100/80 p-1 dark:bg-darkmode-700"
                                >
                                    <button
                                        v-for="(m, key) in modeMeta"
                                        :key="key"
                                        type="button"
                                        class="flex h-8 items-center gap-1.5 rounded-[0.5rem] px-3 text-xs font-medium transition"
                                        :class="
                                            ch.mode === key
                                                ? 'bg-white text-primary shadow-sm dark:bg-darkmode-600'
                                                : 'text-slate-500 hover:text-slate-700'
                                        "
                                        @click="
                                            setChannelMode(ch, key as string)
                                        "
                                    >
                                        <Lucide
                                            :icon="m.icon"
                                            class="h-4 w-4"
                                        />
                                        {{ m.label }}
                                    </button>
                                </div>
                                <span
                                    v-else
                                    class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500 dark:bg-darkmode-400"
                                >
                                    {{ modeMeta[ch.mode]?.label ?? ch.mode }}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div
                        class="mt-4 space-y-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3.5 py-3 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                    >
                        <div class="flex items-center gap-2">
                            <Lucide icon="Zap" class="h-3.5 w-3.5 shrink-0" />
                            Automático: el asistente responde solo y te pasa la
                            conversación cuando el huésped pide a un humano.
                        </div>
                        <div class="flex items-center gap-2">
                            <Lucide
                                icon="UserCheck"
                                class="h-3.5 w-3.5 shrink-0"
                            />
                            Copiloto: el asistente redacta borradores y el staff
                            aprueba antes de enviar.
                        </div>
                        <div class="flex items-center gap-2">
                            <Lucide
                                icon="PowerOff"
                                class="h-3.5 w-3.5 shrink-0"
                            />
                            Apagado: solo atiende tu equipo, sin asistente.
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end">
                        <Button
                            variant="primary"
                            class="min-h-10 px-5"
                            @click="channelsOpen = false"
                            >Listo</Button
                        >
                    </div>
                </div>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
