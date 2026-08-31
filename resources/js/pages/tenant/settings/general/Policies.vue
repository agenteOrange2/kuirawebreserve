<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { reactive, ref } from 'vue';
import Button from '@/components/Base/Button';
import { FormHelp, FormTextarea } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    property: { id: number; name: string };
    settings: { policies: string };
}>();

const toast = useToasts();
const saving = ref(false);
const errors = reactive<Record<string, string>>({});
const policies = ref(props.settings.policies);

async function submit() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    try {
        // El PATCH hace merge en el backend: mandar solo este campo no pisa
        // el resto de los ajustes del hotel.
        await axios.patch(`/api/properties/${props.property.id}`, {
            settings: { policies: policies.value || null },
        });
        toast.success('Guardado', 'Las políticas quedaron actualizadas.');
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
    <RazeLayout title="Políticas del hotel">
        <div class="mt-2">
            <div
                class="box box--stacked flex flex-col gap-3 p-4 sm:p-5 md:flex-row md:items-center md:justify-between"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-primary/10 bg-primary/10 text-primary"
                    >
                        <Lucide icon="ScrollText" class="h-4 w-4" />
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-medium">
                            Políticas del hotel
                        </h1>
                        <p class="mt-0.5 text-xs text-slate-500">
                            Escríbelas en lenguaje natural: mascotas,
                            estacionamiento, visitas, cancelaciones, niños... El
                            asistente responderá usando exactamente lo que
                            pongas aquí.
                        </p>
                    </div>
                </div>
                <div
                    class="flex w-full flex-wrap items-center gap-2 md:w-auto md:shrink-0 md:justify-end"
                >
                    <!-- El volver vive con las acciones, no flotando
                         encima de la tarjeta. -->
                    <Link
                        :href="route('tenant.general-settings')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3.5 text-xs font-medium whitespace-nowrap text-slate-500 shadow-sm transition hover:border-primary/30 hover:text-primary dark:border-darkmode-400 dark:bg-darkmode-600"
                    >
                        <Lucide icon="ArrowLeft" class="h-3.5 w-3.5" />
                        Datos generales
                    </Link>
                </div>
            </div>

            <div class="box box--stacked mt-4 p-4">
                <FormTextarea
                    v-model="policies"
                    rows="14"
                    placeholder="Ej.
— No se permiten mascotas, excepto perros de asistencia.
— El estacionamiento es gratuito para huéspedes (1 auto por habitación).
— Cancelaciones sin costo hasta 24 h antes de la llegada."
                />
                <FormHelp v-if="errors.policies" class="text-danger">{{
                    errors.policies
                }}</FormHelp>

                <div
                    class="mt-3 flex items-start gap-2 rounded-lg border border-dashed border-slate-300/70 bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:border-darkmode-400 dark:bg-darkmode-700"
                >
                    <Lucide
                        icon="Bot"
                        class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                    />
                    <span>
                        Estas políticas, junto con los horarios y las tarifas,
                        son la única fuente de verdad del asistente. Si algo no
                        está escrito aquí, dirá que no tiene esa información en
                        vez de inventarla.
                    </span>
                </div>

                <div class="mt-5 flex justify-end">
                    <Button
                        variant="primary"
                        class="h-9 rounded-[0.5rem] text-xs shadow-md shadow-primary/20"
                        :disabled="saving"
                        @click="submit"
                    >
                        <Lucide icon="Check" class="mr-1.5 h-3.5 w-3.5" />
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </div>
        </div>
    </RazeLayout>
</template>
