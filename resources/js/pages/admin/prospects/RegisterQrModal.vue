<script setup lang="ts">
import QRCode from 'qrcode';
import { ref, watch } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';

const props = defineProps<{ open: boolean; registerUrl: string }>();
const emit = defineEmits<{ close: [] }>();

const toasts = useToasts();
const qrDataUrl = ref('');

watch(
    () => props.open,
    async (open) => {
        if (open && !qrDataUrl.value) {
            qrDataUrl.value = await QRCode.toDataURL(props.registerUrl, {
                width: 768,
                margin: 2,
            });
        }
    },
    { immediate: true },
);

function downloadQr(): void {
    if (!qrDataUrl.value) {
        return;
    }
    const link = document.createElement('a');
    link.href = qrDataUrl.value;
    link.download = 'qr-registro-prospectos.png';
    link.click();
}

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.registerUrl);
        toasts.success('Enlace copiado.');
    } catch {
        toasts.error('No se pudo copiar; selecciona el enlace a mano.');
    }
}
</script>

<template>
    <Dialog :open="open" @close="emit('close')">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[460px]">
            <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="QrCode" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <Dialog.Title
                            class="block border-0 p-0 text-base font-medium"
                            >QR de registro</Dialog.Title
                        >
                        <p class="mt-0.5 text-xs text-slate-500">
                            Quien lo escanee llega al formulario público.
                        </p>
                    </div>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="emit('close')"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <div class="flex justify-center">
                        <img
                            v-if="qrDataUrl"
                            :src="qrDataUrl"
                            alt="QR del formulario de registro"
                            class="h-56 w-56 rounded-xl border border-slate-200 bg-white p-2"
                        />
                        <div
                            v-else
                            class="flex h-56 w-56 items-center justify-center rounded-xl border border-slate-200 text-xs text-slate-400"
                        >
                            Generando QR...
                        </div>
                    </div>
                    <div
                        class="mt-4 flex items-center gap-2 rounded-lg border border-slate-200/80 bg-slate-50/70 py-1.5 pr-1.5 pl-3 dark:border-darkmode-400 dark:bg-darkmode-600/40"
                    >
                        <a
                            :href="registerUrl"
                            target="_blank"
                            rel="noopener"
                            class="min-w-0 flex-1 truncate text-xs text-primary hover:underline"
                            >{{ registerUrl }}</a
                        >
                        <button
                            type="button"
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-primary/10 hover:text-primary"
                            title="Copiar enlace"
                            @click="copyLink"
                        >
                            <Lucide icon="Copy" class="h-4 w-4" />
                        </button>
                    </div>
                    <p class="mt-3 text-xs text-slate-500">
                        Imprímelo o proyéctalo en la reunión. Cada registro
                        llega a esta bandeja como "Registro por QR".
                    </p>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="emit('close')"
                        >Cerrar</Button
                    >
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="!qrDataUrl"
                        @click="downloadQr"
                    >
                        <Lucide icon="Download" class="mr-1.5 h-3.5 w-3.5" />
                        Descargar PNG
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
