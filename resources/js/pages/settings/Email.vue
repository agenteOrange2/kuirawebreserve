<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/components/Base/Button/Button.vue';
import { FormInput, FormLabel, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import SettingsNav from '@/components/SettingsNav.vue';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    settings: {
        mail_host: string;
        mail_port: string;
        mail_username: string;
        mail_from_address: string;
        mail_from_name: string;
        has_password: boolean;
    };
    autoEmailEnabled: boolean;
    configured: boolean;
    prospectsUrl: string;
}>();

const page = usePage();
const toasts = useToasts();
const testing = ref(false);

const form = useForm({
    mail_host: props.settings.mail_host,
    mail_port: props.settings.mail_port,
    mail_username: props.settings.mail_username,
    mail_password: '',
    mail_from_address: props.settings.mail_from_address,
    mail_from_name: props.settings.mail_from_name,
    prospects_auto_email: props.autoEmailEnabled,
});

function toastFlash(): void {
    const flash = page.props.flash as
        | { success?: string | null; error?: string | null }
        | undefined;
    if (flash?.success) {
        toasts.success(flash.success);
    } else if (flash?.error) {
        toasts.error(flash.error);
    }
}

function submit(): void {
    form.patch(route('admin.settings.email.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.mail_password = '';
            toastFlash();
        },
    });
}

function sendTest(): void {
    testing.value = true;
    router.post(
        route('admin.settings.email.test'),
        {},
        {
            preserveScroll: true,
            onSuccess: () => toastFlash(),
            onFinish: () => {
                testing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Correo" />

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
                    <SettingsNav />

                    <!-- Content -->
                    <div class="flex-1 space-y-6">
                        <div class="box box--stacked p-5">
                            <div class="mb-5 flex items-start justify-between gap-4">
                                <div>
                                    <h3 class="text-base font-medium">
                                        Correo de la plataforma
                                    </h3>
                                    <p class="mt-1 text-sm text-slate-500">
                                        Servidor SMTP con el que se envían los
                                        documentos a los prospectos del
                                        registro por QR.
                                    </p>
                                </div>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
                                    :class="
                                        configured
                                            ? 'bg-success/10 text-success'
                                            : 'bg-warning/10 text-warning'
                                    "
                                >
                                    <span
                                        class="h-1.5 w-1.5 rounded-full"
                                        :class="
                                            configured
                                                ? 'bg-success'
                                                : 'bg-warning'
                                        "
                                    />
                                    {{
                                        configured
                                            ? 'Configurado'
                                            : 'Sin configurar'
                                    }}
                                </span>
                            </div>

                            <form class="space-y-4" @submit.prevent="submit">
                                <div class="grid gap-4 sm:grid-cols-[1fr_140px]">
                                    <div>
                                        <FormLabel for="mail_host"
                                            >Servidor SMTP</FormLabel
                                        >
                                        <FormInput
                                            id="mail_host"
                                            v-model="form.mail_host"
                                            type="text"
                                            placeholder="smtp.tuproveedor.com"
                                            class="mt-1"
                                        />
                                        <div
                                            v-if="form.errors.mail_host"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ form.errors.mail_host }}
                                        </div>
                                    </div>
                                    <div>
                                        <FormLabel for="mail_port"
                                            >Puerto</FormLabel
                                        >
                                        <FormInput
                                            id="mail_port"
                                            v-model="form.mail_port"
                                            type="number"
                                            placeholder="587"
                                            class="mt-1"
                                        />
                                        <div
                                            v-if="form.errors.mail_port"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ form.errors.mail_port }}
                                        </div>
                                    </div>
                                </div>
                                <p class="text-xs text-slate-400">
                                    587 o 25 negocian el cifrado
                                    automáticamente; 465 usa TLS implícito.
                                </p>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <FormLabel for="mail_username"
                                            >Usuario</FormLabel
                                        >
                                        <FormInput
                                            id="mail_username"
                                            v-model="form.mail_username"
                                            type="text"
                                            autocomplete="off"
                                            placeholder="usuario@tuproveedor.com"
                                            class="mt-1"
                                        />
                                        <div
                                            v-if="form.errors.mail_username"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ form.errors.mail_username }}
                                        </div>
                                    </div>
                                    <div>
                                        <FormLabel for="mail_password"
                                            >Contraseña</FormLabel
                                        >
                                        <FormInput
                                            id="mail_password"
                                            v-model="form.mail_password"
                                            type="password"
                                            autocomplete="new-password"
                                            :placeholder="
                                                settings.has_password
                                                    ? 'Guardada (vacío = no cambiar)'
                                                    : 'Contraseña del SMTP'
                                            "
                                            class="mt-1"
                                        />
                                        <div
                                            v-if="form.errors.mail_password"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ form.errors.mail_password }}
                                        </div>
                                    </div>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <FormLabel for="mail_from_address"
                                            >Correo remitente</FormLabel
                                        >
                                        <FormInput
                                            id="mail_from_address"
                                            v-model="form.mail_from_address"
                                            type="email"
                                            placeholder="hola@kuirawebreserve.com"
                                            class="mt-1"
                                        />
                                        <div
                                            v-if="form.errors.mail_from_address"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ form.errors.mail_from_address }}
                                        </div>
                                    </div>
                                    <div>
                                        <FormLabel for="mail_from_name"
                                            >Nombre del remitente</FormLabel
                                        >
                                        <FormInput
                                            id="mail_from_name"
                                            v-model="form.mail_from_name"
                                            type="text"
                                            placeholder="KuiraWebReserve"
                                            class="mt-1"
                                        />
                                        <div
                                            v-if="form.errors.mail_from_name"
                                            class="mt-1 text-xs text-danger"
                                        >
                                            {{ form.errors.mail_from_name }}
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="flex items-start justify-between gap-4 rounded-xl border border-slate-200 p-4 dark:border-darkmode-400"
                                >
                                    <div>
                                        <div class="text-sm font-medium">
                                            Enviar documentos al registrarse
                                        </div>
                                        <p
                                            class="mt-1 text-xs text-slate-500"
                                        >
                                            Con esto encendido, cada prospecto
                                            del formulario QR recibe su correo
                                            al momento. Apagado, solo quedan
                                            los envíos manuales desde
                                            Prospectos.
                                        </p>
                                    </div>
                                    <FormSwitch>
                                        <FormSwitch.Input
                                            :checked="
                                                form.prospects_auto_email
                                            "
                                            type="checkbox"
                                            @change="
                                                form.prospects_auto_email =
                                                    !form.prospects_auto_email
                                            "
                                        />
                                    </FormSwitch>
                                </div>
                                <div
                                    v-if="form.errors.prospects_auto_email"
                                    class="text-xs text-danger"
                                >
                                    {{ form.errors.prospects_auto_email }}
                                </div>

                                <div
                                    class="flex flex-wrap items-center gap-3 pt-2"
                                >
                                    <Button
                                        variant="primary"
                                        type="submit"
                                        :disabled="form.processing"
                                    >
                                        <Lucide
                                            icon="Save"
                                            class="mr-2 h-4 w-4"
                                        />
                                        Guardar configuración
                                    </Button>
                                    <Button
                                        variant="outline-secondary"
                                        type="button"
                                        :disabled="testing || !configured"
                                        @click="sendTest"
                                    >
                                        <Lucide
                                            icon="Send"
                                            class="mr-2 h-4 w-4"
                                        />
                                        {{
                                            testing
                                                ? 'Enviando prueba...'
                                                : 'Enviar correo de prueba'
                                        }}
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
                                <p class="text-xs text-slate-400">
                                    La prueba se envía a tu propio correo.
                                    Guarda los cambios antes de probar.
                                </p>
                            </form>
                        </div>

                        <div class="box box--stacked p-5">
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="flex items-center gap-3">
                                    <span
                                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                        ><Lucide
                                            icon="ContactRound"
                                            class="h-5 w-5"
                                    /></span>
                                    <div>
                                        <div class="text-sm font-medium">
                                            Prospectos
                                        </div>
                                        <p class="text-xs text-slate-500">
                                            Los correos de documentos del
                                            registro por QR salen con esta
                                            configuración.
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    :as="Link"
                                    :href="prospectsUrl"
                                    variant="outline-secondary"
                                >
                                    Ir a prospectos
                                    <Lucide
                                        icon="ArrowRight"
                                        class="ml-2 h-4 w-4"
                                    />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
