<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Puerta de área segura (Fortify): se cruza estando YA dentro del panel,
 * camino a la verificación en dos pasos. Por eso vive dentro del panel y no
 * en la pantalla de acceso: quien la ve no está iniciando sesión.
 */
const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Confirmar contraseña" />

    <RazeLayout title="Confirmar contraseña">
        <div class="mt-2 flex justify-center">
            <div class="box box--stacked w-full max-w-md overflow-hidden">
                <div
                    class="flex items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="ShieldAlert" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-sm font-medium">Área segura</div>
                        <div class="text-xs text-slate-500">
                            Confirma tu contraseña para continuar.
                        </div>
                    </div>
                </div>

                <form class="px-4 py-3" @submit.prevent="submit">
                    <FormLabel for="password" class="text-xs">
                        Contraseña
                    </FormLabel>
                    <FormInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="h-9 text-xs"
                        required
                        autocomplete="current-password"
                        autofocus
                    />
                    <div
                        v-if="form.errors.password"
                        class="mt-1 text-[11px] text-danger"
                    >
                        {{ form.errors.password }}
                    </div>

                    <div
                        class="mt-4 flex justify-end border-t border-slate-200/60 pt-3 dark:border-darkmode-400"
                    >
                        <Button
                            variant="primary"
                            type="submit"
                            class="h-9 rounded-[0.5rem] px-5 text-xs"
                            :disabled="form.processing"
                        >
                            <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                            {{
                                form.processing
                                    ? 'Confirmando…'
                                    : 'Confirmar contraseña'
                            }}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    </RazeLayout>
</template>
