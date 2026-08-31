<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormSelect,
    FormSwitch,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: {
        direct_notify_channel: 'auto' | 'meta' | 'evolution';
        arrival_reminder_enabled: boolean;
        arrival_soon_enabled: boolean;
        arrival_soon_hours: number;
        post_stay_thanks_enabled: boolean;
        post_stay_survey_enabled: boolean;
        review_url: string;
    };
    notifyChannels: { meta_whatsapp: boolean; evolution: boolean };
}>();

const toast = useToasts();

const saving = ref(false);
const errors = reactive<Record<string, string>>({});

const form = reactive({
    direct_notify_channel: props.settings.direct_notify_channel,
    arrival_reminder_enabled: props.settings.arrival_reminder_enabled,
    arrival_soon_enabled: props.settings.arrival_soon_enabled,
    arrival_soon_hours: props.settings.arrival_soon_hours,
    post_stay_thanks_enabled: props.settings.post_stay_thanks_enabled,
    post_stay_survey_enabled: props.settings.post_stay_survey_enabled,
    review_url: props.settings.review_url,
});

// Aviso si el canal elegido no está conectado (el envío caería en silencio).
const notifyChannelWarning = computed(() => {
    if (
        form.direct_notify_channel === 'meta' &&
        !props.notifyChannels.meta_whatsapp
    ) {
        return 'No tienes un canal de WhatsApp por Meta conectado: los avisos directos no saldrán hasta conectarlo en Asistente IA.';
    }
    if (
        form.direct_notify_channel === 'evolution' &&
        !props.notifyChannels.evolution
    ) {
        return 'No tienes una instancia de WhatsApp Evolution conectada: los avisos directos no saldrán hasta conectarla en Asistente IA.';
    }
    if (
        form.direct_notify_channel === 'auto' &&
        !props.notifyChannels.meta_whatsapp &&
        !props.notifyChannels.evolution
    ) {
        return 'No tienes ningún canal de WhatsApp conectado: los avisos directos solo saldrán por correo.';
    }
    return null;
});

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: {
                direct_notify_channel: form.direct_notify_channel,
                arrival_reminder_enabled: form.arrival_reminder_enabled,
                arrival_soon_enabled: form.arrival_soon_enabled,
                arrival_soon_hours: form.arrival_soon_hours,
                post_stay_thanks_enabled: form.post_stay_thanks_enabled,
                post_stay_survey_enabled: form.post_stay_survey_enabled,
                // 'url' de Laravel rechaza cadena vacía: sin link va null.
                review_url: form.review_url.trim() || null,
            },
        });
        toast.success('Guardado', 'Los avisos al huésped se actualizaron.');
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
</script>

<template>
    <RazeLayout title="Avisos al huésped">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="BellRing" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">Avisos al huésped</h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Mensajes automáticos que el hotel manda al huésped:
                            recordatorios de llegada y agradecimiento al salir,
                            y por qué canal salen.
                        </p>
                    </div>
                </div>
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

            <form class="mt-4 grid grid-cols-12 gap-5" @submit.prevent="submit">
                <!-- Canal de avisos directos (huésped sin conversación: wizard web) -->
                <div class="col-span-12">
                    <div class="box box--stacked p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="MessageCircle" class="h-3.5 w-3.5" />
                            Canal de envío
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Por dónde salen los avisos cuando el huésped no
                            tiene una conversación abierta (reservó por el
                            wizard web). Si ya hay conversación, el aviso sale
                            por ahí.
                        </p>
                        <div
                            class="flex flex-wrap items-start justify-between gap-4"
                        >
                            <div class="text-sm">
                                <div class="text-sm font-medium">
                                    Canal para avisos directos
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Con "Automático" se intenta la API oficial
                                    de Meta y, si no puede entregar, sale por
                                    Evolution.
                                </p>
                            </div>
                            <FormSelect
                                v-model="form.direct_notify_channel"
                                class="!w-64 shrink-0"
                            >
                                <option value="auto">
                                    Automático (Meta y respaldo Evolution)
                                </option>
                                <option value="meta">
                                    Solo Meta (API oficial de WhatsApp)
                                </option>
                                <option value="evolution">
                                    Solo Evolution
                                </option>
                            </FormSelect>
                        </div>
                        <p
                            v-if="notifyChannelWarning"
                            class="mt-2 flex items-start gap-1.5 text-xs text-warning"
                        >
                            <Lucide
                                icon="TriangleAlert"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            {{ notifyChannelWarning }}
                        </p>
                        <p
                            v-else-if="form.direct_notify_channel === 'meta'"
                            class="mt-2 flex items-start gap-1.5 text-xs text-slate-500"
                        >
                            <Lucide
                                icon="Info"
                                class="mt-0.5 h-3.5 w-3.5 shrink-0"
                            />
                            La API oficial solo entrega mensajes libres dentro
                            de las 24 horas después de que el huésped escribe;
                            para huéspedes que nunca han escrito puede requerir
                            una plantilla aprobada por Meta.
                        </p>
                    </div>
                </div>

                <!-- Antes de la llegada -->
                <div class="col-span-12">
                    <div class="box box--stacked p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="CalendarClock" class="h-3.5 w-3.5" />
                            Antes de la llegada
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Recordatorios automáticos para que la reserva no se
                            le pase al huésped.
                        </p>

                        <div
                            class="flex items-start justify-between gap-4 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="text-sm">
                                <div class="text-sm font-medium">
                                    Recordatorio de llegada
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    Un aviso automático al huésped 24 horas
                                    antes de su llegada, con su código y
                                    horario.
                                </p>
                            </div>
                            <FormSwitch class="mt-1">
                                <FormSwitch.Input
                                    :checked="form.arrival_reminder_enabled"
                                    type="checkbox"
                                    @change="
                                        form.arrival_reminder_enabled =
                                            !form.arrival_reminder_enabled
                                    "
                                />
                            </FormSwitch>
                        </div>

                        <div
                            class="mt-3 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="text-sm">
                                    <div class="text-sm font-medium">
                                        Aviso el día de la llegada
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Un segundo aviso cuando la entrada ya
                                        está a unas horas: su habitación lo
                                        espera hoy, con su código y horario.
                                    </p>
                                </div>
                                <FormSwitch class="mt-1">
                                    <FormSwitch.Input
                                        :checked="form.arrival_soon_enabled"
                                        type="checkbox"
                                        @change="
                                            form.arrival_soon_enabled =
                                                !form.arrival_soon_enabled
                                        "
                                    />
                                </FormSwitch>
                            </div>
                            <div
                                v-if="form.arrival_soon_enabled"
                                class="mt-3 flex flex-wrap items-center gap-2 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400"
                            >
                                <span class="text-xs text-slate-500"
                                    >Mandarlo cuando falten</span
                                >
                                <FormInput
                                    v-model.number="form.arrival_soon_hours"
                                    type="number"
                                    min="1"
                                    max="24"
                                    class="!w-24 text-center"
                                />
                                <span class="text-xs text-slate-500"
                                    >horas para la llegada</span
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Después de la estancia -->
                <div class="col-span-12">
                    <div class="box box--stacked p-4">
                        <div
                            class="mb-1 flex items-center gap-2 text-[11px] font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide icon="Heart" class="h-3.5 w-3.5" />
                            Después de la estancia
                        </div>
                        <p class="mb-4 text-xs text-slate-500">
                            Cierre de la visita: agradecer, pedir opinión e
                            invitar a dejar una reseña.
                        </p>

                        <div
                            class="rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-4 py-3 dark:border-darkmode-400 dark:bg-darkmode-700"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="text-sm">
                                    <div class="text-sm font-medium">
                                        Agradecimiento al salir
                                    </div>
                                    <p class="mt-0.5 text-xs text-slate-500">
                                        Se envía al completar la estancia
                                        (check-out manual o automático): un
                                        mensaje agradeciendo la visita, con el
                                        link de reseñas si lo capturas abajo.
                                    </p>
                                </div>
                                <FormSwitch class="mt-1">
                                    <FormSwitch.Input
                                        :checked="form.post_stay_thanks_enabled"
                                        type="checkbox"
                                        @change="
                                            form.post_stay_thanks_enabled =
                                                !form.post_stay_thanks_enabled
                                        "
                                    />
                                </FormSwitch>
                            </div>
                            <div
                                v-if="form.post_stay_thanks_enabled"
                                class="mt-3 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400"
                            >
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div class="text-sm">
                                        <div class="text-sm font-medium">
                                            Cuestionario de experiencia
                                        </div>
                                        <p
                                            class="mt-0.5 text-xs text-slate-500"
                                        >
                                            El agradecimiento incluye un link
                                            para calificar la estancia (1 a 5)
                                            con comentario. Las respuestas se
                                            ven en Encuestas.
                                        </p>
                                    </div>
                                    <FormSwitch class="mt-1">
                                        <FormSwitch.Input
                                            :checked="
                                                form.post_stay_survey_enabled
                                            "
                                            type="checkbox"
                                            @change="
                                                form.post_stay_survey_enabled =
                                                    !form.post_stay_survey_enabled
                                            "
                                        />
                                    </FormSwitch>
                                </div>
                            </div>
                            <div
                                v-if="form.post_stay_thanks_enabled"
                                class="mt-3 border-t border-dashed border-slate-300/70 pt-3 dark:border-darkmode-400"
                            >
                                <span class="text-xs text-slate-500"
                                    >Link de reseñas (Google, Tripadvisor...)
                                </span>
                                <FormInput
                                    v-model="form.review_url"
                                    type="url"
                                    placeholder="https://g.page/r/tu-hotel/review"
                                    class="mt-1.5"
                                />
                                <p
                                    v-if="errors.review_url"
                                    class="mt-1 text-xs text-danger"
                                >
                                    {{ errors.review_url }}
                                </p>
                                <FormHelp v-else>
                                    Sin link, el mensaje solo agradece la
                                    estancia e invita a volver.
                                </FormHelp>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-span-12 flex justify-end">
                    <Button
                        type="submit"
                        variant="primary"
                        class="rounded-[0.5rem] shadow-md shadow-primary/20"
                        :disabled="saving"
                    >
                        <Lucide icon="Check" class="mr-2 h-4 w-4" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </form>
        </div>
    </RazeLayout>
</template>
