import { onBeforeUnmount, ref } from 'vue';

/**
 * Aviso de mensaje nuevo en la bandeja: tono corto, notificación del
 * navegador y contador en el título de la pestaña.
 *
 * Sin esto, un mensaje que llegaba con la pestaña en segundo plano se
 * quedaba enfriando hasta que alguien volvía a mirar.
 */

const MUTE_KEY = 'kuira.inbox.muted';

export function useInboxAlerts() {
    const muted = ref(readMuted());
    const canNotify = ref(supportsNotifications());
    const permission = ref<NotificationPermission | 'unsupported'>(
        supportsNotifications() ? Notification.permission : 'unsupported',
    );

    // Contador de lo llegado mientras la pestaña estaba en segundo plano.
    let pending = 0;
    const baseTitle = typeof document !== 'undefined' ? document.title : '';

    function readMuted(): boolean {
        try {
            return localStorage.getItem(MUTE_KEY) === '1';
        } catch {
            return false; // modo privado / almacenamiento bloqueado
        }
    }

    function supportsNotifications(): boolean {
        return typeof window !== 'undefined' && 'Notification' in window;
    }

    function toggleMute() {
        muted.value = !muted.value;
        try {
            localStorage.setItem(MUTE_KEY, muted.value ? '1' : '0');
        } catch {
            /* sin almacenamiento el silencio dura lo que la pestaña */
        }
    }

    /**
     * Tono corto sintetizado: evita cargar un archivo de audio (un binario
     * más que versionar, servir y cachear) para dos décimas de "din".
     */
    function beep() {
        try {
            const Ctx =
                window.AudioContext ??
                (
                    window as unknown as {
                        webkitAudioContext?: typeof AudioContext;
                    }
                ).webkitAudioContext;
            if (!Ctx) return;

            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(
                0.12,
                ctx.currentTime + 0.02,
            );
            gain.gain.exponentialRampToValueAtTime(
                0.0001,
                ctx.currentTime + 0.28,
            );

            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
            osc.onended = () => ctx.close();
        } catch {
            /* el navegador puede bloquear audio sin gesto previo */
        }
    }

    /** Debe llamarse desde un clic: los navegadores lo exigen. */
    async function requestPermission() {
        if (!supportsNotifications()) return;

        permission.value = await Notification.requestPermission();
    }

    function refreshTitle() {
        if (typeof document === 'undefined') return;

        document.title = pending > 0 ? `(${pending}) ${baseTitle}` : baseTitle;
    }

    /** Al volver a mirar la bandeja, el contador del título se limpia. */
    function clearBadge() {
        pending = 0;
        refreshTitle();
    }

    function notify(title: string, body: string) {
        if (
            !supportsNotifications() ||
            Notification.permission !== 'granted' ||
            !document.hidden
        ) {
            return;
        }

        try {
            // El tag hace que varios avisos se apilen en uno solo en vez de
            // llenar la pantalla del sistema.
            new Notification(title, { body, tag: 'kuira-inbox' });
        } catch {
            /* algunos navegadores solo permiten notificaciones del SW */
        }
    }

    /** Un mensaje entrante: suena, avisa y suma al título. */
    function announce(title: string, body: string) {
        if (muted.value) return;

        pending += 1;
        refreshTitle();
        beep();
        notify(title, body);
    }

    function onVisible() {
        if (!document.hidden) clearBadge();
    }

    if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', onVisible);

        onBeforeUnmount(() => {
            document.removeEventListener('visibilitychange', onVisible);
            document.title = baseTitle;
        });
    }

    return {
        muted,
        canNotify,
        permission,
        toggleMute,
        requestPermission,
        announce,
        clearBadge,
    };
}
