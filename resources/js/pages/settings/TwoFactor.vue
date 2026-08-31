<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import Lucide from '@/components/Base/Lucide';
import SettingsNav from '@/components/SettingsNav.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData } = useTwoFactorAuth();

const steps = [
    {
        title: 'Instala una app',
        detail: 'Google Authenticator, 1Password o Authy en tu teléfono.',
    },
    {
        title: 'Escanea el código',
        detail: 'Te mostramos un QR; la app empieza a generar códigos.',
    },
    {
        title: 'Guarda los de respaldo',
        detail: 'Te sacan del apuro si pierdes el teléfono.',
    },
];
const showSetupModal = ref<boolean>(false);

const sectionIcon =
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-full border';
const cardHeader =
    'flex flex-wrap items-center gap-2.5 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400';
</script>

<template>
    <Head title="Verificación en dos pasos" />

    <RazeLayout title="Verificación en dos pasos">
        <div class="mt-2">
            <!-- Encabezado de página -->
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3.5">
                    <div
                        :class="[
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-full border',
                            twoFactorEnabled
                                ? 'border-success/10 bg-success/10 text-success'
                                : 'border-primary/10 bg-primary/10 text-primary',
                        ]"
                    >
                        <Lucide icon="ShieldCheck" class="h-5 w-5" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Verificación en dos pasos
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Un código de tu teléfono además de la contraseña,
                            cada vez que entras.
                        </p>
                    </div>
                </div>
                <span
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-medium',
                        twoFactorEnabled
                            ? 'bg-success/10 text-success'
                            : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400',
                    ]"
                >
                    <Lucide
                        :icon="twoFactorEnabled ? 'ShieldCheck' : 'ShieldOff'"
                        class="h-3.5 w-3.5"
                    />
                    {{ twoFactorEnabled ? 'Activada' : 'Desactivada' }}
                </span>
            </div>

            <div class="mt-4 flex flex-col gap-5 lg:flex-row">
                <SettingsNav />

                <div class="min-w-0 flex-1 space-y-4">
                    <div class="box box--stacked overflow-hidden">
                        <div :class="cardHeader">
                            <div
                                :class="[
                                    sectionIcon,
                                    twoFactorEnabled
                                        ? 'border-success/10 bg-success/10 text-success'
                                        : 'border-primary/10 bg-primary/10 text-primary',
                                ]"
                            >
                                <Lucide icon="Smartphone" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-medium">
                                    {{
                                        twoFactorEnabled
                                            ? 'Tu cuenta pide un código al entrar'
                                            : 'Agrega un segundo candado'
                                    }}
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{
                                        twoFactorEnabled
                                            ? 'El código sale de tu aplicación de autenticación y cambia cada minuto.'
                                            : 'Al activarla, además de la contraseña te pediremos un código de tu teléfono.'
                                    }}
                                </div>
                            </div>
                        </div>

                        <!-- Tres pasos: sin esto la pantalla era un botón
                             suelto y nadie sabía qué iba a pasar al tocarlo. -->
                        <div
                            v-if="!twoFactorEnabled"
                            class="grid grid-cols-12 gap-4 border-b border-slate-200/60 px-4 py-3 dark:border-darkmode-400"
                        >
                            <div
                                v-for="(step, index) in steps"
                                :key="step.title"
                                class="col-span-12 flex items-start gap-2.5 sm:col-span-4"
                            >
                                <span
                                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-medium text-primary"
                                >
                                    {{ index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <div class="text-xs font-medium">
                                        {{ step.title }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ step.detail }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="px-4 py-3">
                            <div v-if="!twoFactorEnabled">
                                <Button
                                    v-if="hasSetupData"
                                    variant="primary"
                                    class="h-9 rounded-[0.5rem] px-5 text-xs"
                                    @click="showSetupModal = true"
                                >
                                    <Lucide
                                        icon="ShieldCheck"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    Continuar configuración
                                </Button>
                                <Form
                                    v-else
                                    v-bind="enable.form()"
                                    @success="showSetupModal = true"
                                    #default="{ processing }"
                                >
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        class="h-9 rounded-[0.5rem] px-5 text-xs"
                                        :disabled="processing"
                                    >
                                        <Lucide
                                            icon="ShieldCheck"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        {{
                                            processing
                                                ? 'Activando…'
                                                : 'Activar'
                                        }}
                                    </Button>
                                </Form>
                            </div>

                            <Form
                                v-else
                                v-bind="disable.form()"
                                #default="{ processing }"
                            >
                                <Button
                                    type="submit"
                                    variant="outline-danger"
                                    class="h-9 rounded-[0.5rem] bg-white px-5 text-xs"
                                    :disabled="processing"
                                >
                                    <Lucide
                                        icon="ShieldBan"
                                        class="mr-1.5 h-3.5 w-3.5"
                                    />
                                    {{
                                        processing
                                            ? 'Desactivando…'
                                            : 'Desactivar'
                                    }}
                                </Button>
                            </Form>
                        </div>
                    </div>

                    <TwoFactorRecoveryCodes v-if="twoFactorEnabled" />
                </div>
            </div>
        </div>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </RazeLayout>
</template>
