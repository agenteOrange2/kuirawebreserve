<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        email: string;
        smtp_host: string;
        smtp_port: number;
        smtp_username: string;
        smtp_from_address: string;
        smtp_from_name: string;
        has_smtp_password: boolean;
    };
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    smtp_host: props.settings.smtp_host,
    smtp_port: props.settings.smtp_port,
    smtp_username: props.settings.smtp_username,
    smtp_password: '',
    smtp_from_address: props.settings.smtp_from_address,
    smtp_from_name: props.settings.smtp_from_name,
});

// Estado "configurado": hay servidor guardado y una contraseña guardada.
// Se actualiza en local al guardar, sin recargar la página.
const savedHost = ref(props.settings.smtp_host);
const hasPassword = ref(props.settings.has_smtp_password);
const savedFrom = ref(props.settings.smtp_from_address);
const configured = computed(() => savedHost.value !== '' && hasPassword.value);

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                smtp_host: form.smtp_host || null,
                smtp_port: form.smtp_port,
                smtp_username: form.smtp_username || null,
                smtp_password: form.smtp_password, // vacía = conservar la guardada
                smtp_from_address: form.smtp_from_address || null,
                smtp_from_name: form.smtp_from_name || null,
            },
        });
        if (form.smtp_password.trim() !== '') hasPassword.value = true;
        savedHost.value = form.smtp_host;
        savedFrom.value = form.smtp_from_address;
        form.smtp_password = '';
        toast.success(
            'Correo guardado',
            'La configuración de correo saliente se actualizó.',
        );
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(
                ([key, msgs]) =>
                    (errors[key.replace('settings.', '')] = (
                        msgs as string[]
                    )[0]),
            );
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

// Prueba de correo: usa la config GUARDADA (guarda primero si editaste).
const smtpTestTo = ref(props.settings.email);
const smtpTesting = ref(false);

async function testSmtp() {
    smtpTesting.value = true;
    try {
        const { data } = await axios.post('/api/smtp-test', {
            to: smtpTestTo.value,
        });
        toast.success('Prueba enviada', data.message);
    } catch (e: any) {
        toast.error(
            'La prueba falló',
            e.response?.data?.message ??
                'Revisa el servidor y las credenciales.',
        );
    } finally {
        smtpTesting.value = false;
    }
}

const providerGuides = [
    {
        name: 'Zoho Mail',
        detail: 'Servidor smtp.zoho.com, puerto 587. Usuario y remitente: tu correo completo.',
    },
    {
        name: 'Gmail / Google Workspace',
        detail: 'Servidor smtp.gmail.com, puerto 587. Requiere una "contraseña de aplicación", no la contraseña normal de la cuenta.',
    },
    {
        name: 'Hosting propio (cPanel)',
        detail: 'Servidor mail.tudominio.com, puerto 587 o 465. Usuario: el buzón que creaste en el panel del hosting.',
    },
];
</script>

<template>
    <RazeLayout title="Correo saliente">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="Mail" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Correo saliente</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            El SMTP propio del hotel: con él las confirmaciones
                            y avisos salen por correo a nombre de tu hotel; sin
                            configurar, solo salen por WhatsApp.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando encima
                         de la tarjeta. -->
                    <Link
                        :href="route('tenant.hotel-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Volver a Ajustes
                    </Link>
                </div>
            </div>

            <!-- Estado: si el hotel ya puede mandar correos y con qué remitente -->
            <div class="box box--stacked mt-4 p-4">
                <div
                    class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                >
                    <Lucide icon="Mail" class="h-3.5 w-3.5" /> Estado de tu
                    correo
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[11px] font-medium"
                        :class="
                            configured
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="configured ? 'CircleCheck' : 'CircleX'"
                            class="h-3.5 w-3.5"
                        />
                        {{
                            configured
                                ? `SMTP configurado (${savedHost})`
                                : 'Sin SMTP configurado'
                        }}
                    </span>
                    <span
                        class="flex h-7 items-center gap-1.5 rounded-full px-2.5 text-[11px] font-medium"
                        :class="
                            savedFrom
                                ? 'bg-success/10 text-success'
                                : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'
                        "
                    >
                        <Lucide
                            :icon="savedFrom ? 'CircleCheck' : 'CircleX'"
                            class="h-3.5 w-3.5"
                        />
                        {{
                            savedFrom
                                ? `Remitente: ${savedFrom}`
                                : 'Sin remitente definido'
                        }}
                    </span>
                </div>
                <p v-if="!configured" class="mt-2.5 text-[11px] text-slate-500">
                    Mientras no haya SMTP, los huéspedes no reciben
                    confirmaciones por correo: solo por WhatsApp.
                </p>
            </div>

            <div class="mt-4 grid grid-cols-12 items-stretch gap-5">
                <!-- Servidor SMTP -->
                <div class="col-span-12 xl:col-span-7">
                    <form
                        class="box box--stacked flex h-full flex-col p-4"
                        @submit.prevent="submit"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Server" class="h-3.5 w-3.5" />
                            Servidor SMTP
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-xs"
                                    >Servidor SMTP</label
                                >
                                <FormInput
                                    v-model="form.smtp_host"
                                    type="text"
                                    placeholder="smtp.zoho.com"
                                    class="h-9 text-xs"
                                />
                                <FormHelp
                                    v-if="errors.smtp_host"
                                    class="text-danger"
                                    >{{ errors.smtp_host }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-xs">Puerto</label>
                                <FormInput
                                    v-model.number="form.smtp_port"
                                    type="number"
                                    min="1"
                                    max="65535"
                                    placeholder="587"
                                    class="h-9 text-xs"
                                />
                                <FormHelp
                                    v-if="errors.smtp_port"
                                    class="text-danger"
                                    >{{ errors.smtp_port }}</FormHelp
                                >
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Usuario</label
                                >
                                <FormInput
                                    v-model="form.smtp_username"
                                    type="text"
                                    placeholder="avisos@tuhotel.com"
                                    autocomplete="off"
                                    class="h-9 text-xs"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Contraseña</label
                                >
                                <FormInput
                                    v-model="form.smtp_password"
                                    type="password"
                                    autocomplete="new-password"
                                    :placeholder="
                                        hasPassword
                                            ? 'Guardada — escribe para reemplazar'
                                            : 'Contraseña o app password'
                                    "
                                    class="h-9 text-xs"
                                />
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Remitente (correo)</label
                                >
                                <FormInput
                                    v-model="form.smtp_from_address"
                                    type="email"
                                    placeholder="avisos@tuhotel.com"
                                    class="h-9 text-xs"
                                />
                                <FormHelp
                                    v-if="errors.smtp_from_address"
                                    class="text-danger"
                                    >{{ errors.smtp_from_address }}</FormHelp
                                >
                            </div>
                            <div>
                                <label class="mb-1 block text-xs"
                                    >Remitente (nombre)</label
                                >
                                <FormInput
                                    v-model="form.smtp_from_name"
                                    type="text"
                                    :placeholder="property.name"
                                    class="h-9 text-xs"
                                />
                            </div>
                        </div>
                        <div class="mt-auto flex justify-end pt-5">
                            <Button
                                type="submit"
                                variant="primary"
                                class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                                :disabled="saving"
                            >
                                <Lucide
                                    icon="Check"
                                    class="mr-1.5 h-3.5 w-3.5"
                                />
                                {{ saving ? 'Guardando…' : 'Guardar correo' }}
                            </Button>
                        </div>
                    </form>
                </div>

                <div class="col-span-12 flex flex-col gap-6 xl:col-span-5">
                    <!-- Prueba de envío -->
                    <div class="box box--stacked p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Send" class="h-3.5 w-3.5" /> Enviar
                            prueba
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Manda un correo real con la configuración guardada.
                            Si acabas de editar algo, primero "Guardar correo".
                        </p>
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <label class="mb-1 block text-xs"
                                    >Enviar prueba a</label
                                >
                                <FormInput
                                    v-model="smtpTestTo"
                                    type="email"
                                    placeholder="tu@correo.com"
                                    class="h-9 text-xs"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] bg-white text-xs"
                                :disabled="smtpTesting"
                                @click="testSmtp"
                            >
                                <Lucide
                                    icon="Send"
                                    class="mr-2 h-4 w-4 stroke-[1.3]"
                                />
                                {{ smtpTesting ? 'Enviando…' : 'Probar' }}
                            </Button>
                        </div>
                    </div>

                    <!-- Guías rápidas por proveedor -->
                    <div class="box box--stacked flex-1 p-4">
                        <div
                            class="mb-3 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Lightbulb" class="h-3.5 w-3.5" />
                            Guías rápidas
                        </div>
                        <div
                            class="divide-y divide-dashed divide-slate-200/80 dark:divide-darkmode-400"
                        >
                            <div
                                v-for="guide in providerGuides"
                                :key="guide.name"
                                class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide
                                        icon="KeyRound"
                                        class="h-3.5 w-3.5"
                                    />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        {{ guide.name }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-slate-400">
                                        {{ guide.detail }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
