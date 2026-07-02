<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import AuthLayout from '@/layouts/AuthLayout.vue';

const showRecoveryInput = ref<boolean>(false);
const code = ref<string>('');

const authConfigContent = computed(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Código de recuperación',
            description:
                'Confirma el acceso a tu cuenta ingresando uno de tus códigos de recuperación de emergencia.',
            buttonText: 'usar código de autenticación',
        };
    }

    return {
        title: 'Código de autenticación',
        description:
            'Ingresa el código de autenticación proporcionado por tu aplicación de autenticación.',
        buttonText: 'usar código de recuperación',
    };
});

const form = useForm({
    code: '',
    recovery_code: '',
});

const toggleRecoveryMode = (): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    form.clearErrors();
    code.value = '';
    form.reset();
};

const submit = () => {
    if (showRecoveryInput.value) {
        form.post(route('two-factor.login'));
    } else {
        form.transform(() => ({ code: code.value }));
        form.post(route('two-factor.login'), {
            onError: () => { code.value = ''; },
        });
    }
};
</script>

<template>
    <AuthLayout
        :title="authConfigContent.title"
        :description="authConfigContent.description"
    >
        <Head title="Autenticación de dos factores" />

        <div class="space-y-6">
            <template v-if="!showRecoveryInput">
                <form @submit.prevent="submit" class="space-y-4">
                    <div
                        class="flex flex-col items-center justify-center space-y-3 text-center"
                    >
                        <div class="flex w-full items-center justify-center">
                            <InputOTP
                                id="otp"
                                v-model="code"
                                :maxlength="6"
                                :disabled="form.processing"
                                autofocus
                            >
                                <InputOTPGroup>
                                    <InputOTPSlot
                                        v-for="index in 6"
                                        :key="index"
                                        :index="index - 1"
                                    />
                                </InputOTPGroup>
                            </InputOTP>
                        </div>
                        <InputError :message="form.errors.code" />
                    </div>
                    <Button type="submit" class="w-full" :disabled="form.processing"
                        >Continuar</Button
                    >
                    <div class="text-center text-sm text-muted-foreground">
                        <span>o puedes </span>
                        <button
                            type="button"
                            class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            @click="toggleRecoveryMode"
                        >
                            {{ authConfigContent.buttonText }}
                        </button>
                    </div>
                </form>
            </template>

            <template v-else>
                <form @submit.prevent="submit" class="space-y-4">
                    <Input
                        v-model="form.recovery_code"
                        type="text"
                        placeholder="Ingresa código de recuperación"
                        :autofocus="showRecoveryInput"
                        required
                    />
                    <InputError :message="form.errors.recovery_code" />
                    <Button type="submit" class="w-full" :disabled="form.processing"
                        >Continuar</Button
                    >

                    <div class="text-center text-sm text-muted-foreground">
                        <span>o puedes </span>
                        <button
                            type="button"
                            class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                            @click="toggleRecoveryMode"
                        >
                            {{ authConfigContent.buttonText }}
                        </button>
                    </div>
                </form>
            </template>
        </div>
    </AuthLayout>
</template>
