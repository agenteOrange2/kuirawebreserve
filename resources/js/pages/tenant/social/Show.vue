<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormSelect, FormTextarea } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
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
    submit('tenant.social.comments.private', () => (privateDialog.value = false));
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
    busy.value = true;
    router.patch(
        route('tenant.social.comments.hide', comment.id),
        { oculto: !comment.hidden, motivo: 'decisión del personal' },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function rerun(comment: Comment) {
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
            <!-- Encabezado -->
            <div class="col-span-12">
                <div
                    class="box box--stacked flex flex-col gap-4 p-5 md:flex-row md:items-start md:justify-between"
                >
                    <div class="flex min-w-0 gap-4">
                        <img
                            v-if="post.media_url"
                            :src="post.media_url"
                            alt=""
                            class="h-16 w-16 shrink-0 rounded-[0.5rem] object-cover sm:h-20 sm:w-20"
                        />
                        <div
                            v-else
                            class="flex h-16 w-16 shrink-0 items-center justify-center rounded-[0.5rem] bg-slate-100 text-slate-400 dark:bg-darkmode-400 sm:h-20 sm:w-20"
                        >
                            <Lucide icon="Image" class="h-6 w-6" />
                        </div>
                        <div class="min-w-0">
                            <div
                                class="flex flex-wrap items-center gap-2 text-xs text-slate-500"
                            >
                                <span
                                    class="rounded-full border border-slate-200/70 px-2 py-0.5 dark:border-darkmode-400"
                                >
                                    {{ post.network_label }}
                                </span>
                                <span v-if="post.published_label">
                                    {{ post.published_label }}
                                </span>
                            </div>
                            <h1
                                class="mt-2 text-base leading-relaxed sm:text-lg"
                            >
                                {{ post.message || post.excerpt }}
                            </h1>
                        </div>
                    </div>
                    <div
                        class="grid w-full grid-cols-2 gap-2 md:flex md:w-auto md:shrink-0 md:flex-wrap md:items-center"
                    >
                        <Button
                            as="a"
                            :href="route('tenant.social')"
                            variant="outline-secondary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4" />
                            Volver
                        </Button>
                        <Button
                            v-if="post.permalink"
                            as="a"
                            :href="post.permalink"
                            target="_blank"
                            rel="noopener"
                            variant="outline-primary"
                            class="min-h-11 rounded-[0.5rem] bg-white"
                        >
                            <Lucide icon="ExternalLink" class="mr-2 h-4 w-4" />
                            Ver en la red
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Resumen de la publicación -->
            <div
                v-for="card in [
                    {
                        label: 'Comentarios',
                        value: stats.total,
                        icon: 'MessageSquareText' as const,
                        tone: 'primary',
                    },
                    {
                        label: 'Por atender',
                        value: stats.pendientes,
                        icon: 'TriangleAlert' as const,
                        tone: 'warning',
                    },
                    {
                        label: 'Con interés de compra',
                        value: stats.compras,
                        icon: 'Sparkles' as const,
                        tone: 'success',
                    },
                    {
                        label: 'Conversaciones abiertas',
                        value: stats.conversaciones,
                        icon: 'MessagesSquare' as const,
                        tone: 'info',
                    },
                ]"
                :key="card.label"
                class="col-span-6 xl:col-span-3"
            >
                <div
                    class="box box--stacked flex h-full items-center gap-3 p-4"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="toneClass(card.tone)"
                    >
                        <Lucide :icon="card.icon" class="h-4 w-4" />
                    </div>
                    <div>
                        <div class="text-lg font-medium">{{ card.value }}</div>
                        <div class="text-xs text-slate-500">
                            {{ card.label }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Comentarios -->
            <div class="col-span-12">
                <div class="box box--stacked p-5">
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <h2 class="text-base font-medium">Comentarios</h2>
                        <FormSelect
                            v-model="filter"
                            class="w-full sm:w-56"
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
                        class="mt-5 rounded-[0.5rem] border border-dashed border-slate-300/70 p-8 text-center text-sm text-slate-500 dark:border-darkmode-400"
                    >
                        No hay comentarios que mostrar con este filtro.
                    </div>

                    <div v-else class="mt-4 flex flex-col gap-4">
                        <div
                            v-for="comment in filteredComments"
                            :key="comment.id"
                            class="rounded-[0.5rem] border border-slate-200/70 p-4 dark:border-darkmode-400"
                            :class="comment.hidden ? 'opacity-60' : ''"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <div class="text-sm font-medium">
                                        {{ comment.author_name ?? 'Usuario' }}
                                    </div>
                                    <div
                                        v-if="comment.commented_label"
                                        class="text-xs text-slate-500"
                                    >
                                        {{ comment.commented_label }}
                                    </div>
                                </div>
                                <div
                                    class="flex flex-wrap items-center gap-1.5 text-xs"
                                >
                                    <span
                                        v-if="comment.classification_label"
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
                                        {{ comment.classification_label }}
                                    </span>
                                    <span
                                        class="rounded-full border px-2 py-0.5"
                                        :class="
                                            toneClass(
                                                statusTone[comment.status] ??
                                                    'dark',
                                            )
                                        "
                                    >
                                        {{ comment.status_label }}
                                    </span>
                                </div>
                            </div>

                            <p
                                class="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-300"
                            >
                                {{ comment.body }}
                            </p>

                            <div
                                v-if="comment.public_reply_text"
                                class="mt-3 rounded-[0.4rem] bg-slate-100/70 p-3 text-xs text-slate-600 dark:bg-darkmode-400 dark:text-slate-300"
                            >
                                <span class="font-medium">
                                    Respuesta publicada
                                </span>
                                <span v-if="comment.public_replied_at">
                                    ({{ comment.public_replied_at }})
                                </span>
                                : {{ comment.public_reply_text }}
                            </div>

                            <div
                                v-if="comment.private_reply_sent_at"
                                class="mt-2 flex items-center gap-1.5 text-xs text-success"
                            >
                                <Lucide icon="Send" class="h-3.5 w-3.5" />
                                Mensaje privado enviado
                                {{ comment.private_reply_sent_at }}
                            </div>

                            <div
                                v-if="comment.private_reply_error"
                                class="mt-2 flex items-center gap-1.5 text-xs text-danger"
                            >
                                <Lucide
                                    icon="TriangleAlert"
                                    class="h-3.5 w-3.5"
                                />
                                {{ comment.private_reply_error }}
                            </div>

                            <div
                                v-if="comment.hidden_reason"
                                class="mt-2 text-xs text-slate-500"
                            >
                                Oculto: {{ comment.hidden_reason }}
                            </div>

                            <div
                                v-if="comment.deleted_from_network"
                                class="mt-2 text-xs text-slate-500"
                            >
                                El autor borró este comentario en la red
                                social.
                            </div>

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <Button
                                    variant="outline-secondary"
                                    class="min-h-10 rounded-[0.5rem] bg-white text-xs"
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
                                    class="min-h-10 rounded-[0.5rem] bg-white text-xs"
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
                                    variant="outline-secondary"
                                    class="min-h-10 rounded-[0.5rem] bg-white text-xs"
                                    :disabled="busy"
                                    @click="toggleHidden(comment)"
                                >
                                    <Lucide
                                        :icon="
                                            comment.hidden ? 'Eye' : 'EyeOff'
                                        "
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{ comment.hidden ? 'Mostrar' : 'Ocultar' }}
                                </Button>
                                <Button
                                    variant="outline-secondary"
                                    class="min-h-10 rounded-[0.5rem] bg-white text-xs"
                                    :disabled="busy"
                                    @click="rerun(comment)"
                                >
                                    <Lucide
                                        icon="Sparkles"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Revisar con IA
                                </Button>
                                <Button
                                    v-if="comment.conversation_uuid"
                                    as="a"
                                    :href="route('tenant.inbox')"
                                    variant="outline-primary"
                                    class="min-h-10 rounded-[0.5rem] bg-white text-xs"
                                >
                                    <Lucide
                                        icon="MessagesSquare"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Ver conversación
                                </Button>
                            </div>
                        </div>
                    </div>
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
