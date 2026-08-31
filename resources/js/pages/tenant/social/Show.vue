<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect, FormTextarea } from '@/components/Base/Form';
import { Dialog, Menu } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

interface Comment {
    id: number;
    author_name: string | null;
    body: string | null;
    classification: string | null;
    classification_label: string | null;
    status: string;
    status_label: string;
    public_reply_text: string | null;
    public_replied_at: string | null;
    private_reply_sent_at: string | null;
    private_reply_error: string | null;
    can_private_reply: boolean;
    hidden: boolean;
    hidden_reason: string | null;
    commented_label: string | null;
    conversation_uuid: string | null;
    lead_status: string | null;
    deleted_from_network: boolean;
}

const props = defineProps<{
    post: {
        id: number;
        network: string;
        network_label: string;
        message: string | null;
        excerpt: string;
        permalink: string | null;
        media_url: string | null;
        published_label: string | null;
        last_synced_at: string | null;
    };
    comments: Comment[];
    stats: {
        total: number;
        pendientes: number;
        compras: number;
        conversaciones: number;
    };
}>();

// Clases COMPLETAS por token: Tailwind solo genera lo que puede leer, así que
// un `border-${tono}/20` interpolado nunca existiría.
const tones: Record<string, string> = {
    primary: 'border-primary/20 bg-primary/10 text-primary',
    success: 'border-success/20 bg-success/10 text-success',
    info: 'border-info/20 bg-info/10 text-info',
    warning: 'border-warning/20 bg-warning/10 text-warning',
    pending: 'border-pending/20 bg-pending/10 text-pending',
    danger: 'border-danger/20 bg-danger/10 text-danger',
    dark: 'border-dark/20 bg-dark/10 text-dark',
};

const classificationTone: Record<string, string> = {
    compra: 'success',
    pregunta: 'info',
    queja: 'danger',
    elogio: 'primary',
    spam: 'dark',
};

const statusTone: Record<string, string> = {
    nuevo: 'pending',
    respondido: 'success',
    pendiente_staff: 'warning',
    oculto: 'dark',
    ignorado: 'dark',
};

const toneClass = (tone: string) => tones[tone] ?? tones.dark;

const initial = (name: string | null) =>
    (name ?? 'U').trim().charAt(0).toUpperCase() || 'U';

// La imagen no se pudo recuperar (video sin miniatura, publicación borrada):
// se quita la columna en vez de dejar el icono roto del navegador.
const imageBroken = ref(false);

const escapeHtml = (value: string) =>
    value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

// El texto viene tal cual de la red social: se escapa TODO y solo después se
// insertan las ligas y los hashtags, para poder usar v-html sin abrir la
// puerta a HTML ajeno.
const formattedMessage = computed(() => {
    let text = escapeHtml((props.post.message || props.post.excerpt).trim());

    text = text.replace(
        /(https?:\/\/[^\s<]+)/g,
        '<a href="$1" target="_blank" rel="noopener" class="break-all text-primary underline decoration-primary/40 underline-offset-2">$1</a>',
    );

    return text.replace(
        /(^|\s)(#[\p{L}\p{N}_]+)/gu,
        '$1<span class="font-medium text-primary">$2</span>',
    );
});

const cards = computed(() => [
    {
        label: 'Comentarios',
        value: props.stats.total,
        icon: 'MessageSquareText' as const,
        tone: 'primary',
    },
    {
        label: 'Por atender',
        value: props.stats.pendientes,
        icon: 'TriangleAlert' as const,
        tone: 'warning',
    },
    {
        label: 'Con interés de compra',
        value: props.stats.compras,
        icon: 'Sparkles' as const,
        tone: 'success',
    },
    {
        label: 'Conversaciones abiertas',
        value: props.stats.conversaciones,
        icon: 'MessagesSquare' as const,
        tone: 'info',
    },
]);

// Bordes de la tira de resumen: 2x2 en móvil, 1x4 desde lg.
const cellBorders = [
    '',
    'border-l',
    'border-t lg:border-l lg:border-t-0',
    'border-l border-t lg:border-t-0',
];

// ── Filtro de la lista ──
const filter = ref<string>('todos');

const filteredComments = computed(() => {
    if (filter.value === 'todos') return props.comments;
    if (filter.value === 'pendientes') {
        return props.comments.filter((comment) =>
            ['nuevo', 'pendiente_staff'].includes(comment.status),
        );
    }
    return props.comments.filter(
        (comment) => comment.classification === filter.value,
    );
});

// ── Acciones ──
const busy = ref(false);
const replyDialog = ref(false);
const privateDialog = ref(false);
const activeComment = ref<Comment | null>(null);
const draft = ref('');

function openReply(comment: Comment) {
    activeComment.value = comment;
    draft.value = comment.public_reply_text ?? '';
    replyDialog.value = true;
}

function openPrivate(comment: Comment) {
    activeComment.value = comment;
    draft.value = '';
    privateDialog.value = true;
}

// Dos funciones y no una parametrizada: en las plantillas las refs se
// desenvuelven, así que pasar el diálogo como argumento mandaría un booleano
// suelto y la ventana nunca cerraría.
function sendReply() {
    submit('tenant.social.comments.reply', () => (replyDialog.value = false));
}

function sendPrivate() {
    submit(
        'tenant.social.comments.private',
        () => (privateDialog.value = false),
    );
}

function submit(routeName: string, close: () => void) {
    if (!activeComment.value || draft.value.trim() === '') return;
    busy.value = true;
    router.post(
        route(routeName, activeComment.value.id),
        { texto: draft.value },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = false;
                close();
            },
        },
    );
}

function toggleHidden(comment: Comment) {
    if (busy.value) return;
    busy.value = true;
    router.patch(
        route('tenant.social.comments.hide', comment.id),
        { oculto: !comment.hidden, motivo: 'decisión del personal' },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function rerun(comment: Comment) {
    if (busy.value) return;
    busy.value = true;
    router.post(
        route('tenant.social.comments.rerun', comment.id),
        {},
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <RazeLayout title="Publicación">
        <div class="mt-2 grid grid-cols-12 gap-5">
            <!-- Encabezado: la publicación completa, imagen a un costado -->
            <div class="col-span-12">
                <div class="box box--stacked p-4 sm:p-5">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <a
                                :href="route('tenant.social')"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200/80 bg-white text-slate-500 shadow-sm transition hover:bg-slate-100 dark:border-darkmode-400 dark:bg-transparent dark:hover:bg-darkmode-400"
                                title="Volver a redes sociales"
                            >
                                <Lucide icon="ArrowLeft" class="h-4 w-4" />
                            </a>
                            <div
                                class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500"
                            >
                                <span
                                    class="font-medium text-slate-600 dark:text-slate-300"
                                >
                                    {{ post.network_label }}
                                </span>
                                <span v-if="post.published_label">
                                    {{ post.published_label }}
                                </span>
                                <span
                                    v-if="post.last_synced_at"
                                    class="text-slate-400"
                                    title="Última sincronización de comentarios"
                                >
                                    Actualizado {{ post.last_synced_at }}
                                </span>
                            </div>
                        </div>
                        <Button
                            v-if="post.permalink"
                            as="a"
                            :href="post.permalink"
                            target="_blank"
                            rel="noopener"
                            variant="outline-primary"
                            class="shrink-0 rounded-[0.5rem] bg-white text-xs"
                        >
                            <Lucide
                                icon="ExternalLink"
                                class="mr-1.5 h-3.5 w-3.5"
                            />
                            Ver en la red
                        </Button>
                    </div>

                    <h1 class="sr-only">
                        Publicación de {{ post.network_label }}
                    </h1>

                    <div
                        class="mt-4 flex flex-col gap-4 md:flex-row md:items-start"
                    >
                        <component
                            :is="post.permalink ? 'a' : 'div'"
                            v-if="post.media_url && !imageBroken"
                            :href="post.permalink ?? undefined"
                            :target="post.permalink ? '_blank' : undefined"
                            :rel="post.permalink ? 'noopener' : undefined"
                            class="block w-full shrink-0 md:w-72"
                            :title="
                                post.permalink
                                    ? 'Abrir en la red social'
                                    : undefined
                            "
                        >
                            <img
                                :src="post.media_url"
                                alt=""
                                decoding="async"
                                class="aspect-[4/3] w-full rounded-[0.5rem] object-cover md:aspect-square"
                                @error="imageBroken = true"
                            />
                        </component>
                        <div
                            class="min-w-0 flex-1 md:max-h-72 md:overflow-y-auto md:pr-1"
                        >
                            <p
                                class="text-sm leading-relaxed whitespace-pre-line text-slate-700 dark:text-slate-200"
                                v-html="formattedMessage"
                            ></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resumen de la publicación: una sola tira con divisores -->
            <div class="col-span-12">
                <div class="box box--stacked grid grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(card, index) in cards"
                        :key="card.label"
                        class="flex items-center gap-3 border-slate-200/60 p-4 dark:border-darkmode-400"
                        :class="cellBorders[index]"
                    >
                        <div
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border"
                            :class="toneClass(card.tone)"
                        >
                            <Lucide :icon="card.icon" class="h-4 w-4" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-base leading-tight font-medium">
                                {{ card.value }}
                            </div>
                            <div class="truncate text-xs text-slate-500">
                                {{ card.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comentarios -->
            <div class="col-span-12">
                <div class="box box--stacked">
                    <div
                        class="flex flex-col gap-3 border-b border-slate-200/60 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5 sm:pb-4 dark:border-darkmode-400"
                    >
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-medium">Comentarios</h2>
                            <span
                                class="rounded-full border border-slate-200/70 px-2 py-0.5 text-xs text-slate-500 dark:border-darkmode-400"
                            >
                                {{ filteredComments.length }}
                            </span>
                        </div>
                        <FormSelect
                            v-model="filter"
                            class="w-full text-xs sm:w-48"
                            aria-label="Filtrar comentarios"
                        >
                            <option value="todos">Todos</option>
                            <option value="pendientes">Por atender</option>
                            <option value="compra">Interés de compra</option>
                            <option value="pregunta">Preguntas</option>
                            <option value="queja">Quejas</option>
                            <option value="elogio">Elogios</option>
                            <option value="spam">Spam</option>
                        </FormSelect>
                    </div>

                    <div
                        v-if="filteredComments.length === 0"
                        class="p-10 text-center text-sm text-slate-500"
                    >
                        No hay comentarios que mostrar con este filtro.
                    </div>

                    <template v-else>
                        <div
                            v-for="comment in filteredComments"
                            :key="comment.id"
                            class="border-b border-slate-200/60 px-4 py-4 last:border-b-0 sm:px-5 dark:border-darkmode-400"
                        >
                            <div
                                class="flex items-start gap-3"
                                :class="comment.hidden ? 'opacity-60' : ''"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-medium text-slate-500 dark:bg-darkmode-400 dark:text-slate-300"
                                >
                                    {{ initial(comment.author_name) }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1"
                                    >
                                        <div
                                            class="flex min-w-0 flex-wrap items-baseline gap-x-2"
                                        >
                                            <span class="text-xs font-medium">
                                                {{
                                                    comment.author_name ??
                                                    'Usuario'
                                                }}
                                            </span>
                                            <span
                                                v-if="comment.commented_label"
                                                class="text-xs text-slate-400"
                                            >
                                                {{ comment.commented_label }}
                                            </span>
                                        </div>
                                        <div
                                            class="flex flex-wrap items-center gap-1.5 text-xs"
                                        >
                                            <span
                                                v-if="
                                                    comment.classification_label
                                                "
                                                class="rounded-full border px-2 py-0.5"
                                                :class="
                                                    toneClass(
                                                        classificationTone[
                                                            comment.classification ??
                                                                'pregunta'
                                                        ] ?? 'dark',
                                                    )
                                                "
                                            >
                                                {{
                                                    comment.classification_label
                                                }}
                                            </span>
                                            <span
                                                class="rounded-full border px-2 py-0.5"
                                                :class="
                                                    toneClass(
                                                        statusTone[
                                                            comment.status
                                                        ] ?? 'dark',
                                                    )
                                                "
                                            >
                                                {{ comment.status_label }}
                                            </span>
                                        </div>
                                    </div>

                                    <p
                                        class="mt-1 text-sm leading-relaxed text-slate-600 dark:text-slate-300"
                                    >
                                        {{ comment.body }}
                                    </p>

                                    <div
                                        v-if="comment.public_reply_text"
                                        class="mt-2 border-l-2 border-success/50 pl-3 text-xs text-slate-500"
                                    >
                                        <span
                                            class="font-medium text-slate-600 dark:text-slate-300"
                                        >
                                            Respuesta publicada
                                        </span>
                                        <span v-if="comment.public_replied_at">
                                            · {{ comment.public_replied_at }}
                                        </span>
                                        <p class="mt-0.5 leading-relaxed">
                                            {{ comment.public_reply_text }}
                                        </p>
                                    </div>

                                    <div
                                        v-if="comment.private_reply_sent_at"
                                        class="mt-1.5 flex items-center gap-1.5 text-xs text-success"
                                    >
                                        <Lucide icon="Send" class="h-3 w-3" />
                                        Mensaje privado enviado
                                        {{ comment.private_reply_sent_at }}
                                    </div>

                                    <div
                                        v-if="comment.private_reply_error"
                                        class="mt-1.5 flex items-center gap-1.5 text-xs text-danger"
                                    >
                                        <Lucide
                                            icon="TriangleAlert"
                                            class="h-3 w-3"
                                        />
                                        {{ comment.private_reply_error }}
                                    </div>

                                    <div
                                        v-if="comment.hidden_reason"
                                        class="mt-1.5 text-xs text-slate-500"
                                    >
                                        Oculto: {{ comment.hidden_reason }}
                                    </div>

                                    <div
                                        v-if="comment.deleted_from_network"
                                        class="mt-1.5 text-xs text-slate-500"
                                    >
                                        El autor borró este comentario en la red
                                        social.
                                    </div>

                                    <div
                                        class="mt-2.5 flex flex-wrap items-center gap-1.5"
                                    >
                                        <Button
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white px-2.5 text-xs"
                                            :disabled="busy"
                                            @click="openReply(comment)"
                                        >
                                            <Lucide
                                                icon="MessageSquareText"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Responder
                                        </Button>
                                        <Button
                                            v-if="comment.can_private_reply"
                                            variant="outline-secondary"
                                            class="h-8 rounded-[0.5rem] bg-white px-2.5 text-xs"
                                            :disabled="busy"
                                            @click="openPrivate(comment)"
                                        >
                                            <Lucide
                                                icon="Send"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Mensaje privado
                                        </Button>
                                        <Button
                                            v-if="comment.conversation_uuid"
                                            as="a"
                                            :href="route('tenant.inbox')"
                                            variant="outline-primary"
                                            class="h-8 rounded-[0.5rem] bg-white px-2.5 text-xs"
                                        >
                                            <Lucide
                                                icon="MessagesSquare"
                                                class="mr-1.5 h-3.5 w-3.5"
                                            />
                                            Ver conversación
                                        </Button>
                                        <Menu>
                                            <Menu.Button
                                                class="flex h-8 w-8 items-center justify-center rounded-[0.5rem] border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:bg-slate-100 dark:border-darkmode-400 dark:bg-transparent dark:hover:bg-darkmode-400"
                                                title="Más acciones"
                                            >
                                                <Lucide
                                                    icon="Ellipsis"
                                                    class="h-4 w-4"
                                                />
                                            </Menu.Button>
                                            <Menu.Items class="w-48">
                                                <Menu.Item
                                                    as="button"
                                                    type="button"
                                                    @click="
                                                        toggleHidden(comment)
                                                    "
                                                >
                                                    <Lucide
                                                        :icon="
                                                            comment.hidden
                                                                ? 'Eye'
                                                                : 'EyeOff'
                                                        "
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    {{
                                                        comment.hidden
                                                            ? 'Mostrar'
                                                            : 'Ocultar'
                                                    }}
                                                </Menu.Item>
                                                <Menu.Item
                                                    as="button"
                                                    type="button"
                                                    @click="rerun(comment)"
                                                >
                                                    <Lucide
                                                        icon="Sparkles"
                                                        class="mr-2 h-4 w-4"
                                                    />
                                                    Revisar con IA
                                                </Menu.Item>
                                            </Menu.Items>
                                        </Menu>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Responder en público -->
        <Dialog :open="replyDialog" @close="replyDialog = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="MessageSquareText" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">
                        Responder en público
                    </h2>
                </Dialog.Title>
                <Dialog.Description>
                    <p class="mb-2 text-xs text-slate-500">
                        Lo verá cualquiera en el hilo del comentario: sin
                        precios ni datos personales.
                    </p>
                    <FormTextarea
                        v-model="draft"
                        rows="4"
                        placeholder="Con gusto te mandamos la informacion por privado."
                    />
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="replyDialog = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-24"
                        :disabled="busy || draft.trim() === ''"
                        @click="sendReply"
                    >
                        Publicar
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>

        <!-- Mensaje privado -->
        <Dialog :open="privateDialog" @close="privateDialog = false">
            <Dialog.Panel>
                <Dialog.Title>
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-success/10 bg-success/10 text-success"
                    >
                        <Lucide icon="Send" class="h-5 w-5" />
                    </div>
                    <h2 class="ml-3 text-base font-medium">Mensaje privado</h2>
                </Dialog.Title>
                <Dialog.Description>
                    <p class="mb-2 text-xs text-slate-500">
                        Solo se puede mandar uno por comentario y dentro de los
                        7 días. La conversación queda en la bandeja.
                    </p>
                    <FormTextarea
                        v-model="draft"
                        rows="4"
                        placeholder="Hola, vimos tu comentario. Te ayudo con tarifas y disponibilidad."
                    />
                </Dialog.Description>
                <Dialog.Footer>
                    <Button
                        variant="outline-secondary"
                        class="mr-2 w-24"
                        @click="privateDialog = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        variant="primary"
                        class="w-24"
                        :disabled="busy || draft.trim() === ''"
                        @click="sendPrivate"
                    >
                        Enviar
                    </Button>
                </Dialog.Footer>
            </Dialog.Panel>
        </Dialog>
    </RazeLayout>
</template>
