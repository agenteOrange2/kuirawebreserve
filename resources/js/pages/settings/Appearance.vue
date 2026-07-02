<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Lucide from '@/components/Base/Lucide';
import { useAppearance } from '@/composables/useAppearance';
import RazeLayout from '@/layouts/RazeLayout.vue';

const { appearance, updateAppearance } = useAppearance();

const themes = [
  { value: 'light' as const, label: 'Claro', icon: 'Sun', description: 'Tema claro para uso diurno' },
  { value: 'dark' as const, label: 'Oscuro', icon: 'Moon', description: 'Tema oscuro para reducir fatiga visual' },
  { value: 'system' as const, label: 'Sistema', icon: 'Monitor', description: 'Sigue la configuración de tu dispositivo' },
];
</script>

<template>
  <Head title="Apariencia" />

  <RazeLayout>
    <div class="grid grid-cols-12 gap-y-10 gap-x-6">
      <div class="col-span-12">
        <div class="flex flex-col md:h-10 gap-y-3 md:items-center md:flex-row">
          <div class="text-base font-medium">Configuración</div>
        </div>
      </div>

      <div class="col-span-12">
        <div class="flex flex-col lg:flex-row gap-6">
          <!-- Sidebar Nav -->
          <div class="w-full lg:w-52 shrink-0">
            <div class="box box--stacked p-1.5">
              <nav class="flex flex-col">
                <Link
                  :href="route('admin.settings.profile.edit')"
                  class="flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-darkmode-400 transition-colors"
                >
                  <Lucide icon="User" class="w-4 h-4 mr-2.5" />
                  Perfil
                </Link>
                <Link
                  :href="route('admin.settings.password.edit')"
                  class="flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-darkmode-400 transition-colors"
                >
                  <Lucide icon="Lock" class="w-4 h-4 mr-2.5" />
                  Contraseña
                </Link>
                <Link
                  :href="route('admin.settings.appearance.edit')"
                  :class="[
                    'flex items-center px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
                    'bg-primary/10 text-primary',
                  ]"
                >
                  <Lucide icon="Sun" class="w-4 h-4 mr-2.5" />
                  Apariencia
                </Link>
              </nav>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1">
            <div class="box box--stacked p-5">
              <div class="mb-5">
                <h3 class="text-base font-medium">Apariencia</h3>
                <p class="text-slate-500 text-sm mt-1">Selecciona el tema de la aplicación.</p>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <button
                  v-for="theme in themes"
                  :key="theme.value"
                  @click="updateAppearance(theme.value)"
                  :class="[
                    'relative flex flex-col items-center p-5 rounded-xl border-2 transition-all cursor-pointer group',
                    appearance === theme.value
                      ? 'border-primary bg-primary/5 dark:bg-primary/10'
                      : 'border-slate-200 dark:border-darkmode-400 hover:border-slate-300 dark:hover:border-darkmode-300',
                  ]"
                >
                  <div
                    :class="[
                      'flex items-center justify-center w-14 h-14 rounded-full mb-3 transition-colors',
                      appearance === theme.value
                        ? 'bg-primary/10 text-primary'
                        : 'bg-slate-100 dark:bg-darkmode-400 text-slate-500 dark:text-slate-400 group-hover:bg-slate-200 dark:group-hover:bg-darkmode-300',
                    ]"
                  >
                    <Lucide :icon="theme.icon" class="w-6 h-6" />
                  </div>
                  <div class="font-medium text-sm">{{ theme.label }}</div>
                  <div class="text-xs text-slate-500 mt-1 text-center">{{ theme.description }}</div>

                  <div
                    v-if="appearance === theme.value"
                    class="absolute top-2.5 right-2.5"
                  >
                    <div class="flex items-center justify-center w-5 h-5 rounded-full bg-primary">
                      <Lucide icon="Check" class="w-3 h-3 text-white" />
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
