import axios from 'axios';
import { onMounted, ref } from 'vue';

/**
 * Notificaciones push del panel: avisan al staff aunque tenga el panel
 * CERRADO — es lo que la campana no puede hacer por sí sola.
 *
 * Necesita HTTPS (o localhost) y que el servidor tenga llaves VAPID.
 */

/**
 * La llave VAPID viaja en base64url; el navegador la pide en bytes. Se
 * devuelve un ArrayBuffer (no Uint8Array) porque es lo que declara
 * applicationServerKey.
 */
function urlBase64ToBuffer(base64: string): ArrayBuffer {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(normalized);
    const bytes = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes.buffer;
}

export interface PushDevice {
    id: number;
    name: string;
    last_used_at: string | null;
}

export function usePushNotifications(vapidKey: string | null) {
    const supported = ref(false);
    const subscribed = ref(false);
    const busy = ref(false);
    const error = ref<string | null>(null);
    const devices = ref<PushDevice[]>([]);

    function checkSupport(): boolean {
        return (
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window &&
            // Sin HTTPS el navegador ni siquiera registra el service worker.
            (window.isSecureContext ?? false) &&
            !!vapidKey
        );
    }

    async function currentSubscription(): Promise<PushSubscription | null> {
        if (!checkSupport()) return null;

        const registration = await navigator.serviceWorker.getRegistration();

        return (await registration?.pushManager.getSubscription()) ?? null;
    }

    async function loadDevices() {
        try {
            const { data } = await axios.get('/api/push-subscriptions');
            devices.value = data.devices;
        } catch {
            devices.value = [];
        }
    }

    async function refresh() {
        supported.value = checkSupport();
        if (!supported.value) return;

        subscribed.value = (await currentSubscription()) !== null;
        await loadDevices();
    }

    /**
     * Quita un dispositivo por id, para el caso en que ya no lo tienes a la
     * mano (se perdió el celular). Solo borra el registro del servidor: ese
     * aparato deja de recibir en cuanto el navegador lo confirme.
     */
    async function removeDevice(id: number) {
        try {
            await axios.delete('/api/push-subscriptions', { data: { id } });
            devices.value = devices.value.filter((d) => d.id !== id);
        } catch {
            error.value = 'No se pudo quitar el dispositivo.';
        }
    }

    async function subscribe() {
        if (!checkSupport() || busy.value) return;

        busy.value = true;
        error.value = null;
        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                error.value =
                    'El navegador bloqueó los avisos. Habilítalos en los permisos del sitio.';

                return;
            }

            const registration =
                await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                // Obligatorio en la práctica: los navegadores rechazan las
                // suscripciones que no muestran nada al usuario.
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToBuffer(vapidKey as string),
            });

            await axios.post('/api/push-subscriptions', subscription.toJSON());
            subscribed.value = true;
            await loadDevices();

            // Uno de prueba: que el staff vea con sus ojos que sí llega.
            await axios.post('/api/push-subscriptions/test');
        } catch (e: any) {
            error.value =
                e.response?.data?.message ??
                'No se pudieron activar los avisos en este dispositivo.';
        } finally {
            busy.value = false;
        }
    }

    async function unsubscribe() {
        if (busy.value) return;

        busy.value = true;
        error.value = null;
        try {
            const subscription = await currentSubscription();

            if (subscription) {
                await axios.delete('/api/push-subscriptions', {
                    data: { endpoint: subscription.endpoint },
                });
                await subscription.unsubscribe();
            }

            subscribed.value = false;
            await loadDevices();
        } catch {
            error.value = 'No se pudieron desactivar los avisos.';
        } finally {
            busy.value = false;
        }
    }

    onMounted(refresh);

    return {
        supported,
        subscribed,
        busy,
        error,
        devices,
        subscribe,
        unsubscribe,
        removeDevice,
    };
}
