<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import Lucide from '@/components/Base/Lucide';

interface ChatMessage {
    id: number;
    from: 'me' | 'bot' | 'staff';
    body: string;
    at: string;
}

const props = defineProps<{
    hotel: { name: string; phone: string | null };
    botActive: boolean;
}>();

const uuid = ref<string | null>(null);
const messages = ref<ChatMessage[]>([]);
const draft = ref('');
const sending = ref(false);
const typing = ref(false);
const listRef = ref<HTMLElement | null>(null);
let poller: ReturnType<typeof setInterval> | null = null;

const storageKey = 'kw_webchat_uuid';

async function scrollBottom() {
    await nextTick();
    listRef.value?.scrollTo({
        top: listRef.value.scrollHeight,
        behavior: 'smooth',
    });
}

async function loadMessages() {
    if (!uuid.value) return;
    try {
        const { data } = await axios.get(`/api/webchat/${uuid.value}/messages`);
        if (data.messages.length !== messages.value.length) {
            messages.value = data.messages;
            scrollBottom();
        }
    } catch {
        // Sesión inválida (p. ej. otra BD): empezar de cero.
        localStorage.removeItem(storageKey);
        uuid.value = null;
        await start();
    }
}

async function start() {
    const { data } = await axios.post('/api/webchat/start', {});
    uuid.value = data.uuid;
    localStorage.setItem(storageKey, data.uuid);
    await loadMessages();
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value || !uuid.value) return;
    sending.value = true;
    draft.value = '';
    messages.value.push({
        id: Date.now(),
        from: 'me',
        body,
        at: new Date().toLocaleTimeString('es-MX', {
            hour: '2-digit',
            minute: '2-digit',
        }),
    });
    scrollBottom();
    typing.value = props.botActive;
    try {
        const { data } = await axios.post(
            `/api/webchat/${uuid.value}/messages`,
            { body },
        );
        if (data.reply) messages.value.push(data.reply);
        else if (!data.bot_enabled) await loadMessages();
    } catch {
        messages.value.push({
            id: Date.now() + 1,
            from: 'bot',
            body: 'Ups, algo falló. Intenta de nuevo en un momento.',
            at: '',
        });
    } finally {
        typing.value = false;
        sending.value = false;
        scrollBottom();
    }
}

onMounted(async () => {
    const saved = localStorage.getItem(storageKey);
    if (saved) {
        uuid.value = saved;
        await loadMessages();
    } else {
        await start();
    }
    // Polling: recoge respuestas del staff (handoff) sin recargar.
    poller = setInterval(loadMessages, 8000);
});

onBeforeUnmount(() => {
    if (poller) clearInterval(poller);
});
</script>

<template>
    <Head :title="`Chat · ${hotel.name}`" />
    <div
        class="flex min-h-screen items-center justify-center bg-linear-to-b from-theme-1 to-theme-2 p-0 sm:p-6"
    >
        <div
            class="flex h-screen w-full flex-col overflow-hidden bg-white shadow-2xl sm:h-[85vh] sm:max-w-md sm:rounded-2xl"
        >
            <!-- Header -->
            <div
                class="flex items-center gap-3 bg-linear-to-r from-theme-1 to-theme-2 px-5 py-4"
            >
                <div
                    class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10 text-white"
                >
                    <Lucide icon="Bot" class="h-6 w-6" />
                    <span
                        class="absolute -right-0.5 -bottom-0.5 h-3 w-3 rounded-full border-2 border-theme-1"
                        :class="botActive ? 'bg-success' : 'bg-warning'"
                    />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate font-medium text-white">
                        {{ hotel.name }}
                    </div>
                    <div class="text-xs text-white/70">
                        {{
                            botActive
                                ? 'Asistente en línea · respuesta inmediata'
                                : 'Te responde nuestro equipo'
                        }}
                    </div>
                </div>
                <a
                    v-if="hotel.phone"
                    :href="`tel:${hotel.phone}`"
                    title="Llamar al hotel"
                    class="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                >
                    <Lucide icon="Phone" class="h-4 w-4" />
                </a>
            </div>

            <!-- Mensajes -->
            <div
                ref="listRef"
                class="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-5"
            >
                <template v-for="m in messages" :key="m.id">
                    <div
                        class="flex"
                        :class="
                            m.from === 'me' ? 'justify-end' : 'justify-start'
                        "
                    >
                        <div
                            class="flex max-w-[85%] items-end gap-2"
                            :class="m.from === 'me' ? 'flex-row-reverse' : ''"
                        >
                            <div
                                v-if="m.from !== 'me'"
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    m.from === 'staff'
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-200 text-slate-500'
                                "
                            >
                                <Lucide
                                    :icon="m.from === 'staff' ? 'User' : 'Bot'"
                                    class="h-3.5 w-3.5"
                                />
                            </div>
                            <div>
                                <div
                                    class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-line"
                                    :class="
                                        m.from === 'me'
                                            ? 'rounded-br-md bg-linear-to-r from-theme-1 to-theme-2 text-white'
                                            : 'rounded-bl-md border border-slate-200 bg-white text-slate-700 shadow-sm'
                                    "
                                >
                                    {{ m.body }}
                                </div>
                                <div
                                    class="mt-1 text-[10px] text-slate-400"
                                    :class="m.from === 'me' ? 'text-right' : ''"
                                >
                                    {{ m.at }}
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Escribiendo… -->
                <div v-if="typing" class="flex items-end gap-2">
                    <div
                        class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-200 text-slate-500"
                    >
                        <Lucide icon="Bot" class="h-3.5 w-3.5" />
                    </div>
                    <div
                        class="rounded-2xl rounded-bl-md border border-slate-200 bg-white px-4 py-3 shadow-sm"
                    >
                        <span class="flex gap-1">
                            <span
                                class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400"
                                style="animation-delay: 0ms"
                            />
                            <span
                                class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400"
                                style="animation-delay: 150ms"
                            />
                            <span
                                class="h-1.5 w-1.5 animate-bounce rounded-full bg-slate-400"
                                style="animation-delay: 300ms"
                            />
                        </span>
                    </div>
                </div>
            </div>

            <!-- Composer -->
            <div class="border-t border-slate-200 bg-white p-3">
                <div class="flex items-end gap-2">
                    <textarea
                        v-model="draft"
                        rows="1"
                        placeholder="Escribe tu mensaje…"
                        class="max-h-28 flex-1 resize-none rounded-xl border border-slate-200 px-3.5 py-2.5 text-sm transition outline-none focus:border-theme-2 focus:ring-2 focus:ring-theme-2/20"
                        @keydown.enter.exact.prevent="send"
                    />
                    <button
                        type="button"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-linear-to-r from-theme-1 to-theme-2 text-white shadow-md transition hover:opacity-90 disabled:opacity-40"
                        :disabled="sending || !draft.trim()"
                        @click="send"
                    >
                        <Lucide icon="SendHorizontal" class="h-4 w-4" />
                    </button>
                </div>
                <p class="mt-2 text-center text-[10px] text-slate-400">
                    El asistente puede apartar habitaciones; el hotel confirma
                    tu reserva. · Impulsado por KuiraReserve
                </p>
            </div>
        </div>
    </div>
</template>
