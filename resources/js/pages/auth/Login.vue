<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthShell from '@/components/auth/AuthShell.vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { TenantBrand } from '@/types/auth';

const props = defineProps<{
    canResetPassword?: boolean;
    status?: string;
    /** Correo con el que viene de restablecer su contraseña. */
    email?: string;
    /** Marca del hotel en su dominio (TenantLoginBrand); null en central. */
    tenantBrand?: TenantBrand | null;
}>();

const form = useForm({
    email: props.email ?? '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};

// "¿Olvidaste tu contraseña?" lleva el correo ya escrito.
const forgotHref = () =>
    route(
        'password.request',
        form.email.trim() ? { email: form.email.trim() } : {},
    );
</script>

<template>
    <Head title="Iniciar sesión" />
    <AuthShell :tenant-brand="tenantBrand">
        <div
            v-if="status"
            class="mt-4 flex items-start gap-2 rounded-lg border border-success/30 bg-success/10 p-3 text-sm text-success"
        >
            <Lucide icon="CircleCheck" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>{{ status }}</span>
        </div>

        <div
            v-if="form.errors.email || form.errors.password"
            class="mt-4 rounded-lg border border-danger/30 bg-danger/10 p-3 text-sm text-danger"
        >
            <p v-if="form.errors.email">{{ form.errors.email }}</p>
            <p v-if="form.errors.password">
                {{ form.errors.password }}
            </p>
        </div>

        <form @submit.prevent="submit" class="mt-6">
            <FormLabel htmlFor="login-email">Correo electrónico</FormLabel>
            <FormInput
                id="login-email"
                v-model="form.email"
                type="email"
                autocomplete="username"
                class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                placeholder="correo@ejemplo.com"
                required
            />
            <FormLabel htmlFor="login-password" class="mt-4"
                >Contraseña</FormLabel
            >
            <div class="relative">
                <FormInput
                    id="login-password"
                    v-model="form.password"
                    :type="showPassword ? 'text' : 'password'"
                    autocomplete="current-password"
                    class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5 pr-11"
                    placeholder="************"
                    required
                />
                <button
                    type="button"
                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-400 transition hover:text-slate-600"
                    :title="
                        showPassword
                            ? 'Ocultar contraseña'
                            : 'Mostrar contraseña'
                    "
                    @click="showPassword = !showPassword"
                >
                    <Lucide
                        :icon="showPassword ? 'EyeOff' : 'Eye'"
                        class="h-4 w-4"
                    />
                </button>
            </div>
            <div
                class="mt-4 flex items-center justify-between text-xs text-slate-500 sm:text-sm"
            >
                <label class="flex cursor-pointer items-center select-none">
                    <FormCheck.Input
                        id="remember-me"
                        v-model="form.remember"
                        type="checkbox"
                        class="mr-2 border"
                    />
                    Recordarme
                </label>
                <Link
                    v-if="canResetPassword"
                    :href="forgotHref()"
                    class="text-primary"
                >
                    ¿Olvidaste tu contraseña?
                </Link>
            </div>
            <div class="mt-5 text-center xl:mt-8 xl:text-left">
                <Button
                    type="submit"
                    variant="primary"
                    rounded
                    class="w-full bg-linear-to-r from-theme-1/70 to-theme-2/70 py-3.5"
                    :disabled="form.processing"
                >
                    <Lucide
                        v-if="form.processing"
                        icon="Loader"
                        class="mr-2 h-5 w-5 animate-spin"
                    />
                    {{ form.processing ? 'Ingresando...' : 'Iniciar sesión' }}
                </Button>
            </div>
        </form>
    </AuthShell>
</template>
