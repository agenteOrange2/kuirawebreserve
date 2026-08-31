<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import Lucide from '@/components/Base/Lucide';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
const isRecoveryCodesVisible = ref<boolean>(false);
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <div class="box box--stacked overflow-hidden">
        <div
            class="flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
        >
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-warning/10 bg-warning/10 text-warning"
            >
                <Lucide icon="LockKeyhole" class="h-4 w-4" />
            </div>
            <div class="min-w-0">
                <div class="text-sm font-medium">Códigos de respaldo</div>
                <div class="text-xs text-slate-500">
                    Te dejan entrar si pierdes el teléfono. Guárdalos en un
                    lugar seguro.
                </div>
            </div>
            <div class="flex items-center gap-2 md:ml-auto">
                <Button
                    type="button"
                    variant="outline-secondary"
                    class="h-8 rounded-[0.5rem] bg-white text-xs"
                    @click="toggleRecoveryCodesVisibility"
                >
                    <Lucide
                        :icon="isRecoveryCodesVisible ? 'EyeOff' : 'Eye'"
                        class="mr-1.5 h-3.5 w-3.5"
                    />
                    {{ isRecoveryCodesVisible ? 'Ocultar' : 'Ver códigos' }}
                </Button>
                <Form
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    v-bind="regenerateRecoveryCodes.form()"
                    method="post"
                    :options="{ preserveScroll: true }"
                    @success="fetchRecoveryCodes"
                    #default="{ processing }"
                >
                    <Button
                        variant="secondary"
                        type="submit"
                        class="h-8 rounded-[0.5rem] text-xs"
                        :disabled="processing"
                    >
                        <Lucide icon="RefreshCw" class="mr-1.5 h-3.5 w-3.5" />
                        Generar nuevos
                    </Button>
                </Form>
            </div>
        </div>

        <div v-if="isRecoveryCodesVisible" class="px-4 py-3">
            <div
                v-if="errors?.length"
                class="rounded-lg border border-danger/20 bg-danger/5 px-3 py-2.5 text-xs text-danger"
            >
                <p v-for="(error, i) in errors" :key="i">{{ error }}</p>
            </div>
            <template v-else>
                <div
                    ref="recoveryCodeSectionRef"
                    class="grid gap-1 rounded-lg bg-slate-50 p-3 font-mono text-xs dark:bg-darkmode-400/40"
                >
                    <template v-if="!recoveryCodesList.length">
                        <div
                            v-for="n in 8"
                            :key="n"
                            class="h-3.5 animate-pulse rounded bg-slate-200/70 dark:bg-darkmode-400"
                        ></div>
                    </template>
                    <div
                        v-for="(code, index) in recoveryCodesList"
                        v-else
                        :key="index"
                    >
                        {{ code }}
                    </div>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">
                    Cada código sirve una sola vez y se borra al usarlo. Si te
                    quedas sin códigos, genera nuevos aquí arriba.
                </p>
            </template>
        </div>
    </div>
</template>
