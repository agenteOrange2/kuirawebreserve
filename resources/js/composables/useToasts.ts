import { reactive } from 'vue';

export interface Toast {
    id: number;
    type: 'success' | 'error' | 'info';
    title: string;
    message?: string;
}

// Estado a nivel módulo: cualquier página empuja, el host del layout pinta.
const toasts = reactive<Toast[]>([]);
let nextId = 1;

function dismiss(id: number) {
    const index = toasts.findIndex((toast) => toast.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

function push(type: Toast['type'], title: string, message?: string) {
    const id = nextId++;
    toasts.push({ id, type, title, message });
    setTimeout(() => dismiss(id), type === 'error' ? 8000 : 4500);
}

export function useToasts() {
    return {
        toasts,
        dismiss,
        success: (title: string, message?: string) => push('success', title, message),
        error: (title: string, message?: string) => push('error', title, message),
        info: (title: string, message?: string) => push('info', title, message),
    };
}
