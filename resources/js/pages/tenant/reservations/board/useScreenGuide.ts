import { ref } from 'vue';

/**
 * Si la guía de una pantalla se ve o no, recordado en ESTE navegador. Es una
 * comodidad de quien atiende: la primera vez aparece sola, al cerrarla se
 * queda cerrada, y el botón "Cómo se usa" la vuelve a abrir. Sin acceso al
 * almacenamiento (modo privado) simplemente aparece abierta.
 */
export function useScreenGuide(screen: string) {
    const key = `kuira.guia.${screen}`;
    let hidden = false;
    try {
        hidden = window.localStorage.getItem(key) === 'oculta';
    } catch {
        hidden = false;
    }

    const open = ref(!hidden);

    function remember(value: boolean) {
        open.value = value;
        try {
            if (value) window.localStorage.removeItem(key);
            else window.localStorage.setItem(key, 'oculta');
        } catch {
            // Sin almacenamiento la guía solo dura lo que dure la página.
        }
    }

    return {
        open,
        close: () => remember(false),
        toggle: () => remember(!open.value),
    };
}
