<script setup lang="ts">
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
        toast.success('Correo guardado', 'La configuración de correo saliente se actualizó.');
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.entries(data.errors).forEach(([key, msgs]) => (errors[key.replace('settings.', '')] = (msgs as string[])[0]));
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
        const { data } = await axios.post('/api/smtp-test', { to: smtpTestTo.value });
        toast.success('Prueba enviada', data.message);
    } catch (e: any) {
        toast.error('La prueba falló', e.response?.data?.message ?? 'Revisa el servidor y las credenciales.');
    } finally {
        smtpTesting.value = false;
    }
}

const providerGuides = [
    { name: 'Zoho Mail', detail: 'Servidor smtp.zoho.com, puerto 587. Usuario y remitente: tu correo completo.' },
    { name: 'Gmail / Google Workspace', detail: 'Servidor smtp.gmail.com, puerto 587. Requiere una "contraseña de aplicación", no la contraseña normal de la cuenta.' },
    { name: 'Hosting propio (cPanel)', detail: 'Servidor mail.tudominio.com, puerto 587 o 465. Usuario: el buzón que creaste en el panel del hosting.' },
];
</script>

<template>
    <RazeLayout title="Correo saliente">
        <div class="mt-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-medium">Correo saliente</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Área aislada: el SMTP propio del hotel. Con él, las confirmaciones y avisos al huésped salen por correo a
                        nombre de tu hotel; sin configurar, los avisos solo salen por WhatsApp.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button as="a" :href="route('tenant.hotel-settings')" variant="outline-secondary" class="rounded-[0.5rem] bg-white">
                        <Lucide icon="ArrowLeft" class="mr-2 h-4 w-4 stroke-[1.3]" /> Volver a Ajustes
                    </Button>
                </div>
            </div>

            <!-- Estado: si el hotel ya puede mandar correos y con qué remitente -->
            <div class="mt-5 box box--stacked p-5">
                <div class="mb-3 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                    <Lucide icon="Mail" class="h-3.5 w-3.5" /> Estado de tu correo
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium"
                        :class="configured ? 'bg-success/10 text-success' : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'"
                    >
                        <Lucide :icon="configured ? 'CircleCheck' : 'CircleX'" class="h-3.5 w-3.5" />
                        {{ configured ? `SMTP configurado (${savedHost})` : 'Sin SMTP configurado' }}
                    </span>
                    <span
                        class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-medium"
                        :class="savedFrom ? 'bg-success/10 text-success' : 'bg-slate-100 text-slate-500 dark:bg-darkmode-400'"
                    >
                        <Lucide :icon="savedFrom ? 'CircleCheck' : 'CircleX'" class="h-3.5 w-3.5" />
                        {{ savedFrom ? `Remitente: ${savedFrom}` : 'Sin remitente definido' }}
                    </span>
                </div>
                <p v-if="!configured" class="mt-3 text-xs text-slate-500">
                    Mientras no haya SMTP, los huéspedes no reciben confirmaciones por correo: solo por WhatsApp.
                </p>
            </div>

            <div class="mt-5 grid grid-cols-12 gap-6">
                <!-- Servidor SMTP -->
                <div class="col-span-12 xl:col-span-7">
                    <form class="box box--stacked flex h-full flex-col p-5" @submit.prevent="submit">
                        <div class="mb-4 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Server" class="h-3.5 w-3.5" /> Servidor SMTP
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label class="mb-1 block text-sm">Servidor SMTP</label>
                                <FormInput v-model="form.smtp_host" type="text" placeholder="smtp.zoho.com" />
                                <FormHelp v-if="errors.smtp_host" class="text-danger">{{ errors.smtp_host }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Puerto</label>
                                <FormInput v-model.number="form.smtp_port" type="number" min="1" max="65535" placeholder="587" />
                                <FormHelp v-if="errors.smtp_port" class="text-danger">{{ errors.smtp_port }}</FormHelp>
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm">Usuario</label>
                                <FormInput v-model="form.smtp_username" type="text" placeholder="avisos@tuhotel.com" autocomplete="off" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Contraseña</label>
                                <FormInput
                                    v-model="form.smtp_password"
                                    type="password"
                                    autocomplete="new-password"
                                    :placeholder="hasPassword ? 'Guardada — escribe para reemplazar' : 'Contraseña o app password'"
                                />
                            </div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-sm">Remitente (correo)</label>
                                <FormInput v-model="form.smtp_from_address" type="email" placeholder="avisos@tuhotel.com" />
                                <FormHelp v-if="errors.smtp_from_address" class="text-danger">{{ errors.smtp_from_address }}</FormHelp>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm">Remitente (nombre)</label>
                                <FormInput v-model="form.smtp_from_name" type="text" :placeholder="property.name" />
                            </div>
                        </div>
                        <div class="mt-auto flex justify-end pt-5">
                            <Button type="submit" variant="primary" class="rounded-[0.5rem] shadow-md shadow-primary/20" :disabled="saving">
                                <Lucide icon="Check" class="mr-2 h-4 w-4" /> {{ saving ? 'Guardando…' : 'Guardar correo' }}
                            </Button>
                        </div>
                    </form>
                </div>

                <div class="col-span-12 flex flex-col gap-6 xl:col-span-5">
                    <!-- Prueba de envío -->
                    <div class="box box--stacked p-5">
                        <div class="mb-1 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Send" class="h-3.5 w-3.5" /> Enviar prueba
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Manda un correo real con la configuración guardada. Si acabas de editar algo, primero "Guardar correo".
                        </p>
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <label class="mb-1 block text-sm">Enviar prueba a</label>
                                <FormInput v-model="smtpTestTo" type="email" placeholder="tu@correo.com" />
                            </div>
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="rounded-[0.5rem] bg-white"
                                :disabled="smtpTesting"
                                @click="testSmtp"
                            >
                                <Lucide icon="Send" class="mr-2 h-4 w-4 stroke-[1.3]" /> {{ smtpTesting ? 'Enviando…' : 'Probar' }}
                            </Button>
                        </div>
                    </div>

                    <!-- Guías rápidas por proveedor -->
                    <div class="box box--stacked flex-1 p-5">
                        <div class="mb-3 flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                            <Lucide icon="Lightbulb" class="h-3.5 w-3.5" /> Guías rápidas
                        </div>
                        <div class="divide-y divide-dashed divide-slate-200/80 dark:divide-darkmode-400">
                            <div v-for="guide in providerGuides" :key="guide.name" class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide icon="KeyRound" class="h-3.5 w-3.5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">{{ guide.name }}</div>
                                    <div class="mt-0.5 text-xs text-slate-400">{{ guide.detail }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
