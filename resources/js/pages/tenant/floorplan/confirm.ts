import { ref } from 'vue';
import type { Icon } from '@/components/Base/Lucide';

export interface ConfirmOptions {
    title: string;
    message: string;
    confirmLabel?: string;
    icon?: Icon;
    tone?: 'danger' | 'primary' | 'warning';
}

/**
 * Confirmación con promesa, para escribir `if (!(await ask({...}))) return;`
 * igual que se escribía con `window.confirm`. El texto se queda después de
 * contestar para que el modal no salga vacío durante su animación de cierre.
 */
export function useConfirm() {
    const open = ref(false);
    const options = ref<ConfirmOptions>({ title: '', message: '' });
    let resolver: ((value: boolean) => void) | null = null;

    function ask(next: ConfirmOptions): Promise<boolean> {
        resolver?.(false);
        options.value = next;
        open.value = true;

        return new Promise((resolve) => {
            resolver = resolve;
        });
    }

    function answer(value: boolean) {
        open.value = false;
        resolver?.(value);
        resolver = null;
    }

    return { open, options, ask, answer };
}
