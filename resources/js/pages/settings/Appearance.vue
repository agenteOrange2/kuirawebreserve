<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import { useAppearance } from '@/composables/useAppearance';
import RazeLayout from '@/layouts/RazeLayout.vue';

const { appearance, updateAppearance } = useAppearance();

const themes = [
    {
        value: 'light' as const,
        label: 'Claro',
        icon: 'Sun',
        description: 'Tema claro para uso diurno',
    },
    {
        value: 'dark' as const,
        label: 'Oscuro',
        icon: 'Moon',
        description: 'Tema oscuro para reducir fatiga visual',
    },
    {
        value: 'system' as const,
        label: 'Sistema',
        icon: 'Monitor',
        description: 'Sigue la configuración de tu dispositivo',
    },
];
</script>

<template>
    <Head title="Apariencia" />

    <RazeLayout>
        <div class="grid grid-cols-12 gap-x-6 gap-y-10">
            <div class="col-span-12">
                <div
                    class="flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
                >
                    <div class="text-base font-medium">Configuración</div>
                </div>
            </div>

            <div class="col-span-12">
                <div class="flex flex-col gap-6 lg:flex-row">
                    <!-- Sidebar Nav -->
                    <div class="w-full shrink-0 lg:w-52">
                        <div class="box box--stacked p-1.5">
                            <nav class="flex flex-col">
                                <Link
                                    :href="route('admin.settings.profile.edit')"
                                    class="flex items-center rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="User"
                                        class="mr-2.5 h-4 w-4"
                                    />
                                    Perfil
                                </Link>
                                <Link
                                    :href="
                                        route('admin.settings.password.edit')
                                    "
                                    class="flex items-center rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400"
                                >
                                    <Lucide
                                        icon="Lock"
                                        class="mr-2.5 h-4 w-4"
                                    />
                                    Contraseña
                                </Link>
                                <Link
                                    :href="
                                        route('admin.settings.appearance.edit')
                                    "
                                    :class="[
                                        'flex items-center rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                                        'bg-primary/10 text-primary',
                                    ]"
                                >
                                    <Lucide icon="Sun" class="mr-2.5 h-4 w-4" />
                                    Apariencia
                                </Link>
                            </nav>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="flex-1">
                        <div class="box box--stacked p-5">
                            <div class="mb-5">
                                <h3 class="text-base font-medium">
                                    Apariencia
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    Selecciona el tema de la aplicación.
                                </p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <button
                                    v-for="theme in themes"
                                    :key="theme.value"
                                    @click="updateAppearance(theme.value)"
                                    :class="[
                                        'group relative flex cursor-pointer flex-col items-center rounded-xl border-2 p-5 transition-all',
                                        appearance === theme.value
                                            ? 'border-primary bg-primary/5 dark:bg-primary/10'
                                            : 'border-slate-200 hover:border-slate-300 dark:border-darkmode-400 dark:hover:border-darkmode-300',
                                    ]"
                                >
                                    <div
                                        :class="[
                                            'mb-3 flex h-14 w-14 items-center justify-center rounded-full transition-colors',
                                            appearance === theme.value
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-slate-100 text-slate-500 group-hover:bg-slate-200 dark:bg-darkmode-400 dark:text-slate-400 dark:group-hover:bg-darkmode-300',
                                        ]"
                                    >
                                        <Lucide
                                            :icon="theme.icon"
                                            class="h-6 w-6"
                                        />
                                    </div>
                                    <div class="text-sm font-medium">
                                        {{ theme.label }}
                                    </div>
                                    <div
                                        class="mt-1 text-center text-xs text-slate-500"
                                    >
                                        {{ theme.description }}
                                    </div>

                                    <div
                                        v-if="appearance === theme.value"
                                        class="absolute top-2.5 right-2.5"
                                    >
                                        <div
                                            class="flex h-5 w-5 items-center justify-center rounded-full bg-primary"
                                        >
                                            <Lucide
                                                icon="Check"
                                                class="h-3 w-3 text-white"
                                            />
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
