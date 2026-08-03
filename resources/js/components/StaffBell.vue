<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { Slideover } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';

/**
 * Campana del panel: avisa desde CUALQUIER pantalla, a diferencia del tono
 * de la bandeja que solo suena con esa página abierta.
 *
 * (El NotificationsPanel que traía el theme colgaba de datos falsos que ni
 * siquiera existen en el proyecto, así que este lo sustituye.)
 */
const props = defineProps<{ tenantId: string }>();

interface StaffNotice {
    id: number;
    type: string;
    title: string;
    body: string | null;
    url: string | null;
    read: boolean;
    at: string | null;
}

const open = ref(false);
const loading = ref(false);
const notices = ref<StaffNotice[]>([]);
const unread = ref(0);

const meta: Record<string, { icon: Icon; tone: string }> = {
    message: { icon: 'MessageCircle', tone: 'bg-primary/10 text-primary' },
    reservation: { icon: 'CalendarCheck', tone: 'bg-info/10 text-info' },
    payment: { icon: 'BadgeDollarSign', tone: 'bg-success/10 text-success' },
};

const metaFor = (type: string) =>
    meta[type] ?? { icon: 'Bell' as Icon, tone: 'bg-slate-100 text-slate-500' };

async function load() {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/staff-notifications');
        notices.value = data.notifications;
        unread.value = data.unread;
    } catch {
        /* la campana no debe tumbar la página si falla */
    } finally {
        loading.value = false;
    }
}

function openPanel() {
    open.value = true;
    load();
}

async function go(notice: StaffNotice) {
    open.value = false;

    if (!notice.read) {
        notice.read = true;
        unread.value = Math.max(0, unread.value - 1);
        axios.post(`/api/staff-notifications/${notice.id}/read`).catch(() => {
            /* si falla, se marcará al volver a abrir */
        });
    }

    if (notice.url) router.visit(notice.url);
}

async function readAll() {
    unread.value = 0;
    notices.value = notices.value.map((n) => ({ ...n, read: true }));
    try {
        await axios.post('/api/staff-notifications/read-all');
    } catch {
        load(); // el servidor manda: si falló, recuperar la verdad
    }
}

// En vivo: el contador se mueve sin recargar ni preguntar cada rato.
useEcho<{ id: number; title: string }>(
    `tenant.${props.tenantId}.staff`,
    '.staff.notified',
    () => {
        unread.value += 1;
        if (open.value) load();
    },
);

onMounted(load);
</script>

<template>
    <div>
        <button
            type="button"
            class="relative rounded-full p-2 hover:bg-slate-100 dark:hover:bg-darkmode-400"
            :title="
                unread > 0
                    ? `${unread} aviso${unread === 1 ? '' : 's'} sin leer`
                    : 'Avisos'
            "
            @click="openPanel"
        >
            <Lucide icon="Bell" class="h-[18px] w-[18px]" />
            <span
                v-if="unread > 0"
                class="absolute top-0.5 right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-semibold text-white"
                >{{ unread > 9 ? '9+' : unread }}</span
            >
        </button>

        <Slideover :open="open" @close="open = false">
            <Slideover.Panel class="w-80">
                <Slideover.Title class="flex items-center justify-between p-5">
                    <h2 class="mr-auto text-base font-medium">Avisos</h2>
                    <button
                        v-if="unread > 0"
                        type="button"
                        class="text-xs font-medium text-primary hover:underline"
                        @click="readAll"
                    >
                        Marcar todo como leído
                    </button>
                </Slideover.Title>

                <Slideover.Description class="p-0">
                    <div
                        v-if="notices.length"
                        class="divide-y divide-slate-100 dark:divide-darkmode-400"
                    >
                        <button
                            v-for="n in notices"
                            :key="n.id"
                            type="button"
                            class="flex w-full items-start gap-3 px-5 py-3.5 text-left transition hover:bg-slate-50 dark:hover:bg-darkmode-600"
                            :class="n.read ? 'opacity-60' : ''"
                            @click="go(n)"
                        >
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                :class="metaFor(n.type).tone"
                            >
                                <Lucide
                                    :icon="metaFor(n.type).icon"
                                    class="h-4 w-4"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ n.title }}</span
                                >
                                <span
                                    v-if="n.body"
                                    class="mt-0.5 block text-xs text-slate-500"
                                    >{{ n.body }}</span
                                >
                                <span
                                    class="mt-1 block text-[11px] text-slate-400"
                                    >{{ n.at }}</span
                                >
                            </span>
                            <span
                                v-if="!n.read"
                                class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary"
                            />
                        </button>
                    </div>

                    <div
                        v-else-if="!loading"
                        class="flex flex-col items-center gap-3 px-5 py-16 text-center"
                    >
                        <span
                            class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-darkmode-400"
                        >
                            <Lucide icon="Bell" class="h-6 w-6" />
                        </span>
                        <p class="text-sm text-slate-500">
                            Sin avisos por ahora. Aquí llegan los mensajes
                            nuevos, las reservas que entran solas y los
                            comprobantes por verificar.
                        </p>
                    </div>
                </Slideover.Description>
            </Slideover.Panel>
        </Slideover>
    </div>
</template>
