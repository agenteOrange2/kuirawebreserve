<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '@/components/Base/Button';
import {
    FormHelp,
    FormInput,
    FormLabel,
    FormTextarea,
} from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';
import { useToasts } from '@/composables/useToasts';
import RazeLayout from '@/layouts/RazeLayout.vue';

const props = defineProps<{
    settings: {
        app_name: string;
        login_title: string;
        login_subtitle: string;
        logo_url: string | null;
        favicon_url: string | null;
        login_background_url: string | null;
    };
}>();

const toast = useToasts();

const form = useForm({
    app_name: props.settings.app_name,
    login_title: props.settings.login_title,
    login_subtitle: props.settings.login_subtitle,
    logo: null as File | null,
    favicon: null as File | null,
    login_background: null as File | null,
    remove_logo: false,
    remove_favicon: false,
    remove_login_background: false,
});

// Previews locales: archivo recién elegido > actual guardado > nada.
const localUrl = (file: File | null) =>
    file ? URL.createObjectURL(file) : null;
const logoPreview = computed(() =>
    form.remove_logo ? null : (localUrl(form.logo) ?? props.settings.logo_url),
);
const faviconPreview = computed(() =>
    form.remove_favicon
        ? null
        : (localUrl(form.favicon) ?? props.settings.favicon_url),
);
const backgroundPreview = computed(() =>
    form.remove_login_background
        ? null
        : (localUrl(form.login_background) ??
          props.settings.login_background_url),
);

const logoInput = ref<HTMLInputElement | null>(null);
const faviconInput = ref<HTMLInputElement | null>(null);
const backgroundInput = ref<HTMLInputElement | null>(null);

function pickFile(
    field: 'logo' | 'favicon' | 'login_background',
    event: Event,
) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form[field] = file;
    if (file) form[`remove_${field}` as 'remove_logo'] = false;
}

function removeImage(field: 'logo' | 'favicon' | 'login_background') {
    form[field] = null;
    form[`remove_${field}` as 'remove_logo'] = true;
}

function submit() {
    form.post(route('admin.branding.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.favicon = null;
            form.login_background = null;
            form.remove_logo = false;
            form.remove_favicon = false;
            form.remove_login_background = false;
            toast.success(
                'Apariencia guardada',
                'El login y el favicon ya reflejan los cambios.',
            );
        },
        onError: () =>
            toast.error(
                'Revisa el formulario',
                Object.values(form.errors)[0] as string,
            ),
    });
}
</script>

<template>
    <RazeLayout title="Apariencia">
        <div
            class="mt-2 flex flex-col gap-y-3 md:h-10 md:flex-row md:items-center"
        >
            <div>
                <h1 class="text-lg font-medium group-[.mode--light]:text-white">
                    Apariencia de la plataforma
                </h1>
                <p class="text-sm text-slate-500">
                    Nombre, logo, favicon y el lado derecho del login — aplica
                    en el dominio central y en los de los hoteles
                </p>
            </div>
        </div>

        <form class="mt-5 grid grid-cols-12 gap-5" @submit.prevent="submit">
            <!-- Formulario -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="box box--stacked flex flex-1 flex-col gap-5 p-5">
                    <div
                        class="flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                    >
                        <Lucide icon="Palette" class="h-3.5 w-3.5" /> Identidad
                    </div>

                    <div>
                        <FormLabel htmlFor="brand-name"
                            >Nombre de la plataforma</FormLabel
                        >
                        <FormInput
                            id="brand-name"
                            v-model="form.app_name"
                            type="text"
                            maxlength="60"
                            placeholder="KuiraWebReserve"
                        />
                        <FormHelp
                            v-if="form.errors.app_name"
                            class="text-danger"
                            >{{ form.errors.app_name }}</FormHelp
                        >
                        <FormHelp v-else
                            >Se usa en el título de la pestaña, el login y el
                            panel.</FormHelp
                        >
                    </div>

                    <div class="grid grid-cols-12 gap-4">
                        <div class="col-span-12 sm:col-span-6">
                            <FormLabel>Logo</FormLabel>
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200/70 bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-700"
                                >
                                    <img
                                        v-if="logoPreview"
                                        :src="logoPreview"
                                        alt="Logo"
                                        class="max-h-full max-w-full object-contain"
                                    />
                                    <Lucide
                                        v-else
                                        icon="ImageOff"
                                        class="h-5 w-5 text-slate-300"
                                    />
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <Button
                                        type="button"
                                        variant="outline-secondary"
                                        size="sm"
                                        class="rounded-[0.5rem] bg-white"
                                        @click="logoInput?.click()"
                                    >
                                        <Lucide
                                            icon="Upload"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Subir
                                    </Button>
                                    <button
                                        v-if="logoPreview"
                                        type="button"
                                        class="text-left text-xs text-danger"
                                        @click="removeImage('logo')"
                                    >
                                        Quitar
                                    </button>
                                </div>
                                <input
                                    ref="logoInput"
                                    type="file"
                                    accept=".png,.jpg,.jpeg,.svg,.webp"
                                    class="hidden"
                                    @change="pickFile('logo', $event)"
                                />
                            </div>
                            <FormHelp
                                v-if="form.errors.logo"
                                class="text-danger"
                                >{{ form.errors.logo }}</FormHelp
                            >
                            <FormHelp v-else>PNG/SVG/WebP, máx. 2 MB.</FormHelp>
                        </div>
                        <div class="col-span-12 sm:col-span-6">
                            <FormLabel>Favicon</FormLabel>
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200/70 bg-slate-50 dark:border-darkmode-400 dark:bg-darkmode-700"
                                >
                                    <img
                                        v-if="faviconPreview"
                                        :src="faviconPreview"
                                        alt="Favicon"
                                        class="h-8 w-8 object-contain"
                                    />
                                    <Lucide
                                        v-else
                                        icon="ImageOff"
                                        class="h-5 w-5 text-slate-300"
                                    />
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <Button
                                        type="button"
                                        variant="outline-secondary"
                                        size="sm"
                                        class="rounded-[0.5rem] bg-white"
                                        @click="faviconInput?.click()"
                                    >
                                        <Lucide
                                            icon="Upload"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Subir
                                    </Button>
                                    <button
                                        v-if="faviconPreview"
                                        type="button"
                                        class="text-left text-xs text-danger"
                                        @click="removeImage('favicon')"
                                    >
                                        Quitar
                                    </button>
                                </div>
                                <input
                                    ref="faviconInput"
                                    type="file"
                                    accept=".ico,.png,.svg"
                                    class="hidden"
                                    @change="pickFile('favicon', $event)"
                                />
                            </div>
                            <FormHelp
                                v-if="form.errors.favicon"
                                class="text-danger"
                                >{{ form.errors.favicon }}</FormHelp
                            >
                            <FormHelp v-else
                                >ICO/PNG/SVG cuadrado, máx. 512 KB.</FormHelp
                            >
                        </div>
                    </div>

                    <div
                        class="border-t border-dashed border-slate-300/70 pt-5 dark:border-darkmode-400"
                    >
                        <div
                            class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                        >
                            <Lucide
                                icon="MonitorSmartphone"
                                class="h-3.5 w-3.5"
                            />
                            Lado derecho del login
                        </div>

                        <div class="flex flex-col gap-4">
                            <div>
                                <FormLabel htmlFor="brand-title"
                                    >Título</FormLabel
                                >
                                <FormTextarea
                                    id="brand-title"
                                    v-model="form.login_title"
                                    rows="2"
                                    maxlength="120"
                                    placeholder="Tu hotel, en un solo lugar"
                                />
                                <FormHelp
                                    v-if="form.errors.login_title"
                                    class="text-danger"
                                    >{{ form.errors.login_title }}</FormHelp
                                >
                                <FormHelp v-else
                                    >Los saltos de línea se respetan.</FormHelp
                                >
                            </div>
                            <div>
                                <FormLabel htmlFor="brand-subtitle"
                                    >Texto de apoyo</FormLabel
                                >
                                <FormTextarea
                                    id="brand-subtitle"
                                    v-model="form.login_subtitle"
                                    rows="3"
                                    maxlength="300"
                                    placeholder="Reservas, atención por WhatsApp y cobros de tu hotel en un solo lugar."
                                />
                                <FormHelp
                                    v-if="form.errors.login_subtitle"
                                    class="text-danger"
                                    >{{ form.errors.login_subtitle }}</FormHelp
                                >
                            </div>
                            <div>
                                <FormLabel>Imagen de fondo</FormLabel>
                                <div class="flex items-center gap-3">
                                    <Button
                                        type="button"
                                        variant="outline-secondary"
                                        size="sm"
                                        class="rounded-[0.5rem] bg-white"
                                        @click="backgroundInput?.click()"
                                    >
                                        <Lucide
                                            icon="Upload"
                                            class="mr-1.5 h-3.5 w-3.5"
                                        />
                                        Subir imagen
                                    </Button>
                                    <button
                                        v-if="backgroundPreview"
                                        type="button"
                                        class="text-xs text-danger"
                                        @click="removeImage('login_background')"
                                    >
                                        Quitar
                                    </button>
                                    <input
                                        ref="backgroundInput"
                                        type="file"
                                        accept=".png,.jpg,.jpeg,.webp"
                                        class="hidden"
                                        @change="
                                            pickFile('login_background', $event)
                                        "
                                    />
                                </div>
                                <FormHelp
                                    v-if="form.errors.login_background"
                                    class="text-danger"
                                    >{{
                                        form.errors.login_background
                                    }}</FormHelp
                                >
                                <FormHelp v-else
                                    >JPG/PNG/WebP, máx. 4 MB. Se aplica un velo
                                    con los colores del theme para que el texto
                                    siga legible; sin imagen queda el degradado
                                    actual.</FormHelp
                                >
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-auto flex justify-end border-t border-dashed border-slate-300/70 pt-4 dark:border-darkmode-400"
                    >
                        <Button
                            type="submit"
                            variant="primary"
                            class="rounded-[0.5rem] shadow-md shadow-primary/20"
                            :disabled="form.processing"
                        >
                            <Lucide icon="Check" class="mr-2 h-4 w-4" />
                            {{
                                form.processing
                                    ? 'Guardando…'
                                    : 'Guardar apariencia'
                            }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Vista previa del login -->
            <div class="col-span-12 flex flex-col xl:col-span-6">
                <div class="box box--stacked flex flex-1 flex-col p-5">
                    <div
                        class="mb-4 flex items-center gap-2 text-xs font-medium tracking-wide text-slate-400 uppercase"
                    >
                        <Lucide icon="Eye" class="h-3.5 w-3.5" /> Vista previa
                        del login
                    </div>
                    <div
                        class="relative flex-1 overflow-hidden rounded-xl border border-slate-200/70 dark:border-darkmode-400"
                        style="min-height: 420px"
                    >
                        <div class="absolute inset-0 grid grid-cols-12">
                            <!-- Izquierda: formulario -->
                            <div
                                class="col-span-5 flex flex-col justify-center gap-3 bg-white p-6 dark:bg-darkmode-600"
                            >
                                <img
                                    v-if="logoPreview"
                                    :src="logoPreview"
                                    alt="Logo"
                                    class="max-h-10 w-fit max-w-[140px] object-contain"
                                />
                                <div
                                    v-else
                                    class="flex h-10 w-10 items-center justify-center rounded-lg bg-linear-to-b from-theme-1/90 to-theme-2/90"
                                >
                                    <Lucide
                                        icon="Building2"
                                        class="h-5 w-5 text-white"
                                    />
                                </div>
                                <div class="text-sm font-medium">
                                    {{ form.app_name || 'KuiraReserve' }}
                                </div>
                                <div
                                    class="h-2 w-3/4 rounded bg-slate-100 dark:bg-darkmode-400"
                                ></div>
                                <div
                                    class="h-8 rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                                ></div>
                                <div
                                    class="h-8 rounded-lg border border-slate-200/70 dark:border-darkmode-400"
                                ></div>
                                <div
                                    class="h-8 rounded-full bg-linear-to-r from-theme-1/70 to-theme-2/70"
                                ></div>
                            </div>
                            <!-- Derecha: fondo + textos -->
                            <div class="relative col-span-7 overflow-hidden">
                                <img
                                    v-if="backgroundPreview"
                                    :src="backgroundPreview"
                                    alt=""
                                    class="absolute inset-0 h-full w-full object-cover"
                                />
                                <div
                                    class="absolute inset-0"
                                    :class="
                                        backgroundPreview
                                            ? 'bg-linear-to-b from-theme-1/80 to-theme-2/80'
                                            : 'bg-linear-to-b from-theme-1 to-theme-2'
                                    "
                                ></div>
                                <div
                                    class="relative z-10 flex h-full flex-col justify-center p-8"
                                >
                                    <div
                                        class="text-2xl leading-snug font-medium whitespace-pre-line text-white"
                                    >
                                        {{
                                            form.login_title ||
                                            form.app_name ||
                                            'KuiraReserve'
                                        }}
                                    </div>
                                    <div
                                        class="mt-3 max-w-sm text-sm text-white/70"
                                    >
                                        {{
                                            form.login_subtitle ||
                                            'Reservas, atención por WhatsApp y cobros de tu hotel en un solo lugar.'
                                        }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <FormHelp class="mt-3"
                        >El login real puede variar según el tamaño de pantalla;
                        esta es una guía proporcional.</FormHelp
                    >
                </div>
            </div>
        </form>
    </RazeLayout>
</template>
