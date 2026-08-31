<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput } from '@/components/Base/Form';
import { Dialog } from '@/components/Base/Headless';
import Lucide from '@/components/Base/Lucide';
import { useAppearance } from '@/composables/useAppearance';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { confirm } from '@/routes/two-factor';

type Props = {
    requiresConfirmation: boolean;
    twoFactorEnabled: boolean;
};

const { resolvedAppearance } = useAppearance();

const props = defineProps<Props>();
const isOpen = defineModel<boolean>('isOpen');

const { copy, copied } = useClipboard();
const { qrCodeSvg, manualSetupKey, clearSetupData, fetchSetupData, errors } =
    useTwoFactorAuth();

const showVerificationStep = ref(false);
const code = ref<string>('');
const pinInputContainerRef = useTemplateRef('pinInputContainerRef');

const modalConfig = computed(() => {
    if (props.twoFactorEnabled) {
        return {
            title: 'Verificación en dos pasos activada',
            description:
                'Ya está activa. Escanea el código o captura la clave en tu aplicación de autenticación.',
            buttonText: 'Cerrar',
        };
    }

    if (showVerificationStep.value) {
        return {
            title: 'Confirma el código',
            description:
                'Escribe los 6 dígitos que muestra tu aplicación de autenticación.',
            buttonText: 'Continuar',
        };
    }

    return {
        title: 'Activar verificación en dos pasos',
        description:
            'Escanea este código con tu aplicación de autenticación (Google Authenticator, 1Password, Authy).',
        buttonText: 'Continuar',
    };
});

const handleModalNextStep = () => {
    if (props.requiresConfirmation) {
        showVerificationStep.value = true;
        nextTick(() => {
            pinInputContainerRef.value?.querySelector('input')?.focus();
        });

        return;
    }

    clearSetupData();
    isOpen.value = false;
};

const resetModalState = () => {
    if (props.twoFactorEnabled) {
        clearSetupData();
    }
    showVerificationStep.value = false;
    code.value = '';
};

watch(
    () => isOpen.value,
    async (open) => {
        if (!open) {
            resetModalState();

            return;
        }
        if (!qrCodeSvg.value) {
            await fetchSetupData();
        }
    },
);

// Solo dígitos: el código del autenticador son 6 números.
const onCodeInput = (value: string) => {
    code.value = value.replace(/\D/g, '').slice(0, 6);
};
</script>

<template>
    <Dialog :open="Boolean(isOpen)" @close="isOpen = false">
        <Dialog.Panel class="sm:w-[94vw] lg:w-[520px]">
            <div class="flex max-h-[calc(100dvh-6rem)] flex-col">
                <div
                    class="flex items-center gap-3 border-b border-slate-200/70 px-5 py-4 dark:border-darkmode-400"
                >
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ScanLine" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-base font-medium">
                            {{ modalConfig.title }}
                        </div>
                        <div class="text-xs text-slate-500">
                            {{ modalConfig.description }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="ml-auto flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 dark:hover:bg-darkmode-400"
                        title="Cerrar"
                        @click="isOpen = false"
                    >
                        <Lucide icon="X" class="h-4 w-4" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                    <template v-if="!showVerificationStep">
                        <div
                            v-if="errors?.length"
                            class="rounded-lg border border-danger/20 bg-danger/5 px-3 py-2.5 text-xs text-danger"
                        >
                            <p v-for="(error, i) in errors" :key="i">
                                {{ error }}
                            </p>
                        </div>
                        <template v-else>
                            <div class="flex justify-center">
                                <div
                                    class="flex aspect-square w-52 items-center justify-center overflow-hidden rounded-lg border border-slate-200/70 bg-white p-3 dark:border-darkmode-400"
                                >
                                    <div
                                        v-if="!qrCodeSvg"
                                        class="h-full w-full animate-pulse rounded bg-slate-100 dark:bg-darkmode-400"
                                    ></div>
                                    <div
                                        v-else
                                        v-html="qrCodeSvg"
                                        class="flex size-full items-center justify-center"
                                        :style="{
                                            filter:
                                                resolvedAppearance === 'dark'
                                                    ? 'invert(1) brightness(1.5)'
                                                    : undefined,
                                        }"
                                    />
                                </div>
                            </div>

                            <div
                                class="mt-4 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                O captura la clave a mano
                            </div>
                            <div class="mt-1.5 flex items-stretch gap-2">
                                <FormInput
                                    :value="manualSetupKey ?? 'Generando…'"
                                    type="text"
                                    readonly
                                    class="h-9 font-mono text-xs"
                                />
                                <button
                                    type="button"
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[0.5rem] border border-slate-200 text-slate-500 transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400"
                                    :title="copied ? 'Copiada' : 'Copiar clave'"
                                    :disabled="!manualSetupKey"
                                    @click="copy(manualSetupKey || '')"
                                >
                                    <Lucide
                                        :icon="copied ? 'Check' : 'Copy'"
                                        class="h-4 w-4"
                                    />
                                </button>
                            </div>
                        </template>
                    </template>

                    <Form
                        v-else
                        v-bind="confirm.form()"
                        error-bag="confirmTwoFactorAuthentication"
                        reset-on-error
                        @finish="code = ''"
                        @success="isOpen = false"
                        v-slot="{ errors: formErrors, processing }"
                    >
                        <input type="hidden" name="code" :value="code" />
                        <div ref="pinInputContainerRef">
                            <div
                                class="text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                            >
                                Código de 6 dígitos
                            </div>
                            <FormInput
                                :value="code"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                maxlength="6"
                                placeholder="000000"
                                class="mt-1.5 h-11 text-center font-mono text-lg tracking-[0.5em]"
                                :disabled="processing"
                                @input="
                                    onCodeInput(
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                            <div
                                v-if="formErrors?.code"
                                class="mt-1 text-[11px] text-danger"
                            >
                                {{ formErrors.code }}
                            </div>

                            <div
                                class="mt-4 flex items-center justify-end gap-2"
                            >
                                <Button
                                    type="button"
                                    variant="secondary"
                                    class="h-9 rounded-[0.5rem] px-5 text-xs"
                                    :disabled="processing"
                                    @click="showVerificationStep = false"
                                >
                                    Volver
                                </Button>
                                <Button
                                    type="submit"
                                    variant="primary"
                                    class="h-9 rounded-[0.5rem] px-5 text-xs"
                                    :disabled="processing || code.length < 6"
                                >
                                    Confirmar
                                </Button>
                            </div>
                        </div>
                    </Form>
                </div>

                <div
                    v-if="!showVerificationStep"
                    class="flex justify-end border-t border-slate-200/70 px-5 py-3.5 dark:border-darkmode-400"
                >
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                        @click="handleModalNextStep"
                    >
                        {{ modalConfig.buttonText }}
                    </Button>
                </div>
            </div>
        </Dialog.Panel>
    </Dialog>
</template>
