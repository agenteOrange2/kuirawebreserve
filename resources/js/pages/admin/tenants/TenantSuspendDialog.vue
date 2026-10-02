<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/Base/Button';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';

// Confirmación de suspender / reactivar, con el canon de borrado: círculo,
// texto a la izquierda y botones a la derecha. La usan el listado y la
// cabecera de la ficha.
const props = defineProps<{
    tenant: { id: string; name: string; suspended: boolean } | null;
}>();
const emit = defineEmits<{ close: [] }>();

const form = useForm({});

function close() {
    if (form.processing) return;
    emit('close');
}

function confirm() {
    if (!props.tenant) return;
    form.patch(route('admin.tenants.suspend', props.tenant.id), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Dialog :open="tenant !== null" @close="close">
        <Dialog.Panel>
            <div v-if="tenant" class="p-5">
                <div class="flex items-start gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border"
                        :class="
                            tenant.suspended
                                ? 'border-success/10 bg-success/10 text-success'
                                : 'border-warning/10 bg-warning/10 text-warning'
                        "
                    >
                        <Lucide
                            :icon="tenant.suspended ? 'Play' : 'Pause'"
                            class="h-4 w-4"
                        />
                    </div>
                    <div class="min-w-0">
                        <h2 class="text-base font-medium">
                            {{ tenant.suspended ? 'Reactivar' : 'Suspender' }}
                            {{ tenant.name }}
                        </h2>
                        <p
                            v-if="tenant.suspended"
                            class="mt-1 text-xs text-slate-500"
                        >
                            Su equipo vuelve a entrar al panel tal como lo dejó.
                            No se perdió nada mientras estuvo suspendido.
                        </p>
                        <p v-else class="mt-1 text-xs text-slate-500">
                            Su equipo deja de entrar al panel y ve la pantalla
                            de hotel suspendido con el contacto de soporte. No
                            se borra nada y se reactiva cuando quieras. Para
                            borrarlo de verdad está "Eliminar" en el listado.
                        </p>
                    </div>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <Button
                        variant="outline-secondary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        :disabled="form.processing"
                        @click="close"
                        >Cancelar</Button
                    >
                    <Button
                        :variant="tenant.suspended ? 'success' : 'warning'"
                        class="h-9 rounded-[0.5rem] px-5 text-xs text-white"
                        :disabled="form.processing"
                        @click="confirm"
                    >
                        <Lucide
                            :icon="tenant.suspended ? 'Play' : 'Pause'"
                            class="mr-1.5 h-3.5 w-3.5"
                        />
                        {{
                            form.processing
                                ? 'Guardando...'
                                : tenant.suspended
                                  ? 'Sí, reactivar'
                                  : 'Sí, suspender'
                        }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
