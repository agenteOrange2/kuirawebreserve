<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Lucide from '@/components/Base/Lucide';
import { useSettingsRoutes } from '@/composables/useSettingsRoutes';

/**
 * Navegación de las pantallas de cuenta, compartida por el panel central y
 * el del hotel (cada uno con sus rutas). Antes estaba copiada en cada
 * página con las rutas del admin escritas a mano y el item activo fijo.
 */
const { items } = useSettingsRoutes();
const page = usePage();

const pathOf = (routeName: string) => {
    try {
        return new URL(route(routeName)).pathname;
    } catch {
        return null;
    }
};

const currentPath = computed(() => page.url.split('?')[0]);
</script>

<template>
    <div class="w-full flex-shrink-0 lg:w-52">
        <div class="box box--stacked p-1.5">
            <nav class="flex flex-col">
                <Link
                    v-for="item in items"
                    :key="item.routeName"
                    :href="route(item.routeName)"
                    :class="[
                        'flex items-center rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                        pathOf(item.routeName) === currentPath
                            ? 'bg-primary/10 text-primary'
                            : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400',
                    ]"
                >
                    <Lucide :icon="item.icon" class="mr-2.5 h-4 w-4" />
                    {{ item.label }}
                </Link>
            </nav>
        </div>
    </div>
</template>
