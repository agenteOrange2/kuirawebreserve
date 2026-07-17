import { onBeforeUnmount, onMounted } from 'vue';

/**
 * Cuando la página vive dentro de un iframe (widget incrustado en WP u
 * otro sitio), reporta su alto real al padre para que el iframe crezca
 * solo. Fuera de un iframe no hace nada. Solo manda el alto: ningún dato
 * del huésped cruza el postMessage.
 */
export function useEmbedResize() {
    let observer: ResizeObserver | null = null;

    onMounted(() => {
        if (window.self === window.top) return;

        const post = () => {
            window.parent.postMessage(
                {
                    type: 'kuira:height',
                    height: document.documentElement.scrollHeight,
                },
                '*',
            );
        };

        observer = new ResizeObserver(post);
        observer.observe(document.documentElement);
        post();
    });

    onBeforeUnmount(() => observer?.disconnect());
}
