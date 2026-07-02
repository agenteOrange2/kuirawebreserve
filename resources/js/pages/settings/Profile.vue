<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput, FormLabel } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

type Props = {
  mustVerifyEmail: boolean;
  status?: string;
};

defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user);

const profileForm = useForm({
  name: user.value.name,
  email: user.value.email,
});

const submitProfile = () => {
  profileForm.patch(route('admin.settings.profile.update'));
};

const deleteForm = useForm({
  password: '',
});

const showDeleteModal = ref(false);

const submitDelete = () => {
  deleteForm.delete(route('admin.settings.profile.destroy'), {
    onSuccess: () => {
      showDeleteModal.value = false;
    },
    onError: () => {
      deleteForm.reset('password');
    },
  });
};

</script>

<template>
  <Head title="Configuración" />

  <RazeLayout>
    <div class="grid grid-cols-12 gap-y-10 gap-x-6">
      <!-- Header -->
      <div class="col-span-12">
        <div class="flex flex-col md:h-10 gap-y-3 md:items-center md:flex-row">
          <div class="text-base font-medium">Configuración</div>
        </div>
      </div>

      <div class="col-span-12">
        <div class="flex flex-col lg:flex-row gap-6">
          <!-- Sidebar Nav -->
          <div class="w-full lg:w-52 flex-shrink-0">
            <div class="box box--stacked p-1.5">
              <nav class="flex flex-col">
                <Link
                  :href="route('admin.settings.profile.edit')"
                  :class="[
                    'flex items-center px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
                    'bg-primary/10 text-primary',
                  ]"
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
                  class="flex items-center px-3 py-2.5 rounded-lg text-sm font-medium text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-darkmode-400 transition-colors"
                >
                  <Lucide icon="Sun" class="w-4 h-4 mr-2.5" />
                  Apariencia
                </Link>
              </nav>
            </div>
          </div>

          <!-- Content -->
          <div class="flex-1 space-y-6">
            <!-- Profile Info -->
            <div class="box box--stacked p-5">
              <div class="mb-5">
                <h3 class="text-base font-medium">Información del Perfil</h3>
                <p class="text-slate-500 text-sm mt-1">Actualiza tu nombre y correo electrónico.</p>
              </div>

              <form @submit.prevent="submitProfile" class="space-y-4">
                <div>
                  <FormLabel for="name">Nombre</FormLabel>
                  <FormInput
                    id="name"
                    v-model="profileForm.name"
                    type="text"
                    required
                    autocomplete="name"
                    placeholder="Nombre completo"
                    class="mt-1"
                  />
                  <div v-if="profileForm.errors.name" class="text-danger text-xs mt-1">
                    {{ profileForm.errors.name }}
                  </div>
                </div>

                <div>
                  <FormLabel for="email">Correo electrónico</FormLabel>
                  <FormInput
                    id="email"
                    v-model="profileForm.email"
                    type="email"
                    required
                    autocomplete="username"
                    placeholder="correo@ejemplo.com"
                    class="mt-1"
                  />
                  <div v-if="profileForm.errors.email" class="text-danger text-xs mt-1">
                    {{ profileForm.errors.email }}
                  </div>
                </div>

                <div v-if="mustVerifyEmail && !user.email_verified_at">
                  <p class="text-sm text-slate-500">
                    Tu correo no ha sido verificado.
                    <Link
                      :href="route('verification.send')"
                      method="post"
                      as="button"
                      class="text-primary underline underline-offset-4"
                    >
                      Reenviar verificación
                    </Link>
                  </p>
                  <div
                    v-if="status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-success"
                  >
                    Se ha enviado un nuevo enlace de verificación.
                  </div>
                </div>

                <div class="flex items-center gap-3 pt-2">
                  <Button
                    variant="primary"
                    type="submit"
                    :disabled="profileForm.processing"
                  >
                    Guardar Cambios
                  </Button>
                  <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                  >
                    <span
                      v-show="profileForm.recentlySuccessful"
                      class="text-sm text-success"
                    >
                      Guardado.
                    </span>
                  </Transition>
                </div>
              </form>
            </div>

            <!-- Delete Account -->
            <div class="box box--stacked p-5">
              <div class="mb-5">
                <h3 class="text-base font-medium text-danger">Eliminar Cuenta</h3>
                <p class="text-slate-500 text-sm mt-1">Elimina tu cuenta y todos sus datos permanentemente.</p>
              </div>

              <div class="rounded-lg border border-danger/20 bg-danger/5 p-4 dark:border-danger/30 dark:bg-danger/10">
                <div class="flex items-start gap-3">
                  <Lucide icon="AlertTriangle" class="w-5 h-5 text-danger mt-0.5 flex-shrink-0" />
                  <div>
                    <p class="font-medium text-danger text-sm">Advertencia</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-0.5">
                      Esta acción no se puede deshacer. Todos tus datos serán eliminados permanentemente.
                    </p>
                  </div>
                </div>
              </div>

              <div class="mt-4">
                <Button
                  variant="danger"
                  @click="showDeleteModal = true"
                >
                  Eliminar Cuenta
                </Button>
              </div>

              <!-- Delete Modal -->
              <Dialog
                :open="showDeleteModal"
                @close="showDeleteModal = false; deleteForm.reset();"
              >
                <Dialog.Panel>
                  <div class="p-5">
                    <div class="text-center sm:text-left">
                      <div class="flex items-center gap-3 mb-4">
                        <div class="flex items-center justify-center w-12 h-12 rounded-full bg-danger/10">
                          <Lucide icon="AlertTriangle" class="w-6 h-6 text-danger" />
                        </div>
                        <div>
                          <h3 class="text-lg font-medium">¿Estás seguro?</h3>
                          <p class="text-slate-500 text-sm mt-0.5">Esta acción no se puede deshacer.</p>
                        </div>
                      </div>

                      <p class="text-sm text-slate-600 dark:text-slate-400 mb-5">
                        Una vez eliminada tu cuenta, todos tus datos serán eliminados permanentemente.
                        Ingresa tu contraseña para confirmar.
                      </p>

                      <form @submit.prevent="submitDelete">
                        <div>
                          <FormLabel for="delete-password">Contraseña</FormLabel>
                          <FormInput
                            id="delete-password"
                            v-model="deleteForm.password"
                            type="password"
                            placeholder="Tu contraseña"
                            class="mt-1"
                          />
                          <div v-if="deleteForm.errors.password" class="text-danger text-xs mt-1">
                            {{ deleteForm.errors.password }}
                          </div>
                        </div>

                        <div class="flex justify-end gap-2 mt-5">
                          <Button
                            variant="secondary"
                            type="button"
                            @click="showDeleteModal = false; deleteForm.reset();"
                          >
                            Cancelar
                          </Button>
                          <Button
                            variant="danger"
                            type="submit"
                            :disabled="deleteForm.processing"
                          >
                            Eliminar Cuenta
                          </Button>
                        </div>
                      </form>
                    </div>
                  </div>
                </Dialog.Panel>
              </Dialog>
            </div>
          </div>
        </div>
      </div>
    </div>
  </RazeLayout>
</template>
