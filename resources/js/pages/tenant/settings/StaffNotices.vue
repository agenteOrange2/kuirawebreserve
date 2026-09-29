<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormInput, FormSwitch } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

type EventKey =
    | 'reservation_new'
    | 'payment'
    | 'cancellation'
    | 'checkout'
    | 'survey';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        staff_notice_emails: string[];
        staff_notice_events: Record<EventKey, boolean>;
    };
    hasOwnSmtp: boolean;
}>();

const toast = useToasts();

const MAX_EMAILS = 10;

const saving = ref(false);
const testing = ref(false);
const errors = reactive<Record<string, string>>({});

const emails = ref<string[]>(
    props.settings.staff_notice_emails.length
        ? [...props.settings.staff_notice_emails]
        : [''],
);
const events = reactive<Record<EventKey, boolean>>({
    ...props.settings.staff_notice_events,
});

const eventList: { key: EventKey; icon: string; label: string; help: string }[] = [
    {
        key: 'reservation_new',
        icon: 'CalendarPlus',
        label: 'Reserva nueva',
        help: 'Código, huésped, teléfono, habitación, fechas, total, anticipo y el canal por el que entró (sitio web, asistente por WhatsApp, Messenger, mostrador...).',
    },
    {
        key: 'payment',
        icon: 'Banknote',
        label: 'Pago recibido',
        help: 'Anticipos y saldos: por pasarela, comprobante aprobado o capturado en mostrador, con lo pagado y lo que falta.',
    },
    {
        key: 'cancellation',
        icon: 'CalendarX',
        label: 'Cancelación',
        help: 'Cualquier reserva que se cancele, con el motivo (a mano, apartado vencido, saldo no cubierto) y si ya tenía dinero.',
    },
    {
        key: 'checkout',
        icon: 'DoorOpen',
        label: 'Salidas: revisar habitación',
        help: 'Un aviso por habitación a su hora de salida, para mandar a revisarla, con el saldo si quedó algo por cobrar.',
    },
    {
        key: 'survey',
        icon: 'MessageSquareHeart',
        label: 'Encuesta contestada',
        help: 'Cada respuesta del huésped con su calificación, los aspectos y el comentario. Las calificaciones bajas llegan marcadas para atenderlas rápido.',
    },
];

const cleanEmails = computed(() =>
    emails.value.map((e) => e.trim().toLowerCase()).filter((e) => e !== ''),
);

function addEmail() {
    if (emails.value.length < MAX_EMAILS) emails.value.push('');
}

function removeEmail(index: number) {
    emails.value.splice(index, 1);
    if (emails.value.length === 0) emails.value.push('');
}

function collectErrors(data: any) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.entries(data?.errors ?? {}).forEach(([key, msgs]) => {
        errors[key.replace('settings.staff_notice_', '')] = (msgs as string[])[0];
    });
}

function emailError(index: number): string | undefined {
    return errors[`emails.${index}`];
}

async function submit() {
    saving.value = true;
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                staff_notice_emails: cleanEmails.value,
                staff_notice_events: { ...events },
            },
        });
        Object.keys(errors).forEach((k) => delete errors[k]);
        toast.success('Guardado', 'Los avisos al hotel se actualizaron.');
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            collectErrors(data);
            toast.error('Revisa el formulario', Object.values(errors)[0]);
        } else {
            toast.error('Error', data?.message ?? 'No se pudo guardar.');
        }
    } finally {
        saving.value = false;
    }
}

async function sendTest() {
    if (cleanEmails.value.length === 0) {
        toast.error('Sin correos', 'Agrega al menos un correo para la prueba.');
        return;
    }

    testing.value = true;
    try {
        await axios.post('/ajustes/avisos-hotel/prueba', {
            emails: cleanEmails.value,
        });
        toast.success(
            'Correo de prueba enviado',
            `Revisa la bandeja de ${cleanEmails.value.join(', ')} (y la carpeta de spam).`,
        );
    } catch (e: any) {
        const data = e.response?.data;
        if (data?.errors) {
            Object.keys(errors).forEach((k) => delete errors[k]);
            Object.entries(data.errors).forEach(([key, msgs]) => {
                errors[key] = (msgs as string[])[0];
            });
        }
        toast.error(
            'No se pudo enviar la prueba',
            data?.message ?? 'Ocurrió un error.',
        );
    } finally {
        testing.value = false;
    }
}
</script>

<template>
    <RazeLayout title="Avisos al hotel">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="MailCheck" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Avisos al hotel</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Correos para el dueño y el equipo cuando entra una
                            reserva, llega un pago, se cancela o toca revisar
                            una habitación. También suenan en la campana del
                            panel.
                        </p>
                    </div>
                </div>
                <Link
                    :href="route('tenant.hotel-settings')"
                    class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                >
                    <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                    Volver a Ajustes
                </Link>
            </div>

            <form class="mt-4 grid grid-cols-12 gap-5" @submit.prevent="submit">
                <!-- A quién -->
                <div class="col-span-12">
                    <div class="box box--stacked p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Users" class="h-3.5 w-3.5" />
                            Quién los recibe
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Hasta {{ MAX_EMAILS }} correos. Sin ninguno, los
                            avisos solo quedan en la campana del panel.
                        </p>

                        <div class="space-y-2">
                            <div
                                v-for="(_, index) in emails"
                                :key="index"
                                class="flex items-start gap-2"
                            >
                                <div class="relative min-w-0 flex-1">
                                    <Lucide
                                        icon="Mail"
                                        class="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-slate-400"
                                    />
                                    <FormInput
                                        v-model="emails[index]"
                                        type="email"
                                        placeholder="dueno@mihotel.com"
                                        class="h-9 pl-9 text-xs"
                                    />
                                    <p
                                        v-if="emailError(index)"
                                        class="mt-1 text-xs text-danger"
                                    >
                                        {{ emailError(index) }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-danger/10 hover:text-danger"
                                    title="Quitar correo"
                                    @click="removeEmail(index)"
                                >
                                    <Lucide icon="Trash2" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>

                        <div
                            class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <Button
                                type="button"
                                variant="outline-secondary"
                                class="h-9 rounded-[0.5rem] px-4 text-xs"
                                :disabled="emails.length >= MAX_EMAILS"
                                @click="addEmail"
                            >
                                <Lucide icon="Plus" class="mr-1.5 h-3.5 w-3.5" />
                                Agregar correo
                            </Button>
                            <Button
                                type="button"
                                variant="outline-primary"
                                class="h-9 rounded-[0.5rem] px-4 text-xs"
                                :disabled="testing || cleanEmails.length === 0"
                                @click="sendTest"
                            >
                                <Lucide icon="Send" class="mr-1.5 h-3.5 w-3.5" />
                                {{ testing ? 'Enviando…' : 'Enviar correo de prueba' }}
                            </Button>
                        </div>

                        <FormHelp class="mt-3">
                            {{
                                hasOwnSmtp
                                    ? 'Salen por el correo propio del hotel (Ajustes → Correo saliente).'
                                    : 'El hotel no tiene correo propio configurado: salen por el correo de la plataforma. Se configura en Ajustes → Correo saliente.'
                            }}
                        </FormHelp>
                    </div>
                </div>

                <!-- De qué -->
                <div class="col-span-12">
                    <div class="box box--stacked p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="BellRing" class="h-3.5 w-3.5" />
                            De qué avisar
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Apaga lo que no quieras recibir por correo; la
                            campana del panel sigue avisando.
                        </p>

                        <div class="grid grid-cols-12 gap-3">
                            <div
                                v-for="event in eventList"
                                :key="event.key"
                                class="col-span-12 flex items-start gap-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                            >
                                <div
                                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                                >
                                    <Lucide :icon="event.icon as any" class="h-3.5 w-3.5" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium">
                                        {{ event.label }}
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        {{ event.help }}
                                    </p>
                                </div>
                                <FormSwitch class="shrink-0">
                                    <FormSwitch.Input
                                        v-model="events[event.key]"
                                        type="checkbox"
                                    />
                                </FormSwitch>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button
                        type="submit"
                        variant="primary"
                        class="h-9 rounded-[0.5rem] px-5 text-xs shadow-md shadow-primary/20"
                        :disabled="saving"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </form>
        </div>
    </RazeLayout>
</template>
