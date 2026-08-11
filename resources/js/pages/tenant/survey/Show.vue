<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import type { WizardAppearance } from '@/composables/useWizardAppearance';
import { useWizardAppearance } from '@/composables/useWizardAppearance';

const props = defineProps<{
    token: string | null;
    // Misma apariencia que el wizard (/reservas/ajustes): una sola
    // configuración para todas las páginas públicas.
    appearance: WizardAppearance;
    property: {
        name: string;
        logo_url: string | null;
    };
    // Aspectos personalizados del hotel (/ajustes/encuestas).
    aspects: Array<{ key: string; label: string }>;
    submitted: boolean;
    // QR de habitación sin estancia que evaluar: se muestra un aviso
    // amable en lugar del cuestionario.
    unavailable?: boolean;
    stay: {
        check_in: string | null;
        check_out: string | null;
    };
    review_url: string | null;
}>();

const { isDark, rootStyle } = useWizardAppearance(props.appearance);

const done = ref(props.submitted);
const sending = ref(false);
const error = ref<string | null>(null);

const form = reactive({
    rating: 0,
    comment: '',
});

// Una calificación por aspecto configurado (0 = sin responder).
const answers = reactive<Record<string, number>>(
    Object.fromEntries(props.aspects.map((a) => [a.key, 0])),
);

async function submit() {
    if (!form.rating) {
        error.value = 'Cuéntanos al menos la calificación general.';
        return;
    }
    sending.value = true;
    error.value = null;
    try {
        await axios.post(`/api/encuesta/${props.token}`, {
            rating: form.rating,
            answers: Object.fromEntries(
                Object.entries(answers).filter(([, value]) => value > 0),
            ),
            comment: form.comment.trim() || null,
        });
        done.value = true;
    } catch (e: any) {
        if (e.response?.status === 409) {
            done.value = true;
        } else {
            error.value =
                e.response?.data?.message ??
                'No se pudo enviar. Intenta de nuevo en un momento.';
        }
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <Head :title="`Tu opinión · ${property.name}`" />
    <div
        class="flex min-h-screen bg-linear-to-b from-theme-1 to-theme-2 px-3 py-8 sm:px-8"
        :style="rootStyle"
    >
        <div class="m-auto w-full max-w-xl">
            <div class="mb-5 flex items-center gap-3 px-1 text-white">
                <img
                    v-if="property.logo_url"
                    :src="property.logo_url"
                    :alt="`Logo de ${property.name}`"
                    class="h-11 w-11 shrink-0 rounded-full bg-white object-contain p-1"
                />
                <div
                    v-else
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10"
                >
                    <Lucide icon="Building2" class="h-5 w-5" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-lg font-medium">
                        {{ property.name }}
                    </div>
                    <div class="text-xs text-white/70">
                        Cuéntanos cómo te fue
                    </div>
                </div>
            </div>

            <div
                class="overflow-hidden rounded-2xl bg-white shadow-2xl"
                :class="isDark && 'booking-dark'"
            >
                <!-- QR de habitación sin estancia que evaluar -->
                <div v-if="unavailable" class="p-6 text-center sm:p-8">
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400"
                    >
                        <Lucide icon="Hourglass" class="h-7 w-7" />
                    </div>
                    <h1 class="mt-4 text-lg font-medium text-slate-800">
                        Aún no hay una estancia que evaluar
                    </h1>
                    <p class="mt-1.5 text-sm text-slate-500">
                        Vuelve a escanear el código durante tu estancia o
                        después de tu salida. Gracias por tu visita.
                    </p>
                </div>

                <!-- Ya respondida (o recién enviada) -->
                <div v-else-if="done" class="p-6 text-center sm:p-8">
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success"
                    >
                        <Lucide icon="CircleCheck" class="h-7 w-7" />
                    </div>
                    <h1 class="mt-4 text-lg font-medium text-slate-800">
                        Gracias por tu opinión
                    </h1>
                    <p class="mt-1.5 text-sm text-slate-500">
                        Nos ayuda a atenderte mejor en tu próxima visita.
                    </p>
                    <a
                        v-if="review_url"
                        :href="review_url"
                        target="_blank"
                        rel="noopener"
                        class="mt-5 inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-md shadow-primary/20 transition hover:opacity-90"
                    >
                        <Lucide icon="Star" class="h-4 w-4" />
                        ¿Nos dejas también una reseña pública?
                    </a>
                </div>

                <!-- Formulario -->
                <div v-else class="p-5 sm:p-7">
                    <h1 class="text-lg font-medium text-slate-800">
                        ¿Cómo fue tu estancia?
                    </h1>
                    <p class="mt-1 text-sm text-slate-500">
                        <template v-if="stay.check_in && stay.check_out">
                            Del {{ stay.check_in }} al {{ stay.check_out }}.
                        </template>
                        Es un minuto y nos ayuda muchísimo.
                    </p>

                    <!-- Calificación general -->
                    <div class="mt-6 text-center">
                        <div class="text-sm font-medium text-slate-700">
                            En general
                        </div>
                        <div class="mt-2 flex justify-center gap-1.5">
                            <button
                                v-for="n in 5"
                                :key="n"
                                type="button"
                                class="p-1 transition hover:scale-110"
                                :title="`${n} de 5`"
                                @click="form.rating = n"
                            >
                                <Lucide
                                    icon="Star"
                                    class="h-8 w-8"
                                    :class="
                                        n <= form.rating
                                            ? 'fill-current text-warning'
                                            : 'text-slate-300'
                                    "
                                />
                            </button>
                        </div>
                    </div>

                    <!-- Aspectos (personalizados por el hotel) -->
                    <div
                        v-if="aspects.length"
                        class="mt-6 space-y-3 rounded-xl border border-slate-200 p-4"
                    >
                        <div
                            v-for="aspect in aspects"
                            :key="aspect.key"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="text-sm text-slate-600">{{
                                aspect.label
                            }}</span>
                            <div class="flex gap-0.5">
                                <button
                                    v-for="n in 5"
                                    :key="n"
                                    type="button"
                                    class="p-0.5"
                                    :title="`${n} de 5`"
                                    @click="answers[aspect.key] = n"
                                >
                                    <Lucide
                                        icon="Star"
                                        class="h-5 w-5"
                                        :class="
                                            n <= answers[aspect.key]
                                                ? 'fill-current text-warning'
                                                : 'text-slate-300'
                                        "
                                    />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Comentario -->
                    <div class="mt-5">
                        <label class="mb-1 block text-sm text-slate-600">
                            ¿Algo que quieras contarnos? (opcional)
                        </label>
                        <FormTextarea
                            v-model="form.comment"
                            rows="3"
                            maxlength="1000"
                            placeholder="Lo que más te gustó, lo que podemos mejorar…"
                        />
                    </div>

                    <p
                        v-if="error"
                        class="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger"
                    >
                        {{ error }}
                    </p>

                    <Button
                        variant="primary"
                        class="mt-5 w-full shadow-md shadow-primary/20"
                        :disabled="sending"
                        @click="submit"
                    >
                        <Lucide
                            :icon="sending ? 'RefreshCw' : 'Send'"
                            class="mr-2 h-4 w-4"
                            :class="sending && 'animate-spin'"
                        />
                        {{ sending ? 'Enviando…' : 'Enviar mi opinión' }}
                    </Button>
                </div>
            </div>
        </div>
    </div>
</template>
