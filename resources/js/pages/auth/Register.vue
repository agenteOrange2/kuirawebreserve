<script setup lang="ts">
import { useForm, Head, Link } from '@inertiajs/vue3';
import Button from '@/components/Base/Button';
import { FormInput, FormLabel } from '@/components/Base/Form';
import Lucide from '@/components/Base/Lucide';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <Head title="Registro" />
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
                    class="flex h-[55px] w-[55px] items-center justify-center rounded-[0.8rem] border border-primary/30"
                >
                    <div
                        class="relative flex h-[50px] w-[50px] items-center justify-center rounded-[0.6rem] bg-white bg-linear-to-b from-theme-1/90 to-theme-2/90"
                    >
                        <Lucide icon="Truck" class="h-8 w-8 text-primary" />
                    </div>
                </div>
                <div class="mt-10">
                    <div class="text-2xl font-medium">Crear Cuenta</div>
                    <div class="mt-2.5 text-slate-600">
                        ¿Ya tienes cuenta?
                        <Link
                            :href="route('login')"
                            class="font-medium text-primary"
                            >Iniciar Sesión</Link
                        >
                    </div>

                    <div
                        v-if="Object.keys(form.errors).length"
                        class="mt-4 rounded-lg border border-red-400 bg-red-100 p-3 text-sm text-red-700"
                    >
                        <p v-for="(error, key) in form.errors" :key="key">
                            {{ error }}
                        </p>
                    </div>

                    <form class="mt-6" @submit.prevent="submit">
                        <FormLabel>Nombre*</FormLabel>
                        <FormInput
                            v-model="form.name"
                            type="text"
                            class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                            placeholder="Nombre completo"
                            required
                        />
                        <FormLabel class="mt-5">Email*</FormLabel>
                        <FormInput
                            v-model="form.email"
                            type="email"
                            class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                            placeholder="correo@ejemplo.com"
                            required
                        />
                        <FormLabel class="mt-5">Contraseña*</FormLabel>
                        <FormInput
                            v-model="form.password"
                            type="password"
                            class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                            placeholder="************"
                            required
                        />
                        <FormLabel class="mt-5"
                            >Confirmar Contraseña*</FormLabel
                        >
                        <FormInput
                            v-model="form.password_confirmation"
                            type="password"
                            class="block rounded-[0.6rem] border-slate-300/80 px-4 py-3.5"
                            placeholder="************"
                            required
                        />
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
                                        ? 'Registrando...'
                                        : 'Crear Cuenta'
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
            <div
                class="sticky top-0 z-10 ml-16 hidden h-screen flex-col justify-center lg:flex xl:ml-28 2xl:ml-36"
            >
                <div
                    class="text-[2.6rem] leading-[1.4] font-medium text-white xl:text-5xl xl:leading-[1.2]"
                >
                    Sistema de <br />
                    Transporte
                </div>
                <div
                    class="mt-5 text-base leading-relaxed text-white/70 xl:text-lg"
                >
                    Gestiona tus conductores, vehículos y viajes desde un solo
                    lugar.
                </div>
            </div>
        </div>
    </div>
</template>
