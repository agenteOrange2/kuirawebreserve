<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};
</script>

<template>
    <AuthLayout
        title="Verificar email"
        description="Por favor verifica tu dirección de email haciendo clic en el enlace que te enviamos."
    >
        <Head title="Verificación de email" />

        <div
            v-if="status === 'verification-link-sent'"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            Se ha enviado un nuevo enlace de verificación a tu dirección de email.
        </div>

        <form @submit.prevent="submit" class="space-y-6 text-center">
            <Button :disabled="form.processing" variant="secondary">
                {{ form.processing ? 'Enviando...' : 'Reenviar email de verificación' }}
            </Button>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="mx-auto block text-sm underline underline-offset-4 hover:text-foreground"
            >
                Cerrar sesión
            </Link>
        </form>
    </AuthLayout>
</template>
