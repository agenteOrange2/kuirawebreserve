<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import axios from 'axios';
import { onMounted, ref } from 'vue';
import { Slideover } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { Icon } from '@/components/Base/Lucide';
import { usePushNotifications } from '@/composables/usePushNotifications';

/**
 * Campana del panel: avisa desde CUALQUIER pantalla, a diferencia del tono
 * de la bandeja que solo suena con esa página abierta.
 *
 * (El NotificationsPanel que traía el theme colgaba de datos falsos que ni
 * siquiera existen en el proyecto, así que este lo sustituye.)
 */
const props = defineProps<{ tenantId: string; vapidKey?: string | null }>();

// Push real: avisa con el panel CERRADO. Se ofrece aquí porque es donde el
// staff ya viene a ver sus avisos.
const push = usePushNotifications(props.vapidKey ?? null);

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
    survey: { icon: 'Frown', tone: 'bg-danger/10 text-danger' },
    menu: { icon: 'UtensilsCrossed', tone: 'bg-warning/10 text-warning' },
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

async function remove(notice: StaffNotice) {
    notices.value = notices.value.filter((n) => n.id !== notice.id);
    if (!notice.read) unread.value = Math.max(0, unread.value - 1);
    try {
        await axios.delete(`/api/staff-notifications/${notice.id}`);
    } catch {
        load(); // el servidor manda: si falló, recuperar la verdad
    }
}

function goToAll() {
    open.value = false;
    router.visit('/bandeja/avisos');
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
                    <!-- Push: lo único que avisa con el panel cerrado -->
                    <div
                        v-if="push.supported.value"
                        class="border-b border-slate-100 px-5 py-4 dark:border-darkmode-400"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    push.subscribed.value
                                        ? 'bg-success/10 text-success'
                                        : 'bg-slate-100 text-slate-400 dark:bg-darkmode-400'
                                "
                            >
                                <Lucide
                                    :icon="
                                        push.subscribed.value
                                            ? 'BellRing'
                                            : 'BellOff'
                                    "
                                    class="h-4 w-4"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium">
                                    {{
                                        push.subscribed.value
                                            ? 'Avisos activos en este dispositivo'
                                            : 'Recibir avisos con el panel cerrado'
                                    }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{
                                        push.subscribed.value
                                            ? 'Te llegan aunque no tengas el panel abierto.'
                                            : 'Actívalos y te avisamos aunque cierres el panel.'
                                    }}
                                </p>
                                <button
                                    type="button"
                                    class="mt-2 text-xs font-medium hover:underline"
                                    :class="
                                        push.subscribed.value
                                            ? 'text-slate-500'
                                            : 'text-primary'
                                    "
                                    :disabled="push.busy.value"
                                    @click="
                                        push.subscribed.value
                                            ? push.unsubscribe()
                                            : push.subscribe()
                                    "
                                >
                                    {{
                                        push.busy.value
                                            ? 'Un momento…'
                                            : push.subscribed.value
                                              ? 'Desactivar en este dispositivo'
                                              : 'Activar en este dispositivo'
                                    }}
                                </button>
                                <p
                                    v-if="push.error.value"
                                    class="mt-1.5 text-xs text-danger"
                                >
                                    {{ push.error.value }}
                                </p>
                            </div>
                        </div>

                        <!-- Dispositivos conectados: se pueden quitar desde
                             aquí, que es lo único que sirve cuando el aparato
                             se perdió y ya no lo tienes a la mano. -->
                        <div
                            v-if="push.devices.value.length"
                            class="mt-3 border-t border-slate-100 pt-3 dark:border-darkmode-400"
                        >
                            <p
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Dispositivos conectados
                            </p>
                            <div class="mt-2 space-y-1.5">
                                <div
                                    v-for="device in push.devices.value"
                                    :key="device.id"
                                    class="flex items-center gap-2 text-xs"
                                >
                                    <Lucide
                                        icon="Smartphone"
                                        class="h-3.5 w-3.5 shrink-0 text-slate-400"
                                    />
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ device.name }}
                                        <span
                                            v-if="device.last_used_at"
                                            class="text-slate-400"
                                            >· {{ device.last_used_at }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        title="Quitar este dispositivo"
                                        class="shrink-0 text-slate-400 transition hover:text-danger"
                                        @click="push.removeDevice(device.id)"
                                    >
                                        <Lucide icon="X" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="notices.length"
                        class="divide-y divide-slate-100 dark:divide-darkmode-400"
                    >
                        <div
                            v-for="n in notices"
                            :key="n.id"
                            class="flex items-start gap-3 px-5 py-3.5 transition hover:bg-slate-50 dark:hover:bg-darkmode-600"
                            :class="n.read ? 'opacity-60' : ''"
                        >
                            <button
                                type="button"
                                class="flex min-w-0 flex-1 items-start gap-3 text-left"
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
                                    <span class="flex items-center gap-2">
                                        <span
                                            class="truncate text-sm font-medium"
                                            >{{ n.title }}</span
                                        >
                                        <span
                                            v-if="!n.read"
                                            class="h-2 w-2 shrink-0 rounded-full bg-primary"
                                        />
                                    </span>
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
                            </button>
                            <button
                                type="button"
                                title="Eliminar este aviso"
                                class="mt-1.5 shrink-0 rounded-md p-1 text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                @click="remove(n)"
                            >
                                <Lucide icon="X" class="h-3.5 w-3.5" />
                            </button>
                        </div>
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

                    <!-- La campana solo trae los últimos; el resto vive en
                         /bandeja/avisos con filtros y borrado en masa. -->
                    <div
                        class="border-t border-slate-100 px-5 py-3.5 dark:border-darkmode-400"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-1.5 text-sm font-medium text-primary hover:underline"
                            @click="goToAll"
                        >
                            Ver todos los avisos
                            <Lucide icon="ArrowRight" class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </Slideover.Description>
            </Slideover.Panel>
        </Slideover>
    </div>
</template>
