<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

/**
 * Hermana de ModuleDisabled.vue, pero responde a otra pregunta: el módulo
 * dice qué compró el hotel y se puede solicitar; el modo de operación dice
 * qué clase de negocio es y lo administra la plataforma. Por eso aquí NO hay
 * un "Ver mi plan": ofrecer activarlo sería mentir.
 */
defineProps<{
    currentModeLabel: string;
    allowedModeLabels: string[];
}>();
</script>

<template>
    <RazeLayout title="No disponible en este modo">
        <div class="grid grid-cols-12 gap-5">
            <div class="col-span-12 xl:col-span-8 xl:col-start-3">
                <div class="box box--stacked mt-5 p-10 text-center">
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="CarFront" class="h-6 w-6" />
                    </div>
                    <div class="mt-5 text-lg font-medium">
                        Esta sección no aplica a tu modo de operación
                    </div>
                    <div class="mx-auto mt-2 max-w-md text-slate-500">
                        Es una herramienta de propiedades que operan como
                        {{ allowedModeLabels.join(' o ') }}, y este hotel está
                        configurado como {{ currentModeLabel }}. El modo de
                        operación lo define la plataforma; si crees que está mal
                        configurado, escríbenos y lo ajustamos.
                    </div>
                    <div class="mt-6 flex justify-center">
                        <Link :href="route('tenant.dashboard')">
                            <Button variant="outline-secondary"
                                >Volver al dashboard</Button
                            >
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
