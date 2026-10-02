<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import type { AdminUser } from './types';

// Confirmación de borrado del canon: círculo rojo, texto a la izquierda y
// botones a la derecha. El error del backend (último admin, uno mismo) se
// queda dentro del diálogo, junto a lo que lo provocó.
const props = defineProps<{ user: AdminUser | null }>();

const emit = defineEmits<{
    close: [];
    deleted: [user: AdminUser];
}>();

const busy = ref(false);
const error = ref<string | null>(null);

async function confirm() {
    if (!props.user) return;
    busy.value = true;
    error.value = null;
    try {
        await axios.delete(route('admin.users.destroy', props.user.id));
        emit('deleted', props.user);
    } catch (e: any) {
        error.value =
            e.response?.data?.message ?? 'No se pudo eliminar el usuario.';
    } finally {
        busy.value = false;
    }
}

function close() {
    error.value = null;
    emit('close');
}
</script>

<template>
    <Dialog :open="user !== null" @close="close">
        <Dialog.Panel>
            <div class="p-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-danger/10 bg-danger/10 text-danger"
                    >
                        <Lucide icon="Trash2" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">
                            Eliminar a {{ user?.name }}
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">
                            Pierde el acceso al panel de plataforma y no se
                            puede deshacer. Si solo quieres cortarle el acceso,
                            apaga su interruptor de administrador.
                        </p>
                        <p
                            v-if="error"
                            class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger"
                        >
                            {{ error }}
                        </p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="close"
                        >Cancelar</Button
                    >
                    <Button
                        variant="danger"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="busy"
                        @click="confirm"
                    >
                        <Lucide icon="Trash2" class="mr-1.5 h-3.5 w-3.5" />
                        {{ busy ? 'Eliminando...' : 'Sí, eliminar' }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
