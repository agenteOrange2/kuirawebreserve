<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset();
        },
    });
};
</script>

<template>
    <AuthLayout
        title="Confirmar contraseña"
        description="Esta es un área segura de la aplicación. Por favor confirma tu contraseña antes de continuar."
    >
        <Head title="Confirmar contraseña" />

        <form @submit.prevent="submit">
            <div class="space-y-6">
                <div class="grid gap-2">
                    <Label for="password">Contraseña</Label>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        class="mt-1 block w-full"
                        required
                        autocomplete="current-password"
                        autofocus
                    />

                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center">
                    <Button
                        class="w-full"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Confirmando...' : 'Confirmar contraseña' }}
                    </Button>
                </div>
            </div>
        </form>
    </AuthLayout>
</template>
