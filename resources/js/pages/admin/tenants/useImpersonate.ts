import axios from 'axios';
import { ref } from 'vue';

/**
 * "Entrar como" (soporte): pide un token de un solo uso (60 s) y abre el
 * panel del hotel como su dueño. Lo usan el listado y la cabecera de la
 * ficha.
 */
export function useImpersonate() {
    const impersonating = ref<string | null>(null);
    const impersonateError = ref<string | null>(null);

    async function impersonate(tenantId: string) {
        impersonating.value = tenantId;
        impersonateError.value = null;
        // Abrir la pestaña ANTES del await: tras una respuesta asíncrona el
        // navegador ya no lo trata como gesto del usuario y bloquea el popup
        // (y el token solo vive 60 s, no admite copiar/pegar).
        const win = window.open('', '_blank');
        try {
            const { data } = await axios.post<{ url: string }>(
                route('admin.tenants.impersonate', tenantId),
            );
            if (win) {
                win.location.href = data.url;
            } else {
                window.location.href = data.url;
            }
        } catch (error: any) {
            win?.close();
            impersonateError.value =
                error?.response?.data?.message ??
                'No se pudo generar el acceso.';
        } finally {
            impersonating.value = null;
        }
    }

    return { impersonating, impersonateError, impersonate };
}
