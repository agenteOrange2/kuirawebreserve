<script setup lang="ts">
import { useForm, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/Base/Button';
import { FormCheck, FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

// Branding de plataforma (se edita en /admin/apariencia).
const page = usePage();
const branding = computed(
    () =>
        (page.props.branding ?? {}) as {
            app_name?: string | null;
            logo_url?: string | null;
            login_title?: string | null;
            login_subtitle?: string | null;
            login_background_url?: string | null;
        },
);

const appName = computed(() => branding.value.app_name || 'KuiraReserve');
const loginTitle = computed(() => branding.value.login_title || appName.value);
const loginSubtitle = computed(
    () =>
        branding.value.login_subtitle ||
        'Reservas, atención por WhatsApp y cobros de tu hotel en un solo lugar.',
);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <Head title="Iniciar sesión" />
    <div
        class="container grid grid-cols-12 px-5 py-10 sm:px-10 sm:py-14 md:px-36 lg:h-screen lg:max-w-[1550px] lg:py-0 lg:pr-12 lg:pl-14 xl:px-24 2xl:max-w-[1750px]"
    >
        <div
            :class="[
                'relative z-50 col-span-12 h-full rounded-2xl bg-white p-7 sm:p-14 lg:col-span-5 lg:bg-transparent lg:p-0 lg:pr-10 xl:pr-24 2xl:col-span-4',
                'before:absolute before:inset-0 before:mx-5 before:-mb-3.5 before:rounded-2xl before:bg-white/40 before:content-[\'\']',
            ]"
        >
            <div
                class="relative z-10 flex h-full w-full flex-col justify-center py-2 lg:py-32"
            >
                <div
                    v-if="branding.logo_url"
                    class="flex h-[55px] items-center"
                >
                    <img
                        :src="branding.logo_url"
                        :alt="appName"
                        class="max-h-[55px] max-w-[200px] object-contain"
                    />
                </div>
                <div
                    v-else
                    class="flex h-[55px] w-[55px] items-center justify-center rounded-[0.8rem] border border-primary/30"
                >
                    <div
                        class="relative flex h-[50px] w-[50px] items-center justify-center rounded-[0.6rem] bg-white bg-linear-to-b from-theme-1/90 to-theme-2/90"
                    >
                        <Lucide icon="Building2" class="h-8 w-8 text-white" />
                    </div>
                </div>
                <div class="mt-10">
                    <div class="text-2xl font-medium">{{ appName }}</div>
                    <div class="mt-2.5 text-slate-600">
                        Ingresa tus credenciales para acceder
                    </div>

                    <div
                        v-if="status"
                        class="mt-4 rounded-lg border border-success/30 bg-success/10 p-3 text-sm text-success"
                    >
                        {{ status }}
                    </div>

                    <div
                        v-if="form.errors.email || form.errors.password"
                        class="mt-4 rounded-lg border border-danger/30 bg-danger/10 p-3 text-sm text-danger"
                    >
                        <p v-if="form.errors.email">{{ form.errors.email }}</p>
                        <p v-if="form.errors.password">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="mt-6">
                        <FormLabel>Correo electrónico</FormLabel>
                        <FormInput
                            v-model="form.email"
                            type="email"
                            class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                            placeholder="correo@ejemplo.com"
                            required
                        />
                        <FormLabel class="mt-4">Contraseña</FormLabel>
                        <FormInput
                            v-model="form.password"
                            type="password"
                            class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                            placeholder="************"
                            required
                        />
                        <div
                            class="mt-4 flex items-center justify-between text-xs text-slate-500 sm:text-sm"
                        >
                            <label
                                class="flex cursor-pointer items-center select-none"
                            >
                                <FormCheck.Input
                                    id="remember-me"
                                    v-model="form.remember"
                                    type="checkbox"
                                    class="mr-2 border"
                                />
                                Recordarme
                            </label>
                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-primary"
                            >
                                ¿Olvidaste tu contraseña?
                            </Link>
                        </div>
                        <div class="mt-5 text-center xl:mt-8 xl:text-left">
                            <Button
                                type="submit"
                                variant="primary"
                                rounded
                                class="w-full bg-linear-to-r from-theme-1/70 to-theme-2/70 py-3.5"
                                :disabled="form.processing"
                            >
                                <Lucide
                                    v-if="form.processing"
                                    icon="Loader"
                                    class="mr-2 h-5 w-5 animate-spin"
                                />
                                {{
                                    form.processing
                                        ? 'Ingresando...'
                                        : 'Iniciar sesión'
                                }}
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div
        class="fixed inset-0 container grid h-screen w-screen grid-cols-12 pr-12 pl-14 lg:max-w-[1550px] xl:px-24 2xl:max-w-[1750px]"
    >
        <div
            :class="[
                'relative z-20 col-span-12 h-screen lg:col-span-5 2xl:col-span-4',
                'after:absolute after:inset-y-0 after:right-0 after:hidden after:w-[800%] after:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0] after:bg-white after:bg-linear-to-b after:from-white after:to-slate-100/80 after:content-[\'\'] after:lg:block',
                'before:absolute before:inset-y-0 before:right-0 before:my-6 before:-mr-4 before:hidden before:w-[800%] before:rounded-[0_1.2rem_1.2rem_0/0_1.7rem_1.7rem_0] before:bg-white/50 before:bg-linear-to-b before:from-white/10 before:to-slate-50/10 before:content-[\'\'] before:lg:block',
            ]"
        ></div>
        <div
            :class="[
                'col-span-7 h-full lg:relative 2xl:col-span-8',
                'before:absolute before:inset-y-0 before:left-0 before:w-screen before:bg-linear-to-b before:from-theme-1 before:to-theme-2 before:content-[\'\'] before:lg:-ml-10 before:lg:w-[800%]',
                'after:absolute after:inset-y-0 after:left-0 after:w-screen after:bg-texture-white after:bg-fixed after:bg-center after:bg-no-repeat after:content-[\'\'] after:lg:w-[800%] after:lg:bg-[25rem_-25rem]',
            ]"
        >
            <!-- Fondo configurable (anclado al viewport: el panel blanco lo tapa a
           la izquierda y a la derecha cubre hasta el borde de la pantalla) -->
            <template v-if="branding.login_background_url">
                <img
                    :src="branding.login_background_url"
                    alt=""
                    class="fixed inset-0 h-full w-full object-cover"
                />
                <div
                    class="fixed inset-0 bg-linear-to-b from-theme-1/80 to-theme-2/80"
                ></div>
            </template>

            <div
                class="sticky top-0 z-10 ml-16 hidden h-screen flex-col justify-center lg:flex xl:ml-28 2xl:ml-36"
            >
                <div
                    class="text-[2.6rem] leading-[1.4] font-medium whitespace-pre-line text-white xl:text-5xl xl:leading-[1.2]"
                >
                    {{ loginTitle }}
                </div>
                <div
                    class="mt-5 max-w-xl text-base leading-relaxed text-white/70 xl:text-lg"
                >
                    {{ loginSubtitle }}
                </div>
            </div>
        </div>
    </div>
</template>
