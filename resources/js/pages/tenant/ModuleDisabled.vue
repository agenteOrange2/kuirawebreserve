<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Button from '@/components/Base/Button';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

defineProps<{
    module: { key: string; label: string; description: string | null };
}>();
</script>

<template>
    <RazeLayout :title="module.label">
        <div class="grid grid-cols-12 gap-5">
            <div class="col-span-12 xl:col-span-8 xl:col-start-3">
                <div class="box box--stacked mt-5 p-10 text-center">
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
                    >
                        <Lucide icon="Lock" class="h-6 w-6" />
                    </div>
                    <div class="mt-5 text-lg font-medium">
                        {{ module.label }} no está incluido en tu plan
                    </div>
                    <div class="mx-auto mt-2 max-w-md text-slate-500">
                        {{
                            module.description ??
                            'Este módulo no forma parte de tu plan actual.'
                        }}
                        Puedes revisar lo que incluye tu plan y solicitar la
                        activación desde los ajustes del hotel.
                    </div>
                    <div class="mt-6 flex justify-center gap-3">
                        <Link :href="route('tenant.hotel-settings')">
                            <Button variant="primary">
                                <Lucide icon="Layers" class="mr-2 h-4 w-4" />
                                Ver mi plan
                            </Button>
                        </Link>
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
