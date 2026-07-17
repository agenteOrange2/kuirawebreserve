<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import RazeLayout from '@/layouts/RazeLayout.vue';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('admin.settings.password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () =>
            form.reset('password', 'password_confirmation', 'current_password'),
    });
};
</script>

<template>
    <Head title="Contraseña" />

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
                    <div class="w-full flex-shrink-0 lg:w-52">
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
                                    :class="[
                                        'flex items-center rounded-lg px-3 py-2.5 text-sm font-medium transition-colors',
                                        'bg-primary/10 text-primary',
                                    ]"
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
                                    class="flex items-center rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-darkmode-400"
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
                                    Cambiar Contraseña
                                </h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    Asegúrate de usar una contraseña larga y
                                    segura.
                                </p>
                            </div>

                            <form @submit.prevent="submit" class="space-y-4">
                                <div>
                                    <FormLabel for="current_password"
                                        >Contraseña Actual</FormLabel
                                    >
                                    <FormInput
                                        id="current_password"
                                        v-model="form.current_password"
                                        type="password"
                                        autocomplete="current-password"
                                        placeholder="Contraseña actual"
                                        class="mt-1"
                                    />
                                    <div
                                        v-if="form.errors.current_password"
                                        class="mt-1 text-xs text-danger"
                                    >
                                        {{ form.errors.current_password }}
                                    </div>
                                </div>

                                <div>
                                    <FormLabel for="password"
                                        >Nueva Contraseña</FormLabel
                                    >
                                    <FormInput
                                        id="password"
                                        v-model="form.password"
                                        type="password"
                                        autocomplete="new-password"
                                        placeholder="Nueva contraseña"
                                        class="mt-1"
                                    />
                                    <div
                                        v-if="form.errors.password"
                                        class="mt-1 text-xs text-danger"
                                    >
                                        {{ form.errors.password }}
                                    </div>
                                </div>

                                <div>
                                    <FormLabel for="password_confirmation"
                                        >Confirmar Contraseña</FormLabel
                                    >
                                    <FormInput
                                        id="password_confirmation"
                                        v-model="form.password_confirmation"
                                        type="password"
                                        autocomplete="new-password"
                                        placeholder="Confirmar contraseña"
                                        class="mt-1"
                                    />
                                    <div
                                        v-if="form.errors.password_confirmation"
                                        class="mt-1 text-xs text-danger"
                                    >
                                        {{ form.errors.password_confirmation }}
                                    </div>
                                </div>

                                <div class="flex items-center gap-3 pt-2">
                                    <Button
                                        variant="primary"
                                        type="submit"
                                        :disabled="form.processing"
                                    >
                                        Guardar Contraseña
                                    </Button>
                                    <Transition
                                        enter-active-class="transition ease-in-out"
                                        enter-from-class="opacity-0"
                                        leave-active-class="transition ease-in-out"
                                        leave-to-class="opacity-0"
                                    >
                                        <span
                                            v-show="form.recentlySuccessful"
                                            class="text-sm text-success"
                                        >
                                            Guardado.
                                        </span>
                                    </Transition>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
