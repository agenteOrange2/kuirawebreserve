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

/**
 * Lleva la vista a un elemento. Dentro del widget (iframe que crece con su
 * contenido y no tiene scroll propio) scrollIntoView no mueve la página del
 * hotel: se le pide a widget.js que lo haga con `kuira:scroll`.
 */
export function revealElement(el: HTMLElement | null | undefined): void {
    if (!el) return;
    if (window.self === window.top) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return;
    }
    const top = el.getBoundingClientRect().top + window.scrollY;
    window.parent.postMessage({ type: 'kuira:scroll', top }, '*');
}

/** Dentro del widget, lleva la página del hotel al inicio del iframe. */
export function revealEmbedTop(): void {
    if (window.self !== window.top) {
        window.parent.postMessage({ type: 'kuira:scroll', top: 0 }, '*');
    }
}
