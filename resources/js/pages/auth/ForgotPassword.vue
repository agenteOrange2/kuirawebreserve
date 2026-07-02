<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <AuthLayout
        title="¿Olvidaste tu contraseña?"
        description="Ingresa tu email para recibir un enlace de restablecimiento"
    >
        <Head title="Olvidé mi contraseña" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <div class="space-y-6">
            <form @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                        autofocus
                        placeholder="correo@ejemplo.com"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button
                        class="w-full"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Enviando...' : 'Enviar enlace de restablecimiento' }}
                    </Button>
                </div>
            </form>

            <div class="space-x-1 text-center text-sm text-muted-foreground">
                <span>O volver a</span>
                <Link :href="route('login')" class="underline underline-offset-4 hover:text-foreground">iniciar sesión</Link>
            </div>
        </div>
    </AuthLayout>
</template>
