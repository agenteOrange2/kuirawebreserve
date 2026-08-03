/**
 * Service worker del panel: recibe los avisos push aunque el panel esté
 * cerrado y lleva a la pantalla correcta al picarles.
 *
 * Vive en /public para servirse desde la raíz: un service worker solo
 * controla su propio directorio hacia abajo, y necesita el sitio entero.
 */

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) =>
    event.waitUntil(self.clients.claim()),
);

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload = {};
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Aviso', body: event.data.text() };
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || 'Aviso', {
            body: payload.body || '',
            // Reemplaza el aviso anterior del mismo asunto en vez de apilar
            // cinco notificaciones del mismo huésped.
            tag: payload.tag || 'kuira',
            renotify: true,
            data: { url: payload.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url || '/';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                // Si el panel ya está abierto en alguna ventana, se reusa y
                // se lleva a la pantalla del aviso; abrir otra pestaña más
                // sería justo lo que molesta de estas cosas.
                for (const client of clients) {
                    if (client.url.includes(self.location.origin)) {
                        return client
                            .focus()
                            .then(() => client.navigate(target));
                    }
                }

                return self.clients.openWindow(target);
            }),
    );
});
